<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Menus;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Menus\Concerns\EditsMenuTree;
use App\Modules\Cms\Exceptions\MenuChangeRejectedException;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\MenuItemTranslation;
use App\Modules\Cms\Navigation\MenuItemOptions;
use App\Modules\Cms\Navigation\MenuLimits;
use App\Modules\Cms\Navigation\MenuRules;
use App\Modules\Cms\Navigation\MenuTargets;
use App\Modules\Cms\Navigation\MenuVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Lưu": save everything the editor changed on the Trình đơn screen in one go — new items, edited
 * items (target, labels per language, display options, hidden or shown), the new order and nesting,
 * and items removed to the trash. One transaction with the menu row locked, so two editors never
 * interleave, and a version token (MenuVersion): a draft made from an older state of the menu is
 * refused whole (draft_stale), nothing half-saved.
 *
 * The draft (rows addressed by ulid, new rows by the screen's own key "new:…"; never a bigint id):
 *   version  the token the screen loaded;
 *   tree     every item that stays, in display order, as {key, parent} (parent = a key or null).
 *            Siblings are numbered in the order they come. It must name each live item not
 *            removed, and each new item, exactly once;
 *   creates  [{key: "new:…", link, labels?, options?}];
 *   updates  [{key: ulid, link?, labels?, options?}] — what is left out is kept;
 *   removes  [ulid…] — every item going to the trash, its sub-items included (they share one
 *            removal time, so "Khôi phục" brings the branch back together).
 *   link     {type: entry|taxonomy|product, ref: ulid} · {type: url, url} · {type: special, key};
 *   labels   {locale: text} ('' clears it: the website then shows the target's own name);
 *   options  MenuItemOptions' screen shape (replaces the item's display options).
 *
 * The rules are the write path's own: a target must be on this site (looked up by ulid through the
 * site scope, so another site's key is simply not found: target_invalid), a link must be safe
 * (MenuRules::safeUrl), labels at most 255 letters in an enabled language. Depth: the menu's limit
 * is the strictest area showing it (MenuLimits); an item may not be NEW at a level past that limit
 * (a new item, or one moved deeper than it was) — what was already deeper stays as it is.
 */
final readonly class ApplyMenuDraft
{
    use EditsMenuTree;

    private const int TEXT_MAX = 255;

    /** Rows a draft may name: far above any menu a visitor can use, low enough to bound one save. */
    public const int ITEMS_MAX = MenuRules::ITEMS_MAX;

    private const string NEW_KEY = '/\Anew:[A-Za-z0-9_-]{1,40}\z/';

    public function __construct(
        private SiteLocale $siteLocales,
        private SiteTime $siteTime,
        private MenuTargets $targets,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return array{version: string, keys: array<string, string>, removed: int}
     *
     * @throws AuthorizationException
     * @throws MenuChangeRejectedException
     */
    public function handle(User $actor, Menu $menu, array $draft): array
    {
        Gate::forUser($actor)->authorize('update', $menu);

        $version = is_string($draft['version'] ?? null) ? $draft['version'] : '';
        $tree = $this->treeOf($draft['tree'] ?? null);
        $creates = $this->entriesOf($draft['creates'] ?? [], true);
        $updates = $this->entriesOf($draft['updates'] ?? [], false);
        $removes = $this->removesOf($draft['removes'] ?? []);
        $locales = $this->siteLocales->enabled();

        return DB::transaction(function () use ($menu, $version, $tree, $creates, $updates, $removes, $locales): array {
            $this->lockMenu($menu->id);
            $menu->refresh();
            [$items, $labels] = $this->read($menu->id);
            if (! hash_equals(MenuVersion::of($menu, $items, $labels), $version)) {
                throw new MenuChangeRejectedException('draft_stale');
            }

            /** @var array<string, MenuItem> $live ulid → item */
            $live = [];
            foreach ($items as $item) {
                $live[(string) $item->ulid] = $item;
            }
            $this->checkKeys($tree, $live, $creates, $updates, $removes);
            $levels = $this->levels($tree);
            $limits = MenuLimits::forMenu($menu->id);
            $this->checkDepth($levels, $live, $limits['depth']);

            $targets = $this->targetsFor([...$creates, ...$updates]);
            $zone = $this->siteTime->zone();

            // To the trash first: one UPDATE, one removal time for every row.
            $removed = $removes === [] ? 0 : MenuItem::query()->where('menu_id', $menu->id)->whereIn('ulid', $removes)->delete();

            // New rows, parents before children, each at its place.
            $ids = [];   // key → id, live and new
            foreach ($live as $ulid => $item) {
                $ids[$ulid] = $item->id;
            }
            $place = $this->places($tree);
            $keys = [];
            foreach ($this->parentsFirst($tree) as $key) {
                if (! isset($creates[$key])) {
                    continue;
                }
                $entry = $creates[$key];
                $parent = $place[$key]['parent'];
                $item = new MenuItem([
                    'menu_id' => $menu->id,
                    'parent_id' => $parent === null ? null : $ids[$parent],
                    'position' => $place[$key]['position'],
                    ...$targets[$key],
                    'meta' => MenuItemOptions::normalize([], $this->optionsOf($entry), $locales, $parent === null, $limits['mega'], $zone),
                ]);
                $item->save();
                $ids[$key] = $item->id;
                $keys[$key] = (string) $item->ulid;
            }

            // Moved and renumbered rows: one statement for all of them.
            $moves = [];
            foreach ($live as $ulid => $item) {
                if (! isset($place[$ulid])) {
                    continue;
                }
                $parent = $place[$ulid]['parent'] === null ? null : $ids[$place[$ulid]['parent']];
                if ($item->parent_id !== $parent || $item->position !== $place[$ulid]['position']) {
                    $moves[] = [$item->id, $parent, $place[$ulid]['position']];
                }
            }
            $this->moveAll($menu, $moves);

            // Target and display options of edited rows; mega dropped where it can no longer open.
            foreach ($live as $ulid => $item) {
                if (! isset($place[$ulid])) {
                    continue;
                }
                $topLevel = $place[$ulid]['parent'] === null;
                $changes = [];
                if (isset($updates[$ulid]) && array_key_exists('options', $updates[$ulid])) {
                    $changes['meta'] = MenuItemOptions::normalize($item->meta ?? [], $this->optionsOf($updates[$ulid]), $locales, $topLevel, $limits['mega'], $zone);
                } else {
                    $placed = MenuItemOptions::placed($item->meta ?? [], $topLevel, $limits['mega']);
                    if ($placed !== ($item->meta ?? [])) {
                        $changes['meta'] = $placed;
                    }
                }
                if (isset($targets[$ulid])) {
                    $changes = [...$changes, ...$targets[$ulid]];
                }
                if ($changes !== []) {
                    MenuItem::query()->whereKey($item->id)->update(array_map(
                        fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value,
                        $changes,
                    ));
                }
            }

            $this->saveLabels($menu, [...$creates, ...$updates], $ids, $locales);

            Audit::record('cms.menu.draft_saved', [
                'auditable_type' => Menu::class,
                'auditable_id' => $menu->id,
                'meta' => ['handle' => $menu->handle, 'created' => count($creates), 'updated' => count($updates), 'moved' => count($moves), 'removed' => $removed],
            ]);

            [$items, $labels] = $this->read($menu->id);

            return ['version' => MenuVersion::of($menu, $items, $labels), 'keys' => $keys, 'removed' => Cast::int($removed)];
        });
    }

    /**
     * The menu's live items and their labels, as MenuVersion hashes them (created_at only tells the
     * screen whether a page moved after the item was added).
     *
     * @return array{0: Collection<int, MenuItem>, 1: Collection<int, MenuItemTranslation>}
     */
    public function read(int $menuId): array
    {
        $items = MenuItem::query()->where('menu_id', $menuId)
            ->orderBy('id')
            ->get(['id', 'ulid', 'menu_id', 'parent_id', 'position', 'link_type', 'routable_type', 'routable_id', 'url', 'dynamic_key', 'meta', 'created_at']);
        $labels = MenuItemTranslation::query()
            ->whereIn('menu_item_id', MenuItem::query()->select('id')->where('menu_id', $menuId))
            ->get(['menu_item_id', 'locale', 'label']);

        return [$items, $labels];
    }

    /**
     * @return list<array{key: string, parent: string|null}>
     */
    private function treeOf(mixed $tree): array
    {
        if (! is_array($tree) || ! array_is_list($tree)) {
            throw new MenuChangeRejectedException('item_invalid');
        }
        if (count($tree) > self::ITEMS_MAX) {
            throw new MenuChangeRejectedException('menu_full');
        }
        $out = [];
        foreach ($tree as $node) {
            $key = is_array($node) ? ($node['key'] ?? null) : null;
            $parent = is_array($node) ? ($node['parent'] ?? null) : null;
            if (! is_string($key) || ($parent !== null && ! is_string($parent))) {
                throw new MenuChangeRejectedException('item_invalid');
            }
            $out[] = ['key' => $key, 'parent' => $parent];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>> key → entry
     */
    private function entriesOf(mixed $entries, bool $new): array
    {
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) > self::ITEMS_MAX) {
            throw new MenuChangeRejectedException('item_invalid');
        }
        $out = [];
        foreach ($entries as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            if (! is_string($key) || isset($out[$key]) || (preg_match(self::NEW_KEY, $key) === 1) !== $new) {
                throw new MenuChangeRejectedException('item_invalid');
            }
            if ($new && ! is_array($entry['link'] ?? null)) {
                throw new MenuChangeRejectedException('target_invalid');
            }
            /** @var array<string, mixed> $entry */
            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function removesOf(mixed $removes): array
    {
        if (! is_array($removes) || ! array_is_list($removes) || count($removes) > self::ITEMS_MAX) {
            throw new MenuChangeRejectedException('item_invalid');
        }
        foreach ($removes as $ulid) {
            if (! is_string($ulid) || preg_match(self::NEW_KEY, $ulid) === 1) {
                throw new MenuChangeRejectedException('item_invalid');
            }
        }

        return array_values(array_unique($removes));
    }

    /**
     * Every live item is either kept (in the tree) or removed, every new item is in the tree, and
     * nothing else is: an item of another menu or another site, or one gone since, is refused.
     *
     * @param  list<array{key: string, parent: string|null}>  $tree
     * @param  array<string, MenuItem>  $live
     * @param  array<string, array<string, mixed>>  $creates
     * @param  array<string, array<string, mixed>>  $updates
     * @param  list<string>  $removes
     */
    private function checkKeys(array $tree, array $live, array $creates, array $updates, array $removes): void
    {
        $removed = array_flip($removes);
        foreach ($removes as $ulid) {
            if (! isset($live[$ulid])) {
                throw new MenuChangeRejectedException('item_invalid');
            }
        }
        $seen = [];
        foreach ($tree as $node) {
            $key = $node['key'];
            $known = isset($creates[$key]) || (isset($live[$key]) && ! isset($removed[$key]));
            if (! $known || isset($seen[$key])) {
                throw new MenuChangeRejectedException('item_invalid');
            }
            $seen[$key] = true;
        }
        if (count($seen) !== count($creates) + count($live) - count($removed)) {
            throw new MenuChangeRejectedException('item_invalid');
        }
        foreach ($tree as $node) {
            if ($node['parent'] !== null && (! isset($seen[$node['parent']]) || $node['parent'] === $node['key'])) {
                throw new MenuChangeRejectedException('parent_invalid');
            }
        }
        foreach (array_keys($updates) as $key) {
            if (! isset($seen[$key])) {
                throw new MenuChangeRejectedException('item_invalid');
            }
        }
    }

    /**
     * Each key's level in the final tree (1 = top). A parent chain that loops is refused.
     *
     * @param  list<array{key: string, parent: string|null}>  $tree
     * @return array<string, int>
     */
    private function levels(array $tree): array
    {
        $parents = array_column($tree, 'parent', 'key');
        $levels = [];
        foreach (array_keys($parents) as $key) {
            $key = (string) $key;
            $level = 1;
            $seen = [$key => true];
            for ($at = $parents[$key]; $at !== null; $at = $parents[$at]) {
                if (isset($seen[$at])) {
                    throw new MenuChangeRejectedException('parent_invalid');
                }
                $seen[$at] = true;
                $level++;
            }
            $levels[$key] = $level;
        }

        return $levels;
    }

    /**
     * @param  array<string, int>  $levels
     * @param  array<string, MenuItem>  $live
     */
    private function checkDepth(array $levels, array $live, int $limit): void
    {
        $before = [];
        $parents = [];
        foreach ($live as $ulid => $item) {
            $parents[$item->id] = $item->parent_id;
        }
        foreach ($live as $ulid => $item) {
            $level = 1;
            $seen = [];
            for ($at = $item->parent_id; $at !== null && array_key_exists($at, $parents) && ! isset($seen[$at]); $at = $parents[$at]) {
                $seen[$at] = true;
                $level++;
            }
            $before[$ulid] = $level;
        }
        foreach ($levels as $key => $level) {
            if ($level > $limit && $level > ($before[$key] ?? 0)) {
                throw new MenuChangeRejectedException('depth_exceeded');
            }
        }
    }

    /**
     * The target columns of every entry that sets a link, with the source rows looked up in one
     * query per kind, by ulid, through the site scope.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @return array<string, array{link_type: string, routable_type: string|null, routable_id: int|null, url: string|null, dynamic_key: string|null}>
     */
    private function targetsFor(array $entries): array
    {
        $none = ['routable_type' => null, 'routable_id' => null, 'url' => null, 'dynamic_key' => null];
        $kinds = ['entry', 'taxonomy', ...array_keys($this->targets->all())];
        $wanted = [];   // kind → [ulid → list<key>]
        $out = [];

        foreach ($entries as $key => $entry) {
            if (! array_key_exists('link', $entry)) {
                continue;
            }
            $link = $entry['link'];
            $type = is_array($link) ? ($link['type'] ?? null) : null;
            if ($type === 'url') {
                $url = MenuRules::safeUrl(is_string($link['url'] ?? null) ? $link['url'] : null);
                if ($url === null || mb_strlen($url) > self::TEXT_MAX) {
                    throw new MenuChangeRejectedException('url_invalid');
                }
                $out[$key] = ['link_type' => 'url', ...$none, 'url' => $url];
            } elseif ($type === 'special') {
                $special = $link['key'] ?? null;
                if (! is_string($special) || ! array_key_exists($special, MenuRules::DYNAMIC)) {
                    throw new MenuChangeRejectedException('target_invalid');
                }
                $out[$key] = ['link_type' => 'dynamic', ...$none, 'dynamic_key' => $special];
            } elseif (is_string($type) && in_array($type, $kinds, true) && is_string($link['ref'] ?? null) && $link['ref'] !== '') {
                $wanted[$type][$link['ref']][] = $key;
            } else {
                throw new MenuChangeRejectedException('target_invalid');
            }
        }

        foreach ($wanted as $type => $refs) {
            $class = Relation::getMorphedModel($type);
            if ($class === null) {
                throw new MenuChangeRejectedException('target_invalid');
            }
            $query = $class::query()->whereIn('ulid', array_map('strval', array_keys($refs)));
            if ($type === 'entry') {
                $query->whereIn('type', ['page', 'post']);
            }
            /** @var array<string, int> $found */
            $found = $query->pluck('id', 'ulid')->all();
            foreach ($refs as $ref => $keys) {
                if (! isset($found[$ref])) {
                    throw new MenuChangeRejectedException('target_invalid');
                }
                foreach ($keys as $key) {
                    $out[$key] = ['link_type' => $type, ...$none, 'routable_type' => $type, 'routable_id' => (int) $found[$ref]];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function optionsOf(array $entry): array
    {
        $options = $entry['options'] ?? [];
        if (! is_array($options)) {
            throw new MenuChangeRejectedException('option_invalid');
        }

        /** @var array<string, mixed> $options */
        return $options;
    }

    /**
     * @param  list<array{key: string, parent: string|null}>  $tree
     * @return array<string, array{parent: string|null, position: int}>
     */
    private function places(array $tree): array
    {
        $next = [];
        $place = [];
        foreach ($tree as $node) {
            $group = $node['parent'] ?? '';
            $next[$group] ??= 0;
            $place[$node['key']] = ['parent' => $node['parent'], 'position' => $next[$group]++];
        }

        return $place;
    }

    /**
     * @param  list<array{key: string, parent: string|null}>  $tree
     * @return list<string>
     */
    private function parentsFirst(array $tree): array
    {
        $children = [];
        foreach ($tree as $node) {
            $children[$node['parent'] ?? ''][] = $node['key'];
        }
        $order = [];
        $queue = $children[''] ?? [];
        while ($queue !== []) {
            $key = array_shift($queue);
            $order[] = $key;
            array_push($queue, ...($children[$key] ?? []));
        }

        return $order;
    }

    /**
     * @param  list<array{0: int, 1: int|null, 2: int}>  $moves  id, parent id, position
     */
    private function moveAll(Menu $menu, array $moves): void
    {
        foreach (array_chunk($moves, 500) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?::bigint, ?::bigint, ?::int)'));
            DB::update(
                "update menu_items set parent_id = v.parent_id, position = v.position, updated_at = now()
                 from (values {$values}) as v(id, parent_id, position)
                 where menu_items.id = v.id and menu_items.site_id = ? and menu_items.menu_id = ?",
                [...array_merge(...array_map(fn (array $move): array => [$move[0], $move[1], $move[2]], $chunk)), $menu->site_id, $menu->id],
            );
        }
    }

    /**
     * Labels per language: '' removes the row (the website then shows the target's own name).
     * One delete and one upsert for the whole draft.
     *
     * @param  array<string, array<string, mixed>>  $entries
     * @param  array<string, int>  $ids
     * @param  list<string>  $locales
     */
    private function saveLabels(Menu $menu, array $entries, array $ids, array $locales): void
    {
        $upserts = [];
        $clears = [];
        foreach ($entries as $key => $entry) {
            $labels = $entry['labels'] ?? [];
            if (! is_array($labels)) {
                throw new MenuChangeRejectedException('label_invalid');
            }
            foreach ($labels as $locale => $label) {
                if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                    throw new MenuChangeRejectedException('locale_invalid');
                }
                if (! is_string($label) || ! mb_check_encoding($label, 'UTF-8')) {
                    throw new MenuChangeRejectedException('label_invalid');
                }
                $label = (string) preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $label);
                if (mb_strlen($label) > self::TEXT_MAX || preg_match('/\p{Cc}/u', $label) === 1) {
                    throw new MenuChangeRejectedException('label_invalid');
                }
                if ($label === '') {
                    $clears[] = [$ids[$key], $locale];
                } else {
                    $upserts[] = ['site_id' => $menu->site_id, 'menu_item_id' => $ids[$key], 'locale' => $locale, 'label' => $label, 'created_at' => now(), 'updated_at' => now()];
                }
            }
        }

        if ($clears !== []) {
            MenuItemTranslation::query()->where(function ($query) use ($clears): void {
                foreach ($clears as [$id, $locale]) {
                    $query->orWhere(fn ($one) => $one->where('menu_item_id', $id)->where('locale', $locale));
                }
            })->delete();
        }
        if ($upserts !== []) {
            MenuItemTranslation::query()->upsert($upserts, ['menu_item_id', 'locale'], ['label', 'updated_at']);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Queries\EntryRevisions;
use App\Modules\Cms\Queries\PendingEntryDrafts;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/**
 * Computes the plan: for every pack item, its live record (found by the item's key, or by the
 * ledger's ulid when staff renamed it), the record's managed fields in canonical form (`current`),
 * what the pack would make of them (`desired`) and the verdict (spec §3.3). Read-only; runs inside
 * the site's tenant context, one lookup query per kind (batched by key), never per item. The same
 * readers produce the hash the ledger records after a write, so a record read back right after the
 * loader wrote it hashes equal to its plan's `desired`.
 *
 * Images (this build has no image step): a slot resolves only when its `legacy_key` names a media
 * row the site already has. A block image prop whose slot does not resolve is left out; a card
 * image, a product gallery or a setting whose slot does not resolve keeps its live value.
 */
final class Planner
{
    private const int FACET_BASE = 1000;

    private string $locale = '';

    /** @var list<string> */
    private array $locales = [];

    /** @var array<int, array{vocabulary: string, parent_id: int|null, position: int, list_template: string|null, tr: array<string, array{name: string, slug: string}>}> */
    private array $taxonomies = [];

    /** @var array<int, string> taxonomy id → its key (the pack item's when matched, else derived) */
    private array $taxonomyKeys = [];

    /** @var array<int, string> entry id → key */
    private array $entryKeys = [];

    /** @var array<int, string> product id → key */
    private array $productKeys = [];

    /** @var array<string, array{id: int, key: string}|null> */
    private array $slots = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> pack key → matched record id (taxonomies, entries, products, menus) */
    private array $matched = [];

    /** @var array<string, string> pack key → the earlier ledger key it carries on (a renamed or moved category) */
    private array $carried = [];

    public function __construct(
        private readonly CurrentSite $site,
        private readonly MediaUrlResolver $urls,
    ) {}

    /**
     * @param  list<string>  $forced  --force-item keys
     *
     * @throws PackRejected the pack's languages do not fit the site, or a forced key is not in the pack
     */
    public function plan(Pack $pack, string $siteUlid, string $siteName, string $host, array $forced = []): Plan
    {
        $this->reset();
        $this->languages($pack);

        foreach ($forced as $key) {
            if ($pack->item($key) === null) {
                throw new PackRejected("Refused: --force-item {$key} is not an item of the pack.");
            }
        }

        $ledger = Ledger::read($pack->code);
        $ledgerItems = $ledger['items'] ?? [];
        $this->resolveSlots($pack);
        $this->loadTaxonomies();

        $items = [];
        $context = new PlanContext($pack, $ledgerItems, $forced);
        foreach ($this->taxonomyItems($pack, $context) as $planned) {
            $items[] = $planned;
        }
        $entries = $this->entryItems($pack, $context);
        $products = $this->productItems($pack, $context);
        $menus = $this->menuItems($pack, $context);

        foreach ($pack->items as $item) {
            $planned = match ($item->kind) {
                'setting' => $this->settingItem($item, $context),
                'profile' => $this->profileItem($item, $context),
                'taxonomy' => null,
                'product' => $products[$item->key] ?? null,
                'post', 'page' => $entries[$item->key] ?? null,
                'menu' => $menus[$item->key] ?? null,
                'redirect' => $this->redirectItem($item, $context),
                default => null,
            };
            if ($planned !== null) {
                $items[] = $planned;
            }
        }
        usort($items, fn (PlanItem $a, PlanItem $b): int => array_search($a->item->kind, Pack::KINDS, true) <=> array_search($b->item->kind, Pack::KINDS, true));

        $this->gridWarnings($pack);
        $orphans = array_values(array_diff(array_keys($ledgerItems), array_map(fn (PackItem $item): string => $item->key, $pack->items), array_values($this->carried)));
        sort($orphans, SORT_STRING);

        $resolved = count(array_filter($this->slots, fn (?array $slot): bool => $slot !== null));

        return new Plan(
            pack: $pack,
            siteUlid: $siteUlid,
            siteName: $siteName,
            host: $host,
            items: $items,
            forced: $forced,
            orphans: $orphans,
            warnings: array_values(array_unique($this->warnings)),
            ledger: $ledger,
            media: ['slots' => count($pack->slots), 'resolved' => $resolved],
        );
    }

    /** The record a planned key was matched to (after the plan; internal to the loader). */
    public function matchedId(string $key): ?int
    {
        return $this->matched[$key] ?? null;
    }

    /** @return array{id: int, key: string}|null */
    public function slot(string $name): ?array
    {
        return $this->slots[$name] ?? null;
    }

    /** The live record id of a page, post, product or category key the pack does not hold. */
    public function liveTarget(string $key): ?int
    {
        $maps = match (true) {
            str_starts_with($key, 'tax:') => $this->taxonomyKeys,
            str_starts_with($key, 'product:') => $this->productKeys,
            default => $this->entryKeys,
        };
        $id = array_search($key, $maps, true);

        return is_int($id) ? $id : null;
    }

    public function defaultLocale(): string
    {
        return $this->locale;
    }

    private function reset(): void
    {
        $this->locale = '';
        $this->locales = [];
        $this->taxonomies = [];
        $this->taxonomyKeys = [];
        $this->entryKeys = [];
        $this->productKeys = [];
        $this->slots = [];
        $this->warnings = [];
        $this->matched = [];
        $this->carried = [];
    }

    private function languages(Pack $pack): void
    {
        $rows = SiteLocale::query()->where('enabled', true)->get(['locale', 'is_default']);
        $default = $rows->firstWhere('is_default', true);
        $enabled = $rows->pluck('locale')->map(fn (mixed $locale): string => Cast::string($locale))->all();

        if ($default === null || $default->locale !== $pack->defaultLocale) {
            throw new PackRejected("Refused: the pack's default language '{$pack->defaultLocale}' is not the site's default language.");
        }
        $missing = array_diff($pack->locales, $enabled);
        if ($missing !== []) {
            throw new PackRejected('Refused: the site has not enabled the language(s) '.implode(', ', $missing).' the pack uses.');
        }

        $this->locale = $pack->defaultLocale;
        $this->locales = $pack->locales;
    }

    // ---------------------------------------------------------------- images

    private function resolveSlots(Pack $pack): void
    {
        $keys = [];
        foreach ($pack->slots as $name => $slot) {
            $this->slots[$name] = null;
            $legacy = $slot['legacy_key'] ?? null;
            if (is_string($legacy) && $legacy !== '') {
                $keys[$name] = $legacy;
            }
        }
        if ($keys === []) {
            return;
        }

        $media = Media::query()->whereIn('storage_key', array_values($keys))->get(['id', 'storage_key'])->keyBy('storage_key');
        foreach ($keys as $name => $key) {
            $row = $media->get($key);
            if ($row instanceof Media) {
                $this->slots[$name] = ['id' => $row->id, 'key' => $row->storage_key];
            }
        }
    }

    /**
     * A body with every `{"media": slot}` replaced by the slot's media id — or left out when the
     * slot does not resolve (a list element is dropped, an object prop is removed).
     */
    private function resolveBody(mixed $value, string $at): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $list = array_is_list($value);
        $out = [];
        foreach ($value as $key => $child) {
            if (PackValidator::isMediaRef($child)) {
                $slot = $this->slots[$child['media']] ?? null;
                if ($slot === null) {
                    $this->warnings[] = "{$at}: image slot '{$child['media']}' has no file and no existing image; the image is left out.";

                    continue;
                }
                $out[$key] = $slot['id'];

                continue;
            }
            $out[$key] = $this->resolveBody($child, $at);
        }

        return $list ? array_values($out) : $out;
    }

    // ---------------------------------------------------------------- verdict

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $desired
     * @param  list<string>  $notes
     * @param  string|null  $evidence  why a record with no ledger entry counts as staff-edited
     */
    private function decide(
        PlanContext $context,
        PackItem $item,
        ?array $current,
        array $desired,
        ?string $ref,
        ?int $recordId,
        array $notes = [],
        ?string $evidence = null,
        bool $draftPending = false,
        ?string $conflict = null,
        ?int $trashedId = null,
    ): PlanItem {
        $ledger = $context->ledger[$this->carried[$item->key] ?? $item->key] ?? null;
        $forced = in_array($item->key, $context->forced, true);

        if ($conflict !== null) {
            return new PlanItem($item, PlanItem::CONFLICT, $current, $desired, $ref, $recordId, [$conflict]);
        }

        if ($current === null) {
            if ($ledger !== null && ! $forced) {
                return new PlanItem($item, PlanItem::SKIP_DELETED, null, $desired, $ref, $recordId, ['deleted by staff since the last load; --force-item to recreate']);
            }

            if ($forced && $trashedId !== null) {
                return new PlanItem($item, PlanItem::CREATE, null, $desired, $ref, $trashedId, [...$notes, 'restored from the trash'], true);
            }

            return new PlanItem($item, PlanItem::CREATE, null, $desired, $ref, null, $notes, $forced);
        }

        $currentHash = CanonicalHash::of($current);
        if ($currentHash === CanonicalHash::of($desired)) {
            return new PlanItem($item, PlanItem::UNCHANGED, $current, $desired, $ref, $recordId);
        }

        $skip = null;
        if ($draftPending) {
            $skip = [PlanItem::SKIP_DRAFT, 'a staff draft is waiting; overwriting would make it stale'];
        } elseif ($ledger !== null && $ledger['hash'] !== $currentHash) {
            $skip = [PlanItem::SKIP_EDITED, 'edited by staff since the last load'];
        } elseif ($ledger === null && $evidence !== null) {
            $skip = [PlanItem::SKIP_EDITED, $evidence];
        }

        if ($skip !== null) {
            if (! $forced) {
                return new PlanItem($item, $skip[0], $current, $desired, $ref, $recordId, [$skip[1].'; --force-item to overwrite']);
            }
            $notes[] = "forced over {$skip[0]}";
        }

        if (($current['status'] ?? null) === 'published' && ($desired['status'] ?? null) === 'draft') {
            $notes[] = 'unpublish'.(($item->data['verified'] ?? true) === false ? ' (unverified: '.self::quote(Cast::strings($item->data['claims'] ?? [])).')' : '');
        }

        $verdict = $ledger === null && ! $forced ? PlanItem::ADOPT : PlanItem::UPDATE;

        return new PlanItem($item, $verdict, $current, $desired, $ref, $recordId, $notes, $forced);
    }

    /** @param list<string> $claims */
    private static function quote(array $claims): string
    {
        $first = $claims[0] ?? '';

        return '"'.(mb_strlen($first) > 60 ? mb_substr($first, 0, 57).'…' : $first).'"'.(count($claims) > 1 ? ' …' : '');
    }

    // ---------------------------------------------------------------- taxonomies

    private function loadTaxonomies(): void
    {
        $rows = Taxonomy::query()->get(['id', 'vocabulary', 'parent_id', 'position', 'list_template']);
        $translations = TaxonomyTranslation::query()->whereIn('locale', $this->locales)->get(['taxonomy_id', 'locale', 'name', 'slug']);

        foreach ($rows as $row) {
            $this->taxonomies[$row->id] = [
                'vocabulary' => $row->vocabulary,
                'parent_id' => $row->parent_id,
                'position' => $row->position,
                'list_template' => $row->list_template,
                'tr' => [],
            ];
        }
        foreach ($translations as $translation) {
            if (isset($this->taxonomies[$translation->taxonomy_id])) {
                $this->taxonomies[$translation->taxonomy_id]['tr'][$translation->locale] = ['name' => $translation->name, 'slug' => $translation->slug];
            }
        }
    }

    /** The key a live category is known by when no pack item claims it: tax:<vocabulary>/<root slug>/…/<slug>. */
    private function derivedTaxonomyKey(int $id): string
    {
        $slugs = [];
        $seen = [];
        $cursor = $id;
        while ($cursor !== null && isset($this->taxonomies[$cursor]) && ! isset($seen[$cursor])) {
            $seen[$cursor] = true;
            array_unshift($slugs, $this->taxonomies[$cursor]['tr'][$this->locale]['slug'] ?? 'untitled');
            $cursor = $this->taxonomies[$cursor]['parent_id'];
        }

        return 'tax:'.($this->taxonomies[$id]['vocabulary'] ?? 'unknown').'/'.implode('/', $slugs);
    }

    private function taxonomyKey(int $id): string
    {
        return $this->taxonomyKeys[$id] ??= $this->derivedTaxonomyKey($id);
    }

    /** @return list<PlanItem> */
    private function taxonomyItems(Pack $pack, PlanContext $context): array
    {
        $items = $pack->ofKind('taxonomy');
        foreach ($items as $item) {
            $chain = PackValidator::taxonomyChain($item->key);
            if ($chain === null) {
                continue;
            }
            $parentKey = Cast::stringOrNull($item->data['parent'] ?? null);
            $parentId = $parentKey === null ? null : ($this->matched[$parentKey] ?? null);
            if ($parentKey !== null && $parentId === null) {
                continue;   // the parent is new, so is this one
            }
            $slug = $chain['slugs'][count($chain['slugs']) - 1];
            $names = [$slug, ...Cast::strings($item->data['was'] ?? [])];
            $found = null;
            foreach ($names as $name) {
                foreach ($this->taxonomies as $id => $row) {
                    if (! in_array($id, $this->matchedOf($pack->ofKind('taxonomy')), true) && $row['vocabulary'] === $chain['vocabulary'] && $row['parent_id'] === $parentId && ($row['tr'][$this->locale]['slug'] ?? null) === $name) {
                        $found = $id;
                        break 2;
                    }
                }
            }
            if ($found !== null) {
                $this->matched[$item->key] = $found;
                $this->taxonomyKeys[$found] = $item->key;
            }
        }

        $paths = [];
        foreach ($items as $item) {
            $paths[] = '/'.$this->taxonomySlug($item);
        }
        $routes = SiteRoute::query()->where('locale', $this->locale)->whereIn('path', $paths)->get(['path', 'entry_id', 'taxonomy_id', 'product_id'])->keyBy('path');
        $pagePaths = array_map(fn (PackItem $page): string => $page->anchor(), $pack->ofKind('page'));

        $planned = [];
        foreach ($items as $item) {
            $id = $this->matched[$item->key] ?? null;
            $current = $id === null ? null : $this->taxonomyState($id);
            $desired = $this->desiredTaxonomy($item, $current);
            $path = '/'.$this->taxonomySlug($item);

            $conflict = null;
            $route = $routes->get($path);
            if ($route instanceof SiteRoute && ($route->taxonomy_id === null || $route->taxonomy_id !== $id)) {
                $conflict = "the address {$path} already belongs to another page or item";
            } elseif (in_array($path, $pagePaths, true)) {
                $conflict = "the address {$path} is also a page of this pack";
            }

            $notes = [];
            $oldSlug = $current === null ? null : Cast::arr(Cast::arr($current['translations'])[$this->locale] ?? null)['slug'] ?? null;
            if (is_string($oldSlug) && $oldSlug !== $this->taxonomySlug($item)) {
                $count = DB::table('entry_taxonomies')->where('site_id', $this->site->id())->where('taxonomy_id', $id)->where('position', 0)->count();
                $notes[] = "URL /{$oldSlug} → {$path} (301 + {$count} posts)";
            }

            // A category whose key changed (renamed through `was`, or under a renamed parent) keeps
            // the ledger line of its earlier key, so a staff edit since the last load still counts.
            $ref = $current === null ? null : $this->derivedTaxonomyKey((int) $id);
            if ($ref !== null && ! isset($context->ledger[$item->key]) && isset($context->ledger[$ref]) && $pack->item($ref) === null) {
                $this->carried[$item->key] = $ref;
            }

            $planned[] = $this->decide($context, $item, $current, $desired, $ref, $id, $notes, conflict: $conflict);
        }

        return $planned;
    }

    /**
     * Record ids already claimed by these items (ids are only unique within one kind).
     *
     * @param  list<PackItem>  $items
     * @return list<int>
     */
    private function matchedOf(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if (isset($this->matched[$item->key])) {
                $ids[] = $this->matched[$item->key];
            }
        }

        return $ids;
    }

    private function taxonomySlug(PackItem $item): string
    {
        $chain = PackValidator::taxonomyChain($item->key);

        return $chain === null ? '' : $chain['slugs'][count($chain['slugs']) - 1];
    }

    /** @return array<string, mixed> */
    public function taxonomyState(int $id): array
    {
        $row = $this->taxonomies[$id];
        $translations = [];
        foreach ($this->locales as $locale) {
            $translations[$locale] = $row['tr'][$locale] ?? null;
        }

        return [
            'vocabulary' => $row['vocabulary'],
            'parent' => $row['parent_id'] === null ? null : $this->taxonomyKey($row['parent_id']),
            'position' => $row['position'],
            'list_template' => $row['list_template'],
            'translations' => $translations,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function desiredTaxonomy(PackItem $item, ?array $current): array
    {
        $chain = PackValidator::taxonomyChain($item->key);
        $names = Cast::arr($item->data['name'] ?? null);
        $slugs = Cast::arr($item->data['slug'] ?? null);
        $live = Cast::arr($current['translations'] ?? null);

        $translations = [];
        foreach ($this->locales as $locale) {
            $name = Cast::stringOrNull($names[$locale] ?? null);
            $translations[$locale] = $name === null
                ? ($live[$locale] ?? null)
                : ['name' => $name, 'slug' => $locale === $this->locale ? $this->taxonomySlug($item) : Cast::string($slugs[$locale] ?? null, $this->taxonomySlug($item))];
        }

        return [
            'vocabulary' => $chain['vocabulary'] ?? '',
            'parent' => Cast::stringOrNull($item->data['parent'] ?? null),
            'position' => array_key_exists('position', $item->data) ? Cast::int($item->data['position']) : Cast::int($current['position'] ?? null),
            'list_template' => array_key_exists('list_template', $item->data) ? Cast::stringOrNull($item->data['list_template']) : Cast::stringOrNull($current['list_template'] ?? null),
            'translations' => $translations,
        ];
    }

    // ---------------------------------------------------------------- entries

    /** @return array<string, PlanItem> */
    private function entryItems(Pack $pack, PlanContext $context): array
    {
        $pages = $pack->ofKind('page');
        $posts = $pack->ofKind('post');
        if ($pages === [] && $posts === []) {
            return [];
        }

        $conflicts = [];
        $trashed = [];
        $routes = SiteRoute::query()->where('locale', $this->locale)
            ->whereIn('path', array_map(fn (PackItem $item): string => $item->anchor(), $pages))
            ->get(['path', 'entry_id', 'taxonomy_id', 'product_id'])->keyBy('path');
        $routeEntries = Entry::withTrashed()->whereIn('id', $routes->pluck('entry_id')->filter()->all())->get(['id', 'ulid', 'type', 'deleted_at'])->keyBy('id');

        foreach ($pages as $item) {
            $route = $routes->get($item->anchor());
            if (! $route instanceof SiteRoute) {
                continue;
            }
            $entry = $route->entry_id === null ? null : $routeEntries->get($route->entry_id);
            if (! $entry instanceof Entry || $entry->type !== 'page') {
                $conflicts[$item->key] = "the address {$item->anchor()} already belongs to something that is not a page";
            } elseif ($entry->trashed()) {
                if (($context->ledger[$item->key]['ref'] ?? null) === $entry->ulid) {
                    $trashed[$item->key] = $entry->id;
                } else {
                    $conflicts[$item->key] = "the address {$item->anchor()} belongs to a page in the trash";
                }
            } else {
                $this->matched[$item->key] = $entry->id;
            }
        }

        $slugs = array_map(fn (PackItem $item): string => $item->anchor(), $posts);
        $bySlug = EntryTranslation::query()->where('locale', $this->locale)->whereIn('slug', $slugs)
            ->whereIn('entry_id', Entry::query()->select('id')->where('type', 'post'))
            ->orderBy('entry_id')->get(['entry_id', 'slug']);
        foreach ($posts as $item) {
            $hit = $bySlug->firstWhere('slug', $item->anchor());
            if ($hit instanceof EntryTranslation && ! in_array($hit->entry_id, $this->matchedOf([...$pages, ...$posts]), true)) {
                $this->matched[$item->key] = $hit->entry_id;
            }
        }

        // A record staff renamed or moved is still found by the ulid the ledger keeps.
        $refs = [];
        foreach ([...$pages, ...$posts] as $item) {
            if (! isset($this->matched[$item->key]) && ! isset($conflicts[$item->key]) && isset($context->ledger[$item->key])) {
                $refs[$item->key] = $context->ledger[$item->key]['ref'];
            }
        }
        if ($refs !== []) {
            $byUlid = Entry::withTrashed()->whereIn('ulid', array_values($refs))->get(['id', 'ulid', 'type', 'deleted_at'])->keyBy('ulid');
            foreach ($refs as $key => $ulid) {
                $entry = $byUlid->get($ulid);
                if ($entry instanceof Entry && $entry->type === (str_starts_with($key, 'page:') ? 'page' : 'post')) {
                    if ($entry->trashed()) {
                        $trashed[$key] = $entry->id;
                    } else {
                        $this->matched[$key] = $entry->id;
                    }
                }
            }
        }

        $ids = [];
        foreach ([...$pages, ...$posts] as $item) {
            if (isset($this->matched[$item->key])) {
                $ids[$item->key] = $this->matched[$item->key];
            }
        }
        $states = $this->entryStates(array_values($ids));
        foreach ($ids as $key => $id) {
            $this->entryKeys[$id] = $key;
        }
        $drafts = app(PendingEntryDrafts::class)->among(array_values($ids));
        $edited = app(EntryRevisions::class)->editedByPeople(array_values($ids));

        $planned = [];
        foreach ([...$pages, ...$posts] as $item) {
            $id = $ids[$item->key] ?? null;
            $current = $id === null ? null : ($states[$id]['state'] ?? null);
            $desired = $this->desiredEntry($item, $current);
            $notes = [];
            if ($item->kind === 'page' && $current !== null) {
                $notes[] = $this->blockSummary($current, $desired);
            }
            if ($item->kind === 'post' && $current !== null && (Cast::strings($current['categories'] ?? null)[0] ?? null) !== (Cast::strings($desired['categories'] ?? null)[0] ?? null)) {
                $notes[] = 'primary category changes: the address moves with a 301';
            }

            $planned[$item->key] = $this->decide(
                $context,
                $item,
                $current,
                $desired,
                $id === null ? null : ($states[$id]['ulid'] ?? null),
                $id,
                array_values(array_filter($notes)),
                evidence: $id !== null && in_array($id, $edited, true) ? 'changed by a person in the editor (revision history)' : null,
                draftPending: $id !== null && in_array($id, $drafts, true),
                conflict: $conflicts[$item->key] ?? null,
                trashedId: $trashed[$item->key] ?? null,
            );
        }

        return $planned;
    }

    /**
     * Managed fields of these entries, as the ledger hashes them.
     *
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, state: array<string, mixed>}>
     */
    public function entryStates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $entries = Entry::query()->whereIn('id', $ids)->get(['id', 'ulid', 'type', 'status', 'meta']);
        $translations = EntryTranslation::query()->whereIn('entry_id', $ids)->whereIn('locale', $this->locales)
            ->get(['entry_id', 'locale', 'title', 'slug', 'body', 'excerpt', 'seo_title', 'seo_description', 'status']);
        $byEntry = [];
        foreach ($translations as $translation) {
            $byEntry[$translation->entry_id][] = $translation;
        }
        $categories = $this->categoryKeys('entry_taxonomies', 'entry_id', $ids);

        $states = [];
        foreach ($entries as $entry) {
            $byLocale = [];
            foreach ($byEntry[$entry->id] ?? [] as $translation) {
                $row = [
                    'title' => $translation->title,
                    'excerpt' => $translation->excerpt,
                    'body' => $translation->body,
                    'seo_title' => $translation->seo_title,
                    'seo_description' => $translation->seo_description,
                    'status' => $translation->status,
                ];
                if ($entry->type === 'post') {
                    $row['slug'] = $translation->slug;
                }
                $byLocale[$translation->locale] = $row;
            }
            $state = ['status' => $entry->status];
            if ($entry->type === 'post') {
                $image = $entry->meta['image_url'] ?? null;
                $state['image'] = is_string($image) && $image !== '' ? $image : null;
                $state['categories'] = $categories[$entry->id] ?? [];
            }
            $state['translations'] = [];
            foreach ($this->locales as $locale) {
                $state['translations'][$locale] = $byLocale[$locale] ?? null;
            }
            $states[$entry->id] = ['ulid' => $entry->ulid, 'state' => $state];
        }

        return $states;
    }

    /**
     * Category keys per item, primary first (facet terms are not categories).
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function categoryKeys(string $pivot, string $column, array $ids): array
    {
        $rows = DB::table($pivot)->where('site_id', $this->site->id())->whereIn($column, $ids)
            ->where('position', '<', self::FACET_BASE)->orderBy('position')->get([$column, 'taxonomy_id']);

        $keys = [];
        foreach ($rows as $row) {
            $taxonomyId = Cast::int($row->taxonomy_id);
            if (isset($this->taxonomies[$taxonomyId])) {
                $keys[Cast::int($row->{$column})][] = $this->taxonomyKey($taxonomyId);
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function desiredEntry(PackItem $item, ?array $current): array
    {
        $status = Cast::string($item->data['status'] ?? null, 'draft');
        $state = ['status' => $status];

        if ($item->kind === 'post') {
            $image = $item->data['image'] ?? null;
            if (PackValidator::isMediaRef($image)) {
                $slot = $this->slots[$image['media']] ?? null;
                if ($slot === null) {
                    $this->warnings[] = "{$item->key}: image slot '{$image['media']}' has no file and no existing image; the card keeps its live image.";
                }
                $state['image'] = $slot['key'] ?? Cast::stringOrNull($current['image'] ?? null);
            } else {
                $state['image'] = Cast::stringOrNull($image);
            }
            $state['categories'] = Cast::strings($item->data['categories'] ?? []);
        }

        $titles = Cast::arr($item->data['title'] ?? null);
        $slugs = Cast::arr($item->data['slug'] ?? null);
        $bodies = Cast::arr($item->data['body'] ?? null);
        $live = Cast::arr($current['translations'] ?? null);
        $state['translations'] = [];
        foreach ($this->locales as $locale) {
            $title = Cast::stringOrNull($titles[$locale] ?? null);
            if ($title === null) {
                $state['translations'][$locale] = $live[$locale] ?? null;

                continue;
            }
            $row = [
                'title' => $title,
                'excerpt' => self::text($item->data, 'excerpt', $locale),
                'body' => ['blocks' => $this->resolveBody(Cast::arr($bodies[$locale] ?? $bodies[$this->locale] ?? []), $item->key)],
                'seo_title' => self::text($item->data, 'seo_title', $locale),
                'seo_description' => self::text($item->data, 'seo_description', $locale),
                'status' => $status,
            ];
            if ($item->kind === 'post') {
                $row['slug'] = $locale === $this->locale ? $item->anchor() : Cast::string($slugs[$locale] ?? null, $item->anchor());
            }
            $state['translations'][$locale] = $row;
        }

        return $state;
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $field, string $locale): ?string
    {
        $text = Cast::stringOrNull(Cast::arr($data[$field] ?? null)[$locale] ?? null);

        return $text === '' ? null : $text;
    }

    /**
     * "blocks 9 → 7 (hero → hero_slider, …)" for a page whose block list changes.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $desired
     */
    private function blockSummary(array $current, array $desired): ?string
    {
        $types = function (array $state): array {
            $body = Cast::arr(Cast::arr(Cast::arr($state['translations'] ?? null)[$this->locale] ?? null)['body'] ?? null);

            return array_map(fn (mixed $block): string => Cast::string(Cast::arr($block)['type'] ?? null), Cast::arr($body['blocks'] ?? null));
        };
        $before = $types($current);
        $after = $types($desired);
        if ($before === $after) {
            return null;
        }
        $swaps = [];
        foreach ($before as $index => $type) {
            if (isset($after[$index]) && $after[$index] !== $type && count($swaps) < 2) {
                $swaps[] = "{$type} → {$after[$index]}";
            }
        }

        return 'blocks '.count($before).' → '.count($after).($swaps === [] ? '' : ' ('.implode(', ', $swaps).', …)');
    }

    // ---------------------------------------------------------------- products

    /** @return array<string, PlanItem> */
    private function productItems(Pack $pack, PlanContext $context): array
    {
        $items = $pack->ofKind('product');
        if ($items === []) {
            return [];
        }

        $bySlug = ProductTranslation::query()->where('locale', $this->locale)
            ->whereIn('slug', array_map(fn (PackItem $item): string => $item->anchor(), $items))
            ->orderBy('product_id')->get(['product_id', 'slug']);
        foreach ($items as $item) {
            $hit = $bySlug->firstWhere('slug', $item->anchor());
            if ($hit instanceof ProductTranslation) {
                $this->matched[$item->key] = $hit->product_id;
            }
        }
        $refs = [];
        foreach ($items as $item) {
            if (! isset($this->matched[$item->key]) && isset($context->ledger[$item->key])) {
                $refs[$item->key] = $context->ledger[$item->key]['ref'];
            }
        }
        if ($refs !== []) {
            $byUlid = Product::query()->whereIn('ulid', array_values($refs))->pluck('id', 'ulid');
            foreach ($refs as $key => $ulid) {
                if (isset($byUlid[$ulid])) {
                    $this->matched[$key] = Cast::int($byUlid[$ulid]);
                }
            }
        }

        $ids = [];
        foreach ($items as $item) {
            if (isset($this->matched[$item->key])) {
                $ids[$item->key] = $this->matched[$item->key];
                $this->productKeys[$this->matched[$item->key]] = $item->key;
            }
        }
        $states = $this->productStates(array_values($ids));

        $planned = [];
        foreach ($items as $item) {
            $id = $ids[$item->key] ?? null;
            $current = $id === null ? null : ($states[$id]['state'] ?? null);
            $planned[$item->key] = $this->decide(
                $context,
                $item,
                $current,
                $this->desiredProduct($item, $current),
                $id === null ? null : ($states[$id]['ulid'] ?? null),
                $id,
                evidence: 'catalog edits leave no history, so a product the loader has not written before counts as edited',
            );
        }

        return $planned;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, state: array<string, mixed>}>
     */
    public function productStates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $ids)->get(['id', 'ulid', 'status']);
        $translations = ProductTranslation::query()->whereIn('product_id', $ids)->whereIn('locale', $this->locales)
            ->get(['product_id', 'locale', 'name', 'slug', 'summary', 'description', 'seo_title', 'seo_description']);
        $byProduct = [];
        foreach ($translations as $translation) {
            $byProduct[$translation->product_id][] = $translation;
        }
        $categories = $this->categoryKeys('product_taxonomies', 'product_id', $ids);
        $images = DB::table('product_images')->join('media', 'media.id', '=', 'product_images.media_id')
            ->where('product_images.site_id', $this->site->id())->whereIn('product_images.product_id', $ids)
            ->orderBy('product_images.position')->orderBy('product_images.id')
            ->get(['product_images.product_id', 'media.storage_key']);
        $gallery = [];
        foreach ($images as $image) {
            $gallery[Cast::int($image->product_id ?? null)][] = Cast::string($image->storage_key ?? null);
        }

        $states = [];
        foreach ($products as $product) {
            $byLocale = [];
            foreach ($byProduct[$product->id] ?? [] as $translation) {
                $byLocale[$translation->locale] = [
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'summary' => $translation->summary,
                    'description' => $translation->description,
                    'seo_title' => $translation->seo_title,
                    'seo_description' => $translation->seo_description,
                ];
            }
            $state = [
                'status' => $product->status,
                'categories' => $categories[$product->id] ?? [],
                'images' => $gallery[$product->id] ?? [],
                'translations' => [],
            ];
            foreach ($this->locales as $locale) {
                $state['translations'][$locale] = $byLocale[$locale] ?? null;
            }
            $states[$product->id] = ['ulid' => $product->ulid, 'state' => $state];
        }

        return $states;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function desiredProduct(PackItem $item, ?array $current): array
    {
        $images = [];
        $complete = true;
        foreach (Cast::arr($item->data['images'] ?? []) as $image) {
            $slot = PackValidator::isMediaRef($image) ? ($this->slots[$image['media']] ?? null) : null;
            if ($slot === null) {
                $complete = false;

                continue;
            }
            $images[] = $slot['key'];
        }
        if (! $complete) {
            $this->warnings[] = "{$item->key}: an image slot has no file and no existing image; the gallery keeps its live images.";
            $images = Cast::strings($current['images'] ?? []);
        }

        $names = Cast::arr($item->data['name'] ?? null);
        $slugs = Cast::arr($item->data['slug'] ?? null);
        $live = Cast::arr($current['translations'] ?? null);
        $translations = [];
        foreach ($this->locales as $locale) {
            $name = Cast::stringOrNull($names[$locale] ?? null);
            $translations[$locale] = $name === null ? ($live[$locale] ?? null) : [
                'name' => $name,
                'slug' => $locale === $this->locale ? $item->anchor() : Cast::string($slugs[$locale] ?? null, $item->anchor()),
                'summary' => self::text($item->data, 'summary', $locale),
                'description' => self::text($item->data, 'description', $locale),
                'seo_title' => self::text($item->data, 'seo_title', $locale),
                'seo_description' => self::text($item->data, 'seo_description', $locale),
            ];
        }

        return [
            'status' => Cast::string($item->data['status'] ?? null, 'draft'),
            'categories' => Cast::strings($item->data['categories'] ?? []),
            'images' => $images,
            'translations' => $translations,
        ];
    }

    // ---------------------------------------------------------------- menus

    /** @return array<string, PlanItem> */
    private function menuItems(Pack $pack, PlanContext $context): array
    {
        $items = $pack->ofKind('menu');
        if ($items === []) {
            return [];
        }

        $regions = array_map(fn (PackItem $item): string => $item->anchor(), $items);
        $assignments = MenuAssignment::query()->where('locale', $this->locale)->whereIn('region_key', $regions)->get(['region_key', 'menu_id'])->keyBy('region_key');
        $menuIds = [];
        foreach ($items as $item) {
            $assignment = $assignments->get($item->anchor());
            if ($assignment instanceof MenuAssignment) {
                $menuIds[$item->key] = $assignment->menu_id;
                $this->matched[$item->key] = $assignment->menu_id;
            }
        }
        $states = $this->menuStates(array_values($menuIds));
        $wanted = [];
        foreach ($items as $item) {
            array_push($wanted, ...$this->menuTargets($this->desiredMenuNodes(Cast::arr($item->data['items'] ?? []))));
        }
        $this->rememberLiveTargets(array_values(array_diff(array_unique($wanted), $this->knownTargets($pack))));
        $known = $this->knownTargets($pack);

        $planned = [];
        foreach ($items as $item) {
            $id = $menuIds[$item->key] ?? null;
            $current = $id === null ? null : ($states[$id]['state'] ?? null);
            $desired = ['items' => $this->desiredMenuNodes(Cast::arr($item->data['items'] ?? []))];
            $missing = array_values(array_diff($this->menuTargets($desired['items']), $known));
            $conflict = $missing === [] ? null : 'menu links to '.implode(', ', $missing).', which is neither in the pack nor on the site';

            $notes = [];
            if ($current !== null) {
                $notes[] = count(Cast::arr($current['items'])).' → '.count($desired['items']).' top items';
            }

            $planned[$item->key] = $this->decide($context, $item, $current, $desired, $id === null ? null : ($states[$id]['ulid'] ?? null), $id, $notes, conflict: $conflict);
        }

        return $planned;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{ulid: string, state: array<string, mixed>}>
     */
    public function menuStates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $menus = Menu::query()->whereIn('id', $ids)->pluck('ulid', 'id');
        $nodes = MenuItem::query()->whereIn('menu_id', $ids)->orderBy('position')->orderBy('id')
            ->with(['translations' => fn ($query) => $query->whereIn('locale', $this->locales)])
            ->get(['id', 'menu_id', 'parent_id', 'position', 'link_type', 'routable_type', 'routable_id', 'url', 'dynamic_key']);
        $this->rememberTargets(array_values($nodes->all()));

        $states = [];
        foreach ($menus as $id => $ulid) {
            $states[Cast::int($id)] = [
                'ulid' => Cast::string($ulid),
                'state' => ['items' => $this->liveMenuNodes($nodes->where('menu_id', $id)->all(), null)],
            ];
        }

        return $states;
    }

    /**
     * @param  array<array-key, MenuItem>  $nodes
     * @return list<array<string, mixed>>
     */
    private function liveMenuNodes(array $nodes, ?int $parentId): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if ($node->parent_id !== $parentId) {
                continue;
            }
            $labels = [];
            foreach ($node->translations as $translation) {
                $labels[$translation->locale] = $translation->label;
            }
            ksort($labels);
            $row = ['label' => $labels, 'link' => $this->liveLink($node)];
            $children = $this->liveMenuNodes($nodes, $node->id);
            if ($children !== []) {
                $row['children'] = $children;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function liveLink(MenuItem $node): string
    {
        $id = (int) $node->routable_id;

        return match ($node->link_type) {
            'entry' => 'target:'.($this->entryKeys[$id] ?? 'entry:unknown'),
            'taxonomy' => 'target:'.(isset($this->taxonomies[$id]) ? $this->taxonomyKey($id) : 'taxonomy:unknown'),
            'product' => 'target:'.($this->productKeys[$id] ?? 'product:unknown'),
            'url' => 'url:'.$node->url,
            default => 'dynamic:'.$node->dynamic_key,
        };
    }

    /**
     * Give the entries and products live menu items point at a key (pages by path, posts and
     * products by slug), two batched queries.
     *
     * @param  list<MenuItem>  $nodes
     */
    private function rememberTargets(array $nodes): void
    {
        $entryIds = [];
        $productIds = [];
        foreach ($nodes as $node) {
            $id = (int) $node->routable_id;
            if ($node->link_type === 'entry' && ! isset($this->entryKeys[$id])) {
                $entryIds[] = $id;
            } elseif ($node->link_type === 'product' && ! isset($this->productKeys[$id])) {
                $productIds[] = $id;
            }
        }
        if ($entryIds !== []) {
            $entries = Entry::query()->whereIn('id', $entryIds)->pluck('type', 'id');
            $paths = SiteRoute::query()->where('locale', $this->locale)->whereIn('entry_id', $entryIds)->pluck('path', 'entry_id');
            $slugs = EntryTranslation::query()->where('locale', $this->locale)->whereIn('entry_id', $entryIds)->pluck('slug', 'entry_id');
            foreach ($entries as $id => $type) {
                $id = Cast::int($id);
                $this->entryKeys[$id] = $type === 'page'
                    ? 'page:'.Cast::string($paths[$id] ?? null, '/'.Cast::string($slugs[$id] ?? null))
                    : 'post:'.Cast::string($slugs[$id] ?? null);
            }
        }
        if ($productIds !== []) {
            foreach (ProductTranslation::query()->where('locale', $this->locale)->whereIn('product_id', $productIds)->pluck('slug', 'product_id') as $id => $slug) {
                $this->productKeys[Cast::int($id)] = 'product:'.Cast::string($slug);
            }
        }
    }

    /**
     * Find the live pages (by address), posts and products (by slug) that menu items name but the
     * pack does not hold — three batched queries.
     *
     * @param  list<string>  $keys
     */
    private function rememberLiveTargets(array $keys): void
    {
        $paths = [];
        $posts = [];
        $products = [];
        foreach ($keys as $key) {
            match (true) {
                str_starts_with($key, 'page:') => $paths[] = substr($key, 5),
                str_starts_with($key, 'post:') => $posts[] = substr($key, 5),
                str_starts_with($key, 'product:') => $products[] = substr($key, 8),
                default => null,
            };
        }
        if ($paths !== []) {
            $routes = SiteRoute::query()->where('locale', $this->locale)->whereIn('path', $paths)->whereNotNull('entry_id')
                ->whereIn('entry_id', Entry::query()->select('id')->where('type', 'page'))->pluck('entry_id', 'path');
            foreach ($routes as $path => $id) {
                $this->entryKeys[Cast::int($id)] = 'page:'.$path;
            }
        }
        if ($posts !== []) {
            $rows = EntryTranslation::query()->where('locale', $this->locale)->whereIn('slug', $posts)
                ->whereIn('entry_id', Entry::query()->select('id')->where('type', 'post'))->pluck('entry_id', 'slug');
            foreach ($rows as $slug => $id) {
                $this->entryKeys[Cast::int($id)] ??= 'post:'.$slug;
            }
        }
        if ($products !== []) {
            foreach (ProductTranslation::query()->where('locale', $this->locale)->whereIn('slug', $products)->pluck('product_id', 'slug') as $slug => $id) {
                $this->productKeys[Cast::int($id)] ??= 'product:'.$slug;
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private function desiredMenuNodes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $node = Cast::arr($node);
            $labels = array_filter(Cast::arr($node['label'] ?? null), fn (mixed $label, mixed $locale): bool => is_string($label) && in_array($locale, $this->locales, true), ARRAY_FILTER_USE_BOTH);
            ksort($labels);
            $link = match (true) {
                isset($node['target']) => 'target:'.Cast::string($node['target']),
                isset($node['url']) => 'url:'.Cast::string($node['url']),
                default => 'dynamic:'.Cast::string($node['dynamic'] ?? null),
            };
            $row = ['label' => $labels, 'link' => $link];
            $children = $this->desiredMenuNodes(Cast::arr($node['children'] ?? []));
            if ($children !== []) {
                $row['children'] = $children;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function menuTargets(array $nodes): array
    {
        $targets = [];
        foreach ($nodes as $node) {
            $link = Cast::string($node['link'] ?? null);
            if (str_starts_with($link, 'target:')) {
                $targets[] = substr($link, 7);
            }
            array_push($targets, ...$this->menuTargets(array_values(array_map(Cast::arr(...), Cast::arr($node['children'] ?? [])))));
        }

        return $targets;
    }

    /**
     * Keys a menu may link to: pack items that will exist after the load, plus live pages, posts,
     * products and categories outside the pack (looked up by their derived keys).
     *
     * @return list<string>
     */
    private function knownTargets(Pack $pack): array
    {
        $known = [];
        foreach ($pack->items as $item) {
            if (in_array($item->kind, ['taxonomy', 'page', 'post', 'product'], true)) {
                $known[] = $item->key;
            }
        }
        foreach (array_keys($this->taxonomies) as $id) {
            $known[] = $this->taxonomyKey($id);
        }
        array_push($known, ...array_values($this->entryKeys), ...array_values($this->productKeys));

        return array_values(array_unique($known));
    }

    // ---------------------------------------------------------------- settings, profile, redirects

    private function settingItem(PackItem $item, PlanContext $context): PlanItem
    {
        [$group, $name] = explode('.', $item->anchor(), 2);
        $row = SiteSetting::query()->where('group_name', $group)->where('key', $name)->first(['group_name', 'key', 'value']);
        $live = $row === null ? null : Cast::arr($row->value);
        $managed = isset($item->data['managed_keys']) ? Cast::strings($item->data['managed_keys']) : null;

        $current = $live === null ? null : self::settingState($live, $managed);
        $desired = [];
        foreach (Cast::arr($item->data['value'] ?? null) as $key => $value) {
            if ($managed !== null && ! in_array($key, $managed, true)) {
                continue;
            }
            if (PackValidator::isMediaRef($value)) {
                $slot = $this->slots[$value['media']] ?? null;
                if ($slot === null) {
                    $this->warnings[] = "{$item->key}: image slot '{$value['media']}' has no file and no existing image; '{$key}' keeps its live value.";
                    if ($current !== null && array_key_exists($key, $current)) {
                        $desired[$key] = $current[$key];
                    }

                    continue;
                }
                $value = $this->urls->url($slot['key']);
            }
            $desired[$key] = $value;
        }

        return $this->decide($context, $item, $current, $desired, $current === null ? null : $item->anchor(), null);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<string>|null  $managed
     * @return array<string, mixed>
     */
    public static function settingState(array $value, ?array $managed): array
    {
        $state = [];
        foreach ($value as $key => $sub) {
            if ($managed === null || in_array((string) $key, $managed, true)) {
                $state[(string) $key] = $sub;
            }
        }

        return $state;
    }

    private function profileItem(PackItem $item, PlanContext $context): PlanItem
    {
        $fields = array_keys(Cast::arr($item->data['fields'] ?? null));
        $localized = Cast::arr($item->data['localized'] ?? null);
        $current = $this->profileState(array_map('strval', $fields), array_map('strval', array_keys($localized)));

        $desired = [];
        foreach (Cast::arr($item->data['fields'] ?? null) as $name => $value) {
            $desired[(string) $name] = $value;
        }
        $live = Cast::arr($current['translations'] ?? null);
        $desired['translations'] = [];
        foreach ($this->locales as $locale) {
            $row = [];
            foreach ($localized as $name => $map) {
                $text = Cast::arr($map)[$locale] ?? null;
                $row[(string) $name] = is_string($text) ? $text : (Cast::arr($live[$locale] ?? null)[(string) $name] ?? null);
            }
            $desired['translations'][$locale] = $row;
        }

        if ($current !== null && Cast::stringOrNull(SiteProfile::query()->first()?->translations()->where('locale', $this->locale)->value('license_text')) !== null) {
            $this->warnings[] = 'profile: the site has its own licence text, which is shown instead of the pack\'s licence details.';
        }

        return $this->decide($context, $item, $current, $desired, $current === null ? null : 'profile', null);
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $localized
     * @return array<string, mixed>|null
     */
    public function profileState(array $fields, array $localized): ?array
    {
        $profile = SiteProfile::query()->first();
        if ($profile === null) {
            return null;
        }

        $state = [];
        foreach ($fields as $field) {
            $value = $profile->getAttribute($field);
            $state[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        $translations = $profile->translations()->whereIn('locale', $this->locales)->get();
        $state['translations'] = [];
        foreach ($this->locales as $locale) {
            $translation = $translations->firstWhere('locale', $locale);
            $row = [];
            foreach ($localized as $field) {
                $row[$field] = $translation?->getAttribute($field);
            }
            $state['translations'][$locale] = $row;
        }

        return $state;
    }

    private function redirectItem(PackItem $item, PlanContext $context): PlanItem
    {
        [$locale, $from] = explode(':', $item->anchor(), 2);
        $redirect = Redirect::query()->where('locale', $locale)->where('from_path', $from)->first(['to_path', 'status_code']);
        $current = $redirect === null ? null : ['to' => $redirect->to_path, 'status' => $redirect->status_code];
        $desired = ['to' => Cast::string($item->data['to'] ?? null), 'status' => Cast::int($item->data['status'] ?? 301, 301)];
        $live = SiteRoute::query()->where('locale', $locale)->where('path', $from)->exists();

        return $this->decide($context, $item, $current, $desired, $current === null ? null : $from, null,
            conflict: $live ? "{$from} is a live address; a redirect would hide it" : null);
    }

    // ---------------------------------------------------------------- warnings

    /** Data-bound blocks whose source would show no published card after the load. */
    private function gridWarnings(Pack $pack): void
    {
        $publishedProducts = count(array_filter($pack->ofKind('product'), fn (PackItem $item): bool => ($item->data['status'] ?? null) === 'published'))
            + Product::query()->where('status', 'published')->whereNotIn('id', array_keys($this->productKeys))->count();

        foreach ($pack->ofKind('page') as $page) {
            foreach (Cast::arr(Cast::arr($page->data['body'] ?? null)[$pack->defaultLocale] ?? null) as $block) {
                $block = Cast::arr($block);
                $props = Cast::arr($block['props'] ?? null);
                if (($block['type'] ?? null) === 'product_grid' && $publishedProducts === 0) {
                    $this->warnings[] = "{$page->key}: product_grid would show 0 published cards (no published product).";
                }
                if (($block['type'] ?? null) === 'entry_grid' && is_string($props['taxonomy_slug'] ?? null)) {
                    $slug = $props['taxonomy_slug'];
                    $count = count(array_filter($pack->ofKind('post'), fn (PackItem $post): bool => ($post->data['status'] ?? null) === 'published'
                        && array_filter(Cast::strings($post->data['categories'] ?? []), fn (string $key): bool => str_ends_with($key, '/'.$slug)) !== []));
                    if ($count === 0) {
                        $this->warnings[] = "{$page->key}: entry_grid ({$slug}) would show 0 published cards from this pack.";
                    }
                }
            }
        }
    }
}

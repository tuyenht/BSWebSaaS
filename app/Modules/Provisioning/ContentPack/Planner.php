<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Casts\SanitizedPageBody;
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
use App\Modules\Cms\Routing\CategoryChange;
use App\Modules\Cms\Routing\CategoryRelinker;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ButtonLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Computes the plan: for every pack item, its live record (found by the item's key, or by the
 * ledger's ulid when staff renamed it), the record's managed fields in canonical form (`current`),
 * what the pack would make of them (`desired`) and the verdict (spec §3.3). Read-only; runs inside
 * the site's tenant context, one lookup query per kind (batched by key), never per item. The same
 * readers produce the hash the ledger records after a write, so a record read back right after the
 * loader wrote it hashes equal to its plan's `desired`.
 *
 * On the re-plan inside the --apply transaction (`$lock`), every row a verdict rests on is read
 * FOR NO KEY UPDATE (LOCK), so no staff save can land between the plan and the write; the dry run
 * takes no lock. Not FOR UPDATE: that also blocks the FOR KEY SHARE a staff save takes on a
 * category it files a post under (the foreign-key check), and the two transactions deadlock.
 * Every address the load would write is composed the way the platform composes it and checked
 * against the routes before anything is written (addresses()).
 *
 * Images: a slot resolves to the site's media row holding the slot's own file (same collection, same
 * sha256 — what the image step, `--media`, uploads), else to the row its `legacy_key` names. A block
 * image prop whose slot does not resolve is left out; a card image, a product gallery or a setting
 * whose slot does not resolve keeps its live value. Rows in the Trash never resolve.
 */
final class Planner
{
    private const int FACET_BASE = 1000;

    private string $locale = '';

    /** @var list<string> */
    private array $locales = [];

    /** @var array<int, array{vocabulary: string, parent_id: int|null, position: int, list_template: string|null, detail_template: string|null, tr: array<string, array{name: string, slug: string}>}> */
    private array $taxonomies = [];

    /** @var array<int, string> taxonomy id → its key (the pack item's when matched, else derived) */
    private array $taxonomyKeys = [];

    /** @var array<int, string> entry id → key */
    private array $entryKeys = [];

    /** @var array<int, string> product id → key */
    private array $productKeys = [];

    /** @var array<int, string|null> matched page id → its live parent page's key (null at the top) */
    private array $pageParents = [];

    /** @var array<string, array{id: int, key: string, mime: string, size: int}|null> */
    private array $slots = [];

    /** Slots resolved to the pack's own file (the rest of the resolved ones use a legacy image). */
    private int $packed = 0;

    /** @var array<string, true> slots that name a file in the pack */
    private array $slotFiles = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, int> pack key → matched record id (taxonomies, entries, products, menus) */
    private array $matched = [];

    /** @var array<string, string> pack key → the earlier ledger key it carries on (a renamed or moved category) */
    private array $carried = [];

    /** @var array<string, array<string, string>> pack key → locale → the address the load gives it */
    private array $plannedPaths = [];

    /** @var array<string, list<int>> category key → ids of the routes of the posts and products it carries along */
    private array $branchRoutes = [];

    /** @var array<string, list<array{column: string, id: int, locale: string, from: string, to: string}>> category key → the moves of the posts and products it carries along */
    private array $carriedMoves = [];

    private bool $lock = false;

    /** The row lock of the --apply re-plan (see the class comment). */
    private const string LOCK = 'for no key update';

    /** The kinds a category relink carries along: pivot, item column, item table, translation table. */
    private const array BRANCH_KINDS = [
        'post' => ['entry_taxonomies', 'entry_id', 'entries', 'entry_translations'],
        'product' => ['product_taxonomies', 'product_id', 'products', 'product_translations'],
    ];

    public function __construct(
        private readonly CurrentSite $site,
        private readonly MediaUrlResolver $urls,
        private readonly PathBuilder $paths,
        private readonly PermalinkConfig $permalinks,
        private readonly CategoryRelinker $relinker,
    ) {}

    /**
     * @param  list<string>  $forced  --force-item keys
     * @param  bool  $lock  read the rows the plan rests on FOR NO KEY UPDATE (only inside the --apply transaction)
     *
     * @throws PackRejected the pack's languages do not fit the site, or a forced key is not in the pack
     */
    public function plan(Pack $pack, string $siteUlid, string $siteName, string $host, array $forced = [], bool $lock = false): Plan
    {
        $this->reset();
        $this->lock = $lock;
        $this->languages($pack);

        foreach ($forced as $key) {
            if ($pack->item($key) === null) {
                throw new PackRejected("Refused: --force-item {$key} is not an item of the pack.");
            }
        }

        $ledger = Ledger::read($pack->code, $lock);
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
        $forms = $this->formItems($pack, $context);

        foreach ($pack->items as $item) {
            $planned = match ($item->kind) {
                'setting' => $this->settingItem($item, $context),
                'profile' => $this->profileItem($item, $context),
                'taxonomy' => null,
                'product' => $products[$item->key] ?? null,
                'post', 'page' => $entries[$item->key] ?? null,
                'menu' => $menus[$item->key] ?? null,
                'form' => $forms[$item->key] ?? null,
                'redirect' => $this->redirectItem($item, $context, $entries),
                default => null,
            };
            if ($planned !== null) {
                $items[] = $planned;
            }
        }
        $items = $this->addresses($items);
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
            media: ['slots' => count($pack->slots), 'resolved' => $resolved, 'packed' => $this->packed],
            carried: $this->carried,
        );
    }

    /**
     * The address each category, page, post and product of the last plan is given, per language
     * (only the items that write, and only where the platform would route them).
     *
     * @return array<string, array<string, string>>
     */
    public function plannedPaths(): array
    {
        return $this->plannedPaths;
    }

    /**
     * Ids of the routes of the posts and products a category write of the last plan carries along.
     *
     * @return list<int>
     */
    public function branchRoutes(string $key): array
    {
        return $this->branchRoutes[$key] ?? [];
    }

    /**
     * Where each post and product a category write of the last plan carries along moves to — one
     * line per address that changes, under every moving category above the item.
     *
     * @return array<string, list<array{column: string, id: int, locale: string, from: string, to: string}>>
     */
    public function carriedMoves(): array
    {
        return $this->carriedMoves;
    }

    /** The record a planned key was matched to (after the plan; internal to the loader). */
    public function matchedId(string $key): ?int
    {
        return $this->matched[$key] ?? null;
    }

    /**
     * Each matched page's live parent page, by key, as the last plan read it: the ledger records
     * it so a later plan can tell a page staff moved from one the pack never placed.
     *
     * @return array<int, string|null>
     */
    public function pageParents(): array
    {
        return $this->pageParents;
    }

    /** @return array{id: int, key: string, mime: string, size: int}|null */
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
        $this->pageParents = [];
        $this->slots = [];
        $this->packed = 0;
        $this->slotFiles = [];
        $this->warnings = [];
        $this->matched = [];
        $this->carried = [];
        $this->plannedPaths = [];
        $this->branchRoutes = [];
        $this->carriedMoves = [];
        $this->lock = false;
    }

    /**
     * The query, read FOR NO KEY UPDATE on the --apply re-plan.
     *
     * @template TQuery of Builder<*>|Relation<*, *, *>
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function locked(Builder|Relation $query): Builder|Relation
    {
        if ($this->lock) {
            $query->lock(self::LOCK);
        }

        return $query;
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
        $hashes = [];
        foreach ($pack->slots as $name => $slot) {
            $this->slots[$name] = null;
            $file = $slot['file'] ?? null;
            if (is_string($file) && $file !== '') {
                $this->slotFiles[$name] = true;
                $hashes[$name] = Cast::string($slot['collection'] ?? 'library')."\0".hash_file('sha256', $pack->dir.'/'.$file);
            }
        }
        if ($hashes !== []) {
            $rows = Media::query()->whereIn('hash', array_values(array_unique(array_map(fn (string $at): string => explode("\0", $at, 2)[1], $hashes))))
                ->get(['id', 'collection', 'hash', 'storage_key', 'mime', 'size']);
            $byHash = [];
            foreach ($rows as $row) {
                $byHash[$row->collection."\0".$row->hash] = $row;
            }
            foreach ($hashes as $name => $at) {
                $row = $byHash[$at] ?? null;
                if ($row instanceof Media) {
                    $this->slots[$name] = ['id' => $row->id, 'key' => $row->storage_key, 'mime' => $row->mime, 'size' => $row->size];
                    $this->packed++;
                }
            }
        }

        $keys = [];
        foreach ($pack->slots as $name => $slot) {
            $legacy = $slot['legacy_key'] ?? null;
            if ($this->slots[$name] === null && is_string($legacy) && $legacy !== '') {
                $keys[$name] = $legacy;
            }
        }
        if ($keys === []) {
            return;
        }

        $media = Media::query()->whereIn('storage_key', array_values($keys))->get(['id', 'storage_key', 'mime', 'size'])->keyBy('storage_key');
        foreach ($keys as $name => $key) {
            $row = $media->get($key);
            if ($row instanceof Media) {
                $this->slots[$name] = ['id' => $row->id, 'key' => $row->storage_key, 'mime' => $row->mime, 'size' => $row->size];
            }
        }
    }

    /** Why a slot did not resolve, for a warning: its file is not uploaded yet, or it has none. */
    private function unresolved(string $slot): string
    {
        return isset($this->slotFiles[$slot])
            ? "image slot '{$slot}' is not uploaded yet (run with --media first)"
            : "image slot '{$slot}' has no file and no existing image";
    }

    /**
     * A document slot's resolved file, or null: a slot that resolved (by `legacy_key`) to a file the
     * download button does not take, an image, counts as unresolved.
     *
     * @return array{id: int, key: string, mime: string, size: int}|null
     */
    private function document(string $name): ?array
    {
        $slot = $this->slots[$name] ?? null;

        return $slot !== null && in_array($slot['mime'], ButtonLink::DOCUMENT_MIMES, true) ? $slot : null;
    }

    /**
     * A body with every `{"media": slot}` replaced by the slot's media id — or left out when the
     * slot does not resolve (a list element is dropped, an object prop is removed). A file button
     * gets the document's storage key and its label the document's type and size; a document that
     * does not resolve leaves the button out and its label as the plain text.
     */
    private function resolveBody(mixed $value, string $at): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $list = array_is_list($value);
        $out = [];
        foreach ($value as $key => $child) {
            if (PackValidator::isFileButton($child)) {
                $name = $child['file']['media'];
                $slot = $this->document($name);
                if ($slot === null) {
                    $this->warnings[] = "{$at}: ".(isset($this->slots[$name])
                        ? "document slot '{$name}' names a file that is not a document"
                        : str_replace('image slot', 'document slot', $this->unresolved($name))).'; the download button is left out.';

                    continue;
                }
                $out[$key] = ['k' => 'file', 'file' => $slot['key']];

                continue;
            }
            if (PackValidator::isFileLabel($child)) {
                $slot = $this->document($child['type_and_size_of']);
                $out[$key] = $slot === null
                    ? $child['text']
                    : $child['text'].' ('.Media::typeAndSize($slot['mime'], $slot['size']).')';

                continue;
            }
            if (PackValidator::isMediaRef($child)) {
                $slot = $this->slots[$child['media']] ?? null;
                if ($slot === null) {
                    $this->warnings[] = "{$at}: {$this->unresolved($child['media'])}; the image is left out.";

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
        } elseif ($ledger !== null && ! in_array($ledger['hash'], self::ledgerHashes($current, $ledger), true)) {
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

    /**
     * The hashes a ledger line may hold for this live state: the state itself, and the state as
     * the line recorded it while the item named no date or no parent page. The state without its
     * parent is accepted only from a line that does not record the page's parent (written before
     * parents were managed), or one whose recorded parent is still the live one: a page staff moved
     * since the last load is never taken for the loader's own write.
     *
     * @param  array<string, mixed>  $current
     * @param  array{hash: string, ref: string, parent?: string|null}  $ledger
     * @return list<string>
     */
    private static function ledgerHashes(array $current, array $ledger): array
    {
        $states = [$current, array_diff_key($current, ['published_at' => true])];
        if (! array_key_exists('parent', $ledger) || (array_key_exists('parent', $current) && $ledger['parent'] === $current['parent'])) {
            $states[] = array_diff_key($current, ['parent' => true]);
            $states[] = array_diff_key($current, ['published_at' => true, 'parent' => true]);
        }

        return array_values(array_unique(array_map(CanonicalHash::of(...), $states)));
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
        $rows = $this->locked(Taxonomy::query())->get(['id', 'vocabulary', 'parent_id', 'position', 'list_template', 'detail_template']);
        // Every language, not only the pack's: a category write re-syncs the posts and products under
        // it in every language they are routed in, and their addresses there carry these slugs.
        $translations = $this->locked(TaxonomyTranslation::query())->get(['taxonomy_id', 'locale', 'name', 'slug']);

        foreach ($rows as $row) {
            $this->taxonomies[$row->id] = [
                'vocabulary' => $row->vocabulary,
                'parent_id' => $row->parent_id,
                'position' => $row->position,
                'list_template' => $row->list_template,
                'detail_template' => $row->detail_template,
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

        // Addresses (conflicts, and the posts a rename moves) are checked once every item is planned.
        $planned = [];
        foreach ($items as $item) {
            $id = $this->matched[$item->key] ?? null;
            $current = $id === null ? null : $this->taxonomyState($id);
            $desired = $this->desiredTaxonomy($item, $current);
            $notes = [];

            // A category whose key changed (renamed through `was`, or under a renamed parent) keeps
            // the ledger line of its earlier key, so a staff edit since the last load still counts.
            $ref = $current === null ? null : $this->derivedTaxonomyKey((int) $id);
            if ($ref !== null && ! isset($context->ledger[$item->key]) && isset($context->ledger[$ref]) && $pack->item($ref) === null) {
                $this->carried[$item->key] = $ref;
            }

            $planned[] = $this->decide($context, $item, $current, $desired, $ref, $id, $notes);
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
            'detail_template' => $row['detail_template'],
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
            'detail_template' => array_key_exists('detail_template', $item->data) ? Cast::stringOrNull($item->data['detail_template']) : Cast::stringOrNull($current['detail_template'] ?? null),
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
        $dated = [];
        foreach ([...$pages, ...$posts] as $item) {
            if (isset($ids[$item->key]) && self::packDate($item) !== null) {
                $dated[] = $ids[$item->key];
            }
        }
        $parented = [];
        foreach ($pages as $item) {
            if (isset($ids[$item->key]) && array_key_exists('parent', $item->data)) {
                $parented[] = $ids[$item->key];
            }
        }
        foreach ($ids as $key => $id) {
            $this->entryKeys[$id] = $key;
        }
        $parentProblems = $this->pageParentProblems($pages);
        $states = $this->entryStates(array_values($ids), $dated, $parented);
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

            // A parent page of the pack that this load leaves missing (deleted by staff, or in conflict)
            // would leave the page nothing to sit under: a conflict, not a refused load mid-way.
            $conflict = $conflicts[$item->key] ?? $parentProblems[$item->key] ?? null;
            $parentKey = $item->kind === 'page' ? Cast::stringOrNull($item->data['parent'] ?? null) : null;
            if ($conflict === null && $parentKey !== null && isset($planned[$parentKey]) && ! isset($this->matched[$parentKey]) && ! $planned[$parentKey]->writes()) {
                $conflict = "its parent page {$parentKey} would not exist after this load";
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
                conflict: $conflict,
                trashedId: $trashed[$item->key] ?? null,
            );
        }

        return $planned;
    }

    /**
     * Managed fields of these entries, as the ledger hashes them.
     *
     * `published_at` is part of the state only for the entries in `$dated` (their pack item states a date),
     * so an item without a date never reports or rewrites the live one. `parent` (the parent page's
     * key) likewise only for the pages in `$parented` (their pack item names a parent, or null);
     * every page's live parent is kept for its ledger line (pageParents()).
     *
     * @param  list<int>  $ids
     * @param  list<int>  $dated
     * @param  list<int>  $parented
     * @return array<int, array{ulid: string, state: array<string, mixed>}>
     */
    public function entryStates(array $ids, array $dated = [], array $parented = []): array
    {
        if ($ids === []) {
            return [];
        }

        $entries = $this->locked(Entry::query()->whereIn('id', $ids))->get(['id', 'ulid', 'type', 'status', 'published_at', 'meta', 'parent_id']);
        $parentIds = [];
        foreach ($entries as $entry) {
            if ($entry->type === 'page' && $entry->parent_id !== null) {
                $parentIds[] = $entry->parent_id;
            }
        }
        $this->rememberEntryKeys($parentIds);
        $translations = $this->locked(EntryTranslation::query()->whereIn('entry_id', $ids)->whereIn('locale', $this->locales))
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
            if (in_array($entry->id, $dated, true)) {
                $state['published_at'] = $entry->published_at?->copy()->utc()->format('Y-m-d');
            }
            if ($entry->type === 'page') {
                // a parent the plan cannot name (in the trash) still reads as a difference
                $parent = $entry->parent_id === null ? null : ($this->entryKeys[$entry->parent_id] ?? 'page:(not found)');
                $this->pageParents[$entry->id] = $parent;
                if (in_array($entry->id, $parented, true)) {
                    $state['parent'] = $parent;
                }
            }
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
        $query = DB::table($pivot)->where('site_id', $this->site->id())->whereIn($column, $ids)
            ->where('position', '<', self::FACET_BASE)->orderBy('position');
        if ($this->lock) {
            $query->lock(self::LOCK);
        }
        $rows = $query->get([$column, 'taxonomy_id']);

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
        if (self::packDate($item) !== null) {
            $state['published_at'] = self::packDate($item);
        }
        if ($item->kind === 'page' && array_key_exists('parent', $item->data)) {
            $state['parent'] = Cast::stringOrNull($item->data['parent']);
        }

        if ($item->kind === 'post') {
            $image = $item->data['image'] ?? null;
            if (PackValidator::isMediaRef($image)) {
                $slot = $this->slots[$image['media']] ?? null;
                if ($slot === null) {
                    $this->warnings[] = "{$item->key}: {$this->unresolved($image['media'])}; the card keeps its live image.";
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
                // as the body cast will store it (formatted text sanitized), so the read-back check and the
                // next run's comparison see the same body
                'body' => SanitizedPageBody::cleanBody(['blocks' => $this->resolveBody(Cast::arr($bodies[$locale] ?? $bodies[$this->locale] ?? []), $item->key)]),
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

    /** The publication date (Y-m-d) a published pack item states, or null when it gives none (or is a draft). */
    private static function packDate(PackItem $item): ?string
    {
        return Cast::string($item->data['status'] ?? null, 'draft') === 'published' ? Cast::stringOrNull($item->data['published_at'] ?? null) : null;
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

        $products = $this->locked(Product::query()->whereIn('id', $ids))->get(['id', 'ulid', 'status']);
        $translations = $this->locked(ProductTranslation::query()->whereIn('product_id', $ids)->whereIn('locale', $this->locales))
            ->get(['product_id', 'locale', 'name', 'slug', 'summary', 'description', 'seo_title', 'seo_description']);
        $byProduct = [];
        foreach ($translations as $translation) {
            $byProduct[$translation->product_id][] = $translation;
        }
        $categories = $this->categoryKeys('product_taxonomies', 'product_id', $ids);
        $images = DB::table('product_images')->join('media', 'media.id', '=', 'product_images.media_id')
            ->where('product_images.site_id', $this->site->id())->whereIn('product_images.product_id', $ids)
            ->orderBy('product_images.position')->orderBy('product_images.id');
        if ($this->lock) {
            $images->lock(self::LOCK.' of product_images');
        }
        $images = $images->get(['product_images.product_id', 'media.storage_key']);
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
            $this->warnings[] = "{$item->key}: an image slot is not uploaded yet or has no file and no existing image; the gallery keeps its live images.";
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
            // An area that follows another area (menu_id NULL) has no menu of its own to match.
            if ($assignment instanceof MenuAssignment && $assignment->menu_id !== null) {
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

        $menus = $this->locked(Menu::query()->whereIn('id', $ids))->pluck('ulid', 'id');
        $nodes = $this->locked(MenuItem::query()->whereIn('menu_id', $ids))->orderBy('position')->orderBy('id')
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
        $this->rememberEntryKeys($entryIds);
        if ($productIds !== []) {
            foreach (ProductTranslation::query()->where('locale', $this->locale)->whereIn('product_id', $productIds)->pluck('slug', 'product_id') as $id => $slug) {
                $this->productKeys[Cast::int($id)] = 'product:'.Cast::string($slug);
            }
        }
    }

    /**
     * Give these live entries a key (pages by path, posts by slug) unless they have one; three
     * batched queries. An entry in the trash gets none.
     *
     * @param  list<int>  $entryIds
     */
    private function rememberEntryKeys(array $entryIds): void
    {
        $entryIds = array_values(array_unique(array_filter($entryIds, fn (int $id): bool => ! isset($this->entryKeys[$id]))));
        if ($entryIds === []) {
            return;
        }
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

    /**
     * What stops each pack page that names a parent from getting it, keyed by page key: a site that
     * nests page addresses under their parent (the loader keeps a page's address its own, so a
     * parent would move it), a parent that is neither in the pack nor a live page, or a parent
     * chain — the pack's stated parents over the site's live ones — that leads back to the page.
     * The live links are read only when some page names a parent, and only above the stated parents.
     *
     * @param  list<PackItem>  $pages
     * @return array<string, string>
     */
    private function pageParentProblems(array $pages): array
    {
        $stated = [];
        $topLevel = [];
        foreach ($pages as $item) {
            if (! array_key_exists('parent', $item->data)) {
                continue;
            }
            $parent = Cast::stringOrNull($item->data['parent']);
            if ($parent === null) {
                $topLevel[$item->key] = true;
            } else {
                $stated[$item->key] = $parent;
            }
        }
        if ($stated === []) {
            return [];
        }
        if ($this->permalinks->pagesNested()) {
            return array_map(fn (): string => 'the site nests page addresses under their parent page, and the loader keeps a page at its own address', $stated);
        }

        $inPack = array_flip(array_map(fn (PackItem $item): string => $item->key, $pages));
        $this->rememberLiveTargets(array_values(array_diff(array_values($stated), array_keys($inPack))));
        $starts = [];
        foreach ($stated as $parent) {
            $id = $this->matched[$parent] ?? $this->liveTarget($parent);
            if ($id !== null) {
                $starts[] = $id;
            }
        }
        $links = $this->parentLinks($starts);
        $this->rememberEntryKeys(array_values($links));

        $problems = [];
        foreach ($stated as $key => $parent) {
            if (! isset($inPack[$parent]) && $this->liveTarget($parent) === null) {
                $problems[$key] = "its parent page {$parent} is neither in the pack nor on the site";

                continue;
            }
            $seen = [];
            for ($cursor = $parent; $cursor !== null && ! isset($seen[$cursor]);) {
                if ($cursor === $key) {
                    $problems[$key] = "its parent pages lead back to {$key} itself";

                    break;
                }
                $seen[$cursor] = true;
                if (isset($stated[$cursor]) || isset($topLevel[$cursor])) {
                    $cursor = $stated[$cursor] ?? null;

                    continue;
                }
                $id = $this->matched[$cursor] ?? $this->liveTarget($cursor);
                $up = $id === null ? null : ($links[$id] ?? null);
                $cursor = $up === null ? null : ($this->entryKeys[$up] ?? null);
            }
        }

        return $problems;
    }

    /**
     * The live parent links from these pages upward (page id → parent id), in one read: a walk up
     * the parent column bounded at depth 32, so a loop in the data ends. Pages in the trash have no link.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function parentLinks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $siteId = $this->site->id();
        $up = 'with recursive up(id, parent_id, depth) as (select p.id, p.parent_id, 0 from entries p where p.site_id = ? and p.id = any(?::bigint[]) '
            .'union all select p.id, p.parent_id, up.depth + 1 from entries p join up on p.id = up.parent_id where p.site_id = ? and up.depth < 32) select up.id from up';
        $pages = $this->locked(Entry::query()->where('type', 'page')->whereNotNull('parent_id')->whereRaw("entries.id in ({$up})", [$siteId, '{'.implode(',', array_unique($ids)).'}', $siteId]))
            ->get(['id', 'parent_id']);

        $links = [];
        foreach ($pages as $page) {
            $links[$page->id] = Cast::int($page->parent_id);
        }

        return $links;
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
        $row = $this->locked(SiteSetting::query()->where('group_name', $group)->where('key', $name))->first(['group_name', 'key', 'value']);
        $live = $row === null ? null : Cast::arr($row->value);
        $managed = $item->managedKeys();

        $current = $live === null ? null : self::settingState($live, $managed);
        $desired = [];
        foreach (Cast::arr($item->data['value'] ?? null) as $key => $value) {
            if ($managed !== null && ! in_array($key, $managed, true)) {
                continue;
            }
            if (PackValidator::isMediaRef($value)) {
                $slot = $this->slots[$value['media']] ?? null;
                if ($slot === null) {
                    $this->warnings[] = "{$item->key}: {$this->unresolved($value['media'])}; '{$key}' keeps its live value.";
                    if ($current !== null && array_key_exists($key, $current)) {
                        $desired[$key] = $current[$key];
                    }

                    continue;
                }
                $value = $this->urls->url($slot['key']);
            }
            $desired[$key] = $value;
        }

        return $this->decide($context, $item, $current, $this->asTheAdminStores($item, $desired, $managed, $live), $current === null ? null : $item->anchor(), null);
    }

    /**
     * The value in the shape the admin's own save stores it, so a pack-written setting and a
     * staff-written one read back the same: the heading decoration with every field filled in, the
     * floating contact as its form stores it (trimmed, built-in channels added) and a block slot's
     * text through the page-body sanitizer. When the item owns only some keys, the heading
     * decoration is checked as the whole it becomes once merged with the live value (the admin
     * refuses a custom colour without its hex), and only the owned keys are kept. Other settings
     * are written as given.
     *
     * @param  array<string, mixed>  $desired
     * @param  list<string>|null  $managed
     * @param  array<array-key, mixed>|null  $live
     * @return array<string, mixed>
     *
     * @throws PackRejected the merged heading decoration is one the admin would refuse
     */
    private function asTheAdminStores(PackItem $item, array $desired, ?array $managed, ?array $live): array
    {
        if ($item->key === 'setting:theme.heading_decor' || $item->key === 'setting:storefront.floating_contact') {
            $whole = $managed === null ? $desired : array_replace($live ?? [], $desired);
            if ($item->key === 'setting:theme.heading_decor') {
                try {
                    $stored = HeadingDecorInput::checked(array_combine(array_map('strval', array_keys($whole)), $whole)) ?? $whole;
                } catch (SettingsChangeRejected $e) {
                    throw new PackRejected("Refused: {$item->key} would leave the site's heading decoration with '{$e->field}' not accepted ({$e->reason}) once merged with its current value.");
                }
            } else {
                $stored = FloatingContactInput::stored($whole, $this->locales);
            }

            /** @var array<string, mixed> $stored */
            return $managed === null ? $stored : array_intersect_key($stored, array_flip($managed));
        }
        if ($item->key === 'setting:storefront.layout_blocks') {
            foreach ($desired as $slot => $perLocale) {
                $cleaned = [];
                foreach (Cast::arr($perLocale) as $locale => $text) {
                    $clean = SanitizedPageBody::cleanBody(['blocks' => Cast::arr(Cast::arr($text)['blocks'] ?? null)])['blocks'];
                    $cleaned[$locale] = ['blocks' => is_array($clean) ? array_values($clean) : []];
                }
                $desired[$slot] = $cleaned;
            }
        }

        return $desired;
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
        $profile = $this->locked(SiteProfile::query())->first();
        if ($profile === null) {
            return null;
        }

        $state = [];
        foreach ($fields as $field) {
            $value = $profile->getAttribute($field);
            $state[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        $translations = $this->locked($profile->translations()->whereIn('locale', $this->locales))->get();
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

    /** @param  array<string, PlanItem>  $entries  the plan's page and post items */
    private function redirectItem(PackItem $item, PlanContext $context, array $entries): PlanItem
    {
        [$locale, $from] = explode(':', $item->anchor(), 2);
        $redirect = $this->locked(Redirect::query()->where('locale', $locale)->where('from_path', $from))->first(['to_path', 'status_code']);
        $current = $redirect === null ? null : ['to' => $redirect->to_path, 'status' => $redirect->status_code];
        $desired = ['to' => Cast::string($item->data['to'] ?? null), 'status' => Cast::int($item->data['status'] ?? 301, 301)];
        $route = SiteRoute::query()->where('locale', $locale)->where('path', $from)->first(['entry_id', 'taxonomy_id', 'product_id']);
        $live = $route !== null && $this->stillServed($route, $locale, $entries);

        return $this->decide($context, $item, $current, $desired, $current === null ? null : $from, null,
            conflict: $live ? "{$from} is a live address; a redirect would hide it" : null);
    }

    /**
     * Whether an address still shows its content once this load is applied: the storefront follows a
     * redirect only where it does not (RouteResolver). A page or post counts while it and its translation
     * stay published, as the plan writes it, or as it is live when the plan leaves it alone; so a post the
     * same load unpublishes can hand its address to a redirect. A category or product address always counts.
     *
     * @param  array<string, PlanItem>  $entries
     */
    private function stillServed(SiteRoute $route, string $locale, array $entries): bool
    {
        if ($route->entry_id === null) {
            return true;
        }

        foreach ($entries as $planned) {
            if ($planned->recordId === $route->entry_id) {
                $state = ($planned->writes() ? $planned->desired : $planned->current) ?? [];
                $translation = Cast::arr(Cast::arr($state['translations'] ?? null)[$locale] ?? null);
                if (($state['status'] ?? null) !== 'published') {
                    return false;
                }

                // a language the pack gives no text keeps its live translation as it is
                return $translation === []
                    ? EntryTranslation::query()->where('entry_id', $route->entry_id)->where('locale', $locale)->where('status', 'published')->exists()
                    : ($translation['status'] ?? null) === 'published';
            }
        }

        return Entry::query()->whereKey($route->entry_id)->where('status', 'published')
            ->whereHas('translations', fn (Builder $query) => $query->where('locale', $locale)->where('status', 'published'))
            ->exists();
    }

    // ---------------------------------------------------------------- forms

    /**
     * Each form item against the site's form of that key, two queries for all of them. The state is
     * the fields the pack names, each with what the pack manages of it — a platform field its place
     * (and its example when the pack gives one), a field the pack adds all of it — plus the thank-you
     * page by its key (the form stores the page's ulid). A language the pack gives no label or
     * example keeps its live text. A form the site does not have, a platform field it lacks or a
     * thank-you page that is neither in the pack nor on the site is a conflict: forms and their
     * platform fields come with the site (EnsureContactForm), the loader never invents them.
     *
     * @return array<string, PlanItem>
     */
    private function formItems(Pack $pack, PlanContext $context): array
    {
        $items = $pack->ofKind('form');
        if ($items === []) {
            return [];
        }

        $forms = $this->locked(Form::query()->whereIn('key', array_map(fn (PackItem $item): string => $item->anchor(), $items)))
            ->get(['id', 'ulid', 'key', 'settings'])->keyBy('key');
        /** @var array<int, array<string, FormField>> $fields form id → field key → field */
        $fields = [];
        if ($forms->isNotEmpty()) {
            $rows = $this->locked(FormField::query()->whereIn('form_id', $forms->pluck('id')->all()))
                ->get(['id', 'form_id', 'key', 'type', 'label', 'is_required', 'options', 'sort_order']);
            foreach ($rows as $row) {
                $fields[$row->form_id][$row->key] = $row;
            }
        }

        $targets = [];
        foreach ($items as $item) {
            $target = Cast::arr($item->data['settings'] ?? null)['redirect_entry'] ?? null;
            if (is_string($target)) {
                $targets[] = $target;
            }
        }
        $this->rememberLiveTargets(array_values(array_diff(array_unique($targets), $this->knownTargets($pack))));
        $known = $this->knownTargets($pack);
        $stored = [];
        foreach ($forms as $form) {
            $ulid = Cast::arr($form->settings)['redirect_entry'] ?? null;
            if (is_string($ulid) && $ulid !== '') {
                $stored[] = $ulid;
            }
        }
        $pages = $stored === [] ? [] : Entry::query()->where('type', 'page')->whereIn('ulid', array_values(array_unique($stored)))->pluck('id', 'ulid')->all();
        $this->rememberEntryKeys(array_map(fn (mixed $id): int => Cast::int($id), array_values($pages)));

        $planned = [];
        foreach ($items as $item) {
            $form = $forms->get($item->anchor());
            $packFields = array_values(array_map(fn (mixed $field): array => Cast::arr($field), Cast::arr($item->data['fields'] ?? null)));
            if (! $form instanceof Form) {
                $planned[$item->key] = $this->decide($context, $item, null, $this->desiredForm($item, $packFields, []), null, null,
                    conflict: "the site has no form '{$item->anchor()}'; forms come with the site, the loader never creates one");

                continue;
            }
            $this->matched[$item->key] = $form->id;

            $live = $fields[$form->id] ?? [];
            $current = ['fields' => []];
            $problems = [];
            foreach ($packFields as $field) {
                $key = Cast::string($field['key'] ?? null);
                $row = $live[$key] ?? null;
                $current['fields'][$key] = $row === null ? null : self::fieldState($field, $row);
                if ($row === null && self::isPlatformField($key)) {
                    $problems[] = $key;
                }
            }
            $settings = Cast::arr($item->data['settings'] ?? null);
            if (array_key_exists('redirect_entry', $settings)) {
                $ulid = Cast::arr($form->settings)['redirect_entry'] ?? null;
                $id = is_string($ulid) ? ($pages[$ulid] ?? null) : null;
                $current['redirect_entry'] = $id === null ? $ulid : ($this->entryKeys[Cast::int($id)] ?? $ulid);
            }

            $conflict = null;
            $target = Cast::stringOrNull($settings['redirect_entry'] ?? null);
            if ($problems !== []) {
                $conflict = "the site's form has no platform field ".implode(', ', $problems).' (forms:sync-contact-fields restores it); the loader places platform fields, never creates them';
            } elseif ($target !== null && ! in_array($target, $known, true)) {
                $conflict = "the thank-you page {$target} is neither in the pack nor on the site";
            }

            $notes = [];
            foreach (array_diff(array_keys($live), array_keys($current['fields'])) as $other) {
                $notes[] = "field {$other} is not in the pack and keeps its place (".Cast::int($live[$other]->getAttribute('sort_order')).')';
            }

            $planned[$item->key] = $this->decide($context, $item, $current, $this->desiredForm($item, $packFields, $live), $form->ulid, $form->id, $notes, conflict: $conflict);
        }

        return $planned;
    }

    /**
     * What the pack makes of the form's fields and settings, in the shape fieldState() reads them.
     *
     * @param  list<array<array-key, mixed>>  $packFields
     * @param  array<string, FormField>  $live
     * @return array<string, mixed>
     */
    private function desiredForm(PackItem $item, array $packFields, array $live): array
    {
        $desired = ['fields' => []];
        foreach ($packFields as $field) {
            $key = Cast::string($field['key'] ?? null);
            $row = $live[$key] ?? null;
            $state = ['sort_order' => Cast::int($field['sort_order'] ?? null)];
            if (! self::isPlatformField($key) && $key !== 'consent') {
                $state['type'] = Cast::string($field['type'] ?? null);
                $state['label'] = self::localeTexts($row?->label, $field['label'] ?? null);
                $state['is_required'] = ($field['is_required'] ?? false) === true;
                $state['choices'] = self::choiceState(Cast::arr($field['choices'] ?? null));
            }
            if (array_key_exists('placeholder', $field)) {
                $state['placeholder'] = self::localeTexts(Cast::arr($row?->options)['placeholder'] ?? null, $field['placeholder']);
            }
            $desired['fields'][$key] = $state;
        }
        $settings = Cast::arr($item->data['settings'] ?? null);
        if (array_key_exists('redirect_entry', $settings)) {
            $desired['redirect_entry'] = Cast::string($settings['redirect_entry']);
        }

        return $desired;
    }

    /**
     * One live field as the pack's entry for it manages it (see desiredForm()).
     *
     * @param  array<array-key, mixed>  $field
     * @return array<string, mixed>
     */
    public static function fieldState(array $field, FormField $row): array
    {
        $key = Cast::string($field['key'] ?? null);
        $options = Cast::arr($row->getAttribute('options'));
        $state = ['sort_order' => Cast::int($row->getAttribute('sort_order'))];
        if (! self::isPlatformField($key) && $key !== 'consent') {
            $state['type'] = $row->type;
            $state['label'] = self::localeTexts($row->label, null);
            $state['is_required'] = $row->is_required;
            $state['choices'] = self::choiceState(Cast::arr($options['choices'] ?? null));
        }
        if (array_key_exists('placeholder', $field)) {
            $state['placeholder'] = self::localeTexts($options['placeholder'] ?? null, null);
        }

        return $state;
    }

    private static function isPlatformField(string $key): bool
    {
        return in_array($key, PackValidator::FORM_PLATFORM_FIELDS, true);
    }

    /**
     * A language map of texts: the live one with the pack's languages written over it.
     *
     * @return array<string, string>
     */
    private static function localeTexts(mixed $live, mixed $pack): array
    {
        $texts = [];
        foreach (array_replace(Cast::arr($live), Cast::arr($pack)) as $locale => $text) {
            $texts[(string) $locale] = Cast::string($text);
        }
        ksort($texts, SORT_STRING);

        return $texts;
    }

    /**
     * Choices as the storefront reads them (FormFieldTypes::choices): value and label per language,
     * a choice without a value or with a value already listed dropped.
     *
     * @param  array<array-key, mixed>  $choices
     * @return list<array{value: string, label: array<string, string>}>
     */
    private static function choiceState(array $choices): array
    {
        $state = [];
        foreach ($choices as $choice) {
            $choice = Cast::arr($choice);
            $value = Cast::string($choice['value'] ?? null);
            if ($value !== '' && ! isset($state[$value])) {
                $state[$value] = ['value' => $value, 'label' => self::localeTexts($choice['label'] ?? null, null)];
            }
        }

        return array_values($state);
    }

    // ---------------------------------------------------------------- addresses

    /**
     * Every address the load would write — each category, page, post and product that writes, in
     * every pack language it has (posts and products only where the platform routes them: published,
     * or already routed), plus the published posts and products filed under a category whose slug or
     * parent changes, which the category write carries along — composed the way PathBuilder composes
     * it, then checked in one routes query per batch. An address that belongs to something else, or
     * that two items of this load both claim, makes the claiming item a conflict in the dry run. A
     * moving category also gets a scope: the hash of the rows it carries along, so a post filed
     * under it between the dry run and --apply changes the fingerprint.
     *
     * @param  list<PlanItem>  $items
     * @return list<PlanItem>
     */
    private function addresses(array $items): array
    {
        $tree = $this->plannedTree($items);
        $bindings = $this->sectionBindings();
        $writing = array_values(array_filter($items, fn (PlanItem $planned): bool => $planned->writes()));

        $routed = $this->routedLocales($writing);
        $pages = $this->livePages($writing);

        /** @var list<array{key: string, locale: string, path: string, column: string, id: int|null, what: string}> $claims */
        $claims = [];
        foreach ($writing as $planned) {
            $kind = $planned->item->kind;
            $column = match ($kind) {
                'taxonomy' => 'taxonomy_id',
                'product' => 'product_id',
                'page', 'post' => 'entry_id',
                default => null,
            };
            if ($column === null) {
                continue;
            }
            $published = ($planned->desired['status'] ?? null) === 'published';
            $primary = Cast::strings($planned->desired['categories'] ?? [])[0] ?? null;
            foreach ($this->locales as $locale) {
                $row = Cast::arr(Cast::arr($planned->desired['translations'] ?? null)[$locale] ?? null);
                if ($row === []) {
                    continue;
                }
                $isRouted = $planned->recordId !== null && isset($routed[$column][$planned->recordId][$locale]);
                $path = match ($kind) {
                    'taxonomy' => $this->paths->forTaxonomy(Cast::string($row['slug'] ?? null)),
                    'page' => $this->pagePath($planned, $pages, $locale),
                    'post', 'product' => $published || $isRouted
                        ? $this->itemPath($kind, $primary, $tree, $bindings, $locale, Cast::string($row['slug'] ?? null))
                        : null,
                };
                if ($path !== null) {
                    $this->plannedPaths[$planned->item->key][$locale] = $path;
                    $claims[] = ['key' => $planned->item->key, 'locale' => $locale, 'path' => $path, 'column' => $column, 'id' => $planned->recordId, 'what' => $planned->item->key];
                }
            }
        }

        [$branchClaims, $moves, $scopes] = $this->branchMoves($writing, $tree, $bindings);
        array_push($claims, ...$branchClaims);

        $conflicts = $this->claimConflicts($claims);

        $out = [];
        foreach ($items as $planned) {
            $key = $planned->item->key;
            if (isset($conflicts[$key])) {
                $out[] = $planned->asConflict($conflicts[$key]);

                continue;
            }
            if (isset($scopes[$key])) {
                $notes = [];
                $old = Cast::arr(Cast::arr($planned->current['translations'] ?? null)[$this->locale] ?? null)['slug'] ?? null;
                $new = $this->plannedPaths[$key][$this->locale] ?? null;
                if (is_string($old) && $new !== null && $this->paths->forTaxonomy($old) !== $new) {
                    $count = $moves[$key] ?? ['post' => 0, 'product' => 0];
                    $notes[] = "URL {$this->paths->forTaxonomy($old)} → {$new} (301 + {$count['post']} posts".($count['product'] > 0 ? ", {$count['product']} products" : '').')';
                }
                $planned = $planned->carrying($notes, $scopes[$key]);
            }
            $out[] = $planned;
        }

        return $out;
    }

    /**
     * The category tree as the load leaves it: every live category, with the writing pack items'
     * parent and slugs in their place. Keyed by category key.
     *
     * @param  list<PlanItem>  $items
     * @return array<string, array{id: int|null, parent: string|null, slugs: array<string, string>}>
     */
    private function plannedTree(array $items): array
    {
        $tree = [];
        foreach ($this->taxonomies as $id => $row) {
            $slugs = [];
            foreach ($row['tr'] as $locale => $translation) {
                $slugs[$locale] = $translation['slug'];
            }
            $tree[$this->taxonomyKey($id)] = ['id' => $id, 'parent' => $row['parent_id'] === null ? null : $this->taxonomyKey($row['parent_id']), 'slugs' => $slugs];
        }
        foreach ($items as $planned) {
            if ($planned->item->kind !== 'taxonomy' || ! $planned->writes()) {
                continue;
            }
            $slugs = $tree[$planned->item->key]['slugs'] ?? [];
            foreach (Cast::arr($planned->desired['translations'] ?? null) as $locale => $row) {
                $slug = Cast::arr($row)['slug'] ?? null;
                if (is_string($slug)) {
                    $slugs[(string) $locale] = $slug;
                }
            }
            $tree[$planned->item->key] = ['id' => $planned->recordId, 'parent' => Cast::stringOrNull($planned->desired['parent'] ?? null), 'slugs' => $slugs];
        }

        return $tree;
    }

    /**
     * The section built on each category (the first by display order), as SectionResolver reads it.
     *
     * @return array<int, array{key: string, type: string}>
     */
    private function sectionBindings(): array
    {
        $bindings = [];
        foreach (ContentSection::query()->whereNotNull('taxonomy_id')->orderBy('sort_order')->orderBy('id')->get(['taxonomy_id', 'key', 'entry_type']) as $section) {
            $bindings[Cast::int($section->taxonomy_id)] ??= ['key' => Cast::string($section->key), 'type' => $section->entry_type === 'page' ? 'pages' : 'posts'];
        }

        return $bindings;
    }

    /**
     * The languages each writing post and product is already routed in.
     *
     * @param  list<PlanItem>  $writing
     * @return array<string, array<int, array<string, true>>> column → id → locale
     */
    private function routedLocales(array $writing): array
    {
        $ids = ['entry_id' => [], 'product_id' => []];
        foreach ($writing as $planned) {
            if ($planned->recordId !== null && in_array($planned->item->kind, ['post', 'product'], true)) {
                $ids[$planned->item->kind === 'post' ? 'entry_id' : 'product_id'][] = $planned->recordId;
            }
        }
        $routed = [];
        foreach ($ids as $column => $list) {
            if ($list === []) {
                continue;
            }
            foreach (SiteRoute::query()->whereIn($column, $list)->get([$column, 'locale']) as $route) {
                $routed[$column][Cast::int($route->{$column})][$route->locale] = true;
            }
        }

        return $routed;
    }

    /**
     * The live pages the load writes, with their slugs (a page keeps its live slug).
     *
     * @param  list<PlanItem>  $writing
     * @return array<int, Entry>
     */
    private function livePages(array $writing): array
    {
        $ids = [];
        foreach ($writing as $planned) {
            if ($planned->item->kind === 'page' && $planned->recordId !== null) {
                $ids[] = $planned->recordId;
            }
        }

        $pages = [];
        if ($ids !== []) {
            foreach (Entry::withTrashed()->with(['translations:id,entry_id,locale,slug', 'parent.translations:id,entry_id,locale,slug'])->whereIn('id', $ids)->get(['id', 'type', 'parent_id']) as $page) {
                $pages[$page->id] = $page;
            }
        }

        return $pages;
    }

    /** @param array<int, Entry> $pages */
    private function pagePath(PlanItem $planned, array $pages, string $locale): string
    {
        $page = $planned->recordId === null ? null : ($pages[$planned->recordId] ?? null);
        $slug = $page?->translationFor($locale)?->slug;
        $slug ??= trim($planned->item->anchor(), '/') === '' ? PathBuilder::HOME_SLUG : trim($planned->item->anchor(), '/');
        if ($page instanceof Entry) {
            return $this->paths->forEntry($page, $locale, $slug);
        }
        if ($slug === PathBuilder::HOME_SLUG) {
            return '/';
        }
        $base = $this->permalinks->pattern('pages', 'pages') === 'base' ? $this->permalinks->base('pages') : null;

        return ($base === null ? '' : '/'.$base).'/'.$slug;   // a new page has no parent page
    }

    /**
     * A post's or product's address from its primary category in the planned tree — PathBuilder's
     * presets (flat, category, category_path, base) and SectionResolver's section walk.
     *
     * @param  array<string, array{id: int|null, parent: string|null, slugs: array<string, string>}>  $tree
     * @param  array<int, array{key: string, type: string}>  $bindings
     */
    private function itemPath(string $kind, ?string $primary, array $tree, array $bindings, string $locale, string $slug): string
    {
        if ($kind === 'product') {
            $section = 'products';
            $pattern = $this->permalinks->pattern('products', 'products');
        } else {
            $bound = null;
            $seen = [];
            for ($cursor = $primary; $cursor !== null && isset($tree[$cursor]) && ! isset($seen[$cursor]); $cursor = $tree[$cursor]['parent']) {
                $seen[$cursor] = true;
                $id = $tree[$cursor]['id'];
                if ($id !== null && isset($bindings[$id])) {
                    $bound = $bindings[$id];
                    break;
                }
            }
            $section = $bound['key'] ?? null;
            $pattern = $bound === null ? PermalinkConfig::DEFAULT_PATTERN : $this->permalinks->pattern($bound['key'], $bound['type']);
        }

        $chain = [];
        $seen = [];
        for ($cursor = $primary; $pattern === 'category_path' && $cursor !== null && isset($tree[$cursor]) && ! isset($seen[$cursor]); $cursor = $tree[$cursor]['parent']) {
            $seen[$cursor] = true;
            $segment = trim($tree[$cursor]['slugs'][$locale] ?? '', '/');
            if ($segment !== '') {
                array_unshift($chain, $segment);
            }
        }
        $base = $section === null ? null : $this->permalinks->base($section);

        $prefix = match ($pattern) {
            'category' => $primary !== null && isset($tree[$primary]['slugs'][$locale]) ? '/'.trim($tree[$primary]['slugs'][$locale], '/') : '',
            'category_path' => $chain === [] ? '' : '/'.implode('/', $chain),
            'base' => $base === null ? '' : '/'.$base,
            default => '',
        };

        return $prefix.'/'.trim($slug, '/');
    }

    /**
     * The published posts and products filed (primary) under a category whose address changes,
     * other than the pack's own writing items: where each one moves to (a claim), how many move per
     * category, and the hash of each moving category's rows. It takes exactly the rows the Applier's
     * relink re-syncs: every language the item is routed in (not only the pack's), and only the kinds
     * CategoryRelinker re-syncs for the changes the category goes through (PlanItem::taxonomyChanges).
     *
     * @param  list<PlanItem>  $writing
     * @param  array<string, array{id: int|null, parent: string|null, slugs: array<string, string>}>  $tree
     * @param  array<int, array{key: string, type: string}>  $bindings
     * @return array{0: list<array{key: string, locale: string, path: string, column: string, id: int|null, what: string}>, 1: array<string, array{post: int, product: int}>, 2: array<string, string>}
     */
    private function branchMoves(array $writing, array $tree, array $bindings): array
    {
        $moving = [];   // taxonomy id → key
        $changes = [];  // key → what the write does to the addresses under it
        $own = ['post' => [], 'product' => []];
        foreach ($writing as $planned) {
            $change = $planned->item->kind === 'taxonomy' && $planned->recordId !== null ? PlanItem::taxonomyChanges($planned->current, $planned->desired) : [];
            if ($change !== []) {
                $moving[$planned->recordId] = $planned->item->key;
                $changes[$planned->item->key] = $change;
            }
            if (in_array($planned->item->kind, ['post', 'product'], true) && $planned->recordId !== null) {
                $own[$planned->item->kind][$planned->recordId] = true;
            }
        }
        if ($moving === []) {
            return [[], [], []];
        }

        // Every category under a moving one, with the moving categories above it (nearest first).
        $children = [];
        foreach ($this->taxonomies as $id => $row) {
            if ($row['parent_id'] !== null) {
                $children[$row['parent_id']][] = $id;
            }
        }
        $owners = [];
        foreach (array_keys($moving) as $root) {
            $stack = [$root];
            while ($stack !== []) {
                $id = array_pop($stack);
                if (isset($owners[$id]) && in_array($moving[$root], $owners[$id], true)) {
                    continue;
                }
                $owners[$id][] = $moving[$root];
                array_push($stack, ...($children[$id] ?? []));
            }
        }

        $rows = [];
        foreach (self::BRANCH_KINDS as $kind => [$pivot, $column]) {
            // The categories whose moving owners make the relink re-sync this kind.
            $followed = array_keys(array_filter($owners, fn (array $keys): bool => $this->relinker->resyncs(
                $pivot,
                ...array_merge(...array_map(fn (string $key): array => $changes[$key], $keys)),
            )));
            foreach (array_chunk($followed, 1000) as $chunk) {
                $query = $this->branchItems($kind, $chunk)->orderBy('it.id')->orderBy('r.locale');
                if ($this->lock) {
                    $query->lock(self::LOCK);
                }
                foreach ($query->get(['it.id', 'pv.taxonomy_id', 'r.id as route_id', 'r.locale', 'r.path', 'tr.slug']) as $row) {
                    $rows[] = ['kind' => $kind, 'column' => $column] + Cast::arr((array) $row);
                }
            }
        }

        $claims = [];
        $moves = [];
        $scoped = array_fill_keys(array_values($moving), []);
        foreach ($rows as $row) {
            $id = Cast::int($row['id']);
            $taxonomyId = Cast::int($row['taxonomy_id']);
            $locale = Cast::string($row['locale']);
            $path = Cast::string($row['path']);
            foreach ($owners[$taxonomyId] ?? [] as $key) {
                $scoped[$key][] = [$row['kind'], $id, $locale, $path, Cast::string($row['slug'])];
                $this->branchRoutes[$key][] = Cast::int($row['route_id']);
            }
            if (isset($own[$row['kind']][$id])) {
                continue;   // a pack item: claimed above with its own new address
            }
            $new = $this->itemPath($row['kind'], $this->taxonomyKey($taxonomyId), $tree, $bindings, $locale, Cast::string($row['slug']));
            if ($new === $path) {
                continue;
            }
            $key = ($owners[$taxonomyId] ?? [''])[0];
            $moves[$key] ??= ['post' => 0, 'product' => 0];
            $moves[$key][$row['kind']]++;
            $claims[] = ['key' => $key, 'locale' => $locale, 'path' => $new, 'column' => Cast::string($row['column']), 'id' => $id, 'what' => "a {$row['kind']} it moves from {$path}"];
            foreach ($owners[$taxonomyId] ?? [] as $owner) {
                $this->carriedMoves[$owner][] = ['column' => Cast::string($row['column']), 'id' => $id, 'locale' => $locale, 'from' => $path, 'to' => $new];
            }
        }

        $scopes = [];
        foreach ($scoped as $key => $list) {
            $scopes[$key] = CanonicalHash::of($list);
        }

        return [$claims, $moves, $scopes];
    }

    /**
     * The routes a category relink about to run with these changes would re-sync under the branch,
     * but which the last plan did not carry along for that category: a post or product published or
     * filed there after the plan was made. Its old address is not in the backup and its new one was
     * never checked, so the caller must not relink. One query.
     *
     * @param  list<int>  $branch  the category and every category under it, as the tree stands now
     * @return list<int> route ids
     */
    public function unplannedBranchRoutes(string $key, array $branch, CategoryChange ...$changes): array
    {
        $query = null;
        foreach (self::BRANCH_KINDS as $kind => [$pivot]) {
            if ($branch === [] || ! $this->relinker->resyncs($pivot, ...$changes)) {
                continue;
            }
            $part = $this->branchItems($kind, $branch)->select('r.id');
            $query = $query === null ? $part : $query->unionAll($part);
        }
        if ($query === null) {
            return [];
        }

        $planned = array_flip($this->branchRoutes[$key] ?? []);

        return array_values(array_filter(
            array_map(Cast::int(...), $query->pluck('id')->all()),
            fn (int $id): bool => ! isset($planned[$id]),
        ));
    }

    /**
     * The published posts (or products) filed primarily under these categories, one row per
     * language they are routed in: what CategoryRelinker re-syncs for that kind.
     *
     * @param  'post'|'product'  $kind
     * @param  list<int>  $taxonomyIds
     */
    private function branchItems(string $kind, array $taxonomyIds): QueryBuilder
    {
        [$pivot, $column, $table, $translations] = self::BRANCH_KINDS[$kind];
        $query = DB::table("{$pivot} as pv")
            ->join("{$table} as it", 'it.id', '=', "pv.{$column}")
            ->join('routes as r', "r.{$column}", '=', 'it.id')
            ->join("{$translations} as tr", fn (JoinClause $join) => $join->on("tr.{$column}", '=', 'it.id')->on('tr.locale', '=', 'r.locale'))
            ->where('pv.site_id', $this->site->id())
            ->where('pv.position', 0)
            ->whereIn('pv.taxonomy_id', $taxonomyIds)
            ->where('it.status', 'published')
            ->whereNull('it.deleted_at');
        if ($kind === 'post') {
            $query->where('it.type', 'post');
        }

        return $query;
    }

    /**
     * Which claiming items conflict, and why: the address belongs to another record, or two claims
     * of this load want it.
     *
     * @param  list<array{key: string, locale: string, path: string, column: string, id: int|null, what: string}>  $claims
     * @return array<string, string> pack key → reason
     */
    private function claimConflicts(array $claims): array
    {
        $conflicts = [];
        $byAddress = [];
        foreach ($claims as $claim) {
            $byAddress[$claim['locale']."\0".$claim['path']][] = $claim;
        }
        foreach ($byAddress as $list) {
            $owners = array_unique(array_map(fn (array $claim): string => $claim['column'].':'.($claim['id'] ?? 'new:'.$claim['key']), $list));
            if (count($owners) < 2) {
                continue;
            }
            foreach ($list as $claim) {
                $others = array_values(array_unique(array_map(fn (array $other): string => $other['what'], array_filter($list, fn (array $other): bool => $other !== $claim))));
                $conflicts[$claim['key']] ??= "the address {$claim['path']} ({$claim['locale']}) is also claimed in this load by ".implode(', ', $others);
            }
        }

        $paths = array_values(array_unique(array_map(fn (array $claim): string => $claim['path'], $claims)));
        $locales = array_values(array_unique(array_map(fn (array $claim): string => $claim['locale'], $claims)));
        $routes = [];
        foreach (array_chunk($paths, 1000) as $chunk) {
            foreach (SiteRoute::query()->whereIn('locale', $locales)->whereIn('path', $chunk)->get(['locale', 'path', 'entry_id', 'taxonomy_id', 'product_id']) as $route) {
                $routes[$route->locale."\0".$route->path] = $route;
            }
        }
        foreach ($claims as $claim) {
            $route = $routes[$claim['locale']."\0".$claim['path']] ?? null;
            if (! $route instanceof SiteRoute || ($claim['id'] !== null && $route->getAttribute($claim['column']) === $claim['id'])) {
                continue;
            }
            $what = $claim['what'] === $claim['key'] ? '' : " (for {$claim['what']})";
            $conflicts[$claim['key']] ??= "the address {$claim['path']} ({$claim['locale']}) already belongs to another page or item{$what}";
        }

        return $conflicts;
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

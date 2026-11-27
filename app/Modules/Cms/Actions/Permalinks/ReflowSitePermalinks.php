<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Permalinks;

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\PermalinkItem;
use App\Modules\Cms\Routing\PermalinkKinds;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\PermalinkConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves the routes of the current site's published items to the address their section's permalink
 * preset gives them, through PathBuilder (the one URL builder) and RouteSync (each move records a
 * 301 from the old address, chains collapsed). Items are pages and posts (entries) and the kinds
 * other modules register (PermalinkKinds: Catalog's products).
 *
 * Two callers:
 *   - chunk(): one keyset chunk of one kind, for the background run of a change of preset
 *     (ReflowPermalinks). It composes the sections being changed from their staged preset
 *     (PermalinkConfig::withTargets) while the live preset keeps serving everything else. An item
 *     already where it belongs is left untouched, so a chunk run twice writes nothing the second
 *     time (idempotent, resumable from any cursor). A clash — the new address another item holds,
 *     or one of the platform's — gets the next free slug (`-2`, `-3`…) stored on the item, then the
 *     move and its 301; without `suffix` it is reported instead.
 *   - handle(): the operator command (permalinks:reflow): every section back to flat in one run,
 *     clashes reported, never suffixed; `dryRun` runs the same writes and rolls them back.
 *
 * Runs inside the caller's Tenancy::enter() (an explicit transaction with SET LOCAL, RLS-scoped).
 */
final readonly class ReflowSitePermalinks
{
    /** The kind name of pages and posts; the other kinds are PermalinkKinds' keys. */
    public const string ENTRIES = 'entry';

    /** Items per chunk of the background run. */
    public const int CHUNK = 500;

    /** Parent levels loaded with a page, so a nested address composes without a query per level. */
    private const int PARENT_LEVELS = 4;

    public function __construct(
        private RouteSync $routes,
        private PathBuilder $paths,
        private PermalinkConfig $permalinks,
        private SectionResolver $sections,
        private PermalinkKinds $kinds,
    ) {}

    /**
     * Every section back to flat and every route moved to its flat address (the operator command).
     *
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    public function handle(bool $dryRun = false): array
    {
        if (! $dryRun) {
            return $this->flattenAll();
        }

        $summary = [];
        try {
            DB::transaction(function () use (&$summary): never {
                $summary = $this->flattenAll();

                throw new RuntimeException(self::class.':dry-run');
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== self::class.':dry-run') {
                throw $e;
            }
            // expected: every write of the preview pass above has just been rolled back
        }
        // the pass cached the flat presets it wrote; those are gone, so read the real ones again
        $this->permalinks->flush();

        return $summary;
    }

    /** @return list<string> the kinds a full run walks, entries first */
    public function kinds(): array
    {
        return [self::ENTRIES, ...array_keys($this->kinds->all())];
    }

    /**
     * One chunk of a run: the published items of $kind whose id follows $after (at most $limit ids
     * with a route), each route moved to the address its section's preset gives — the preset of
     * $targets for the sections named there. `last` is the cursor for the next chunk, null when
     * this was the last one.
     *
     * @param  list<string>|null  $sections  only items of these sections (null: every item)
     * @param  array<string, array{pattern: string, base: string|null}>  $targets
     * @return array{last: int|null, rows: list<array{type: string, title: string, locale: string, old: string, new: string, status: string, section: string|null}>}
     */
    public function chunk(string $kind, ?array $sections, array $targets, int $after, int $limit = self::CHUNK, bool $suffix = true): array
    {
        $column = $this->column($kind);
        $ids = array_values(array_map(static fn (mixed $id): int => Cast::int($id), SiteRoute::query()
            ->whereNotNull($column)->where($column, '>', $after)
            ->distinct()->orderBy($column)->limit($limit)->pluck($column)->all()));

        $rows = [];
        if ($ids !== []) {
            $rows = $this->permalinks->withTargets($targets, function () use ($kind, $ids, $sections, $suffix): array {
                $rows = [];
                foreach ($this->items($kind, $ids) as ['section' => $section, 'item' => $item]) {
                    if ($sections !== null && ! in_array($section, $sections, true)) {
                        continue;
                    }
                    foreach ($item->routes as $locale => $old) {
                        $row = $this->move($item, $locale, $old, $suffix);
                        if ($row !== null) {
                            $rows[] = $row + ['section' => $section];
                        }
                    }
                }

                return $rows;
            });
        }

        return ['last' => count($ids) < $limit || $ids === [] ? null : $ids[count($ids) - 1], 'rows' => $rows];
    }

    /**
     * The published items of $kind among $ids with the section composing each one's address
     * (pages: `pages`; a post: its section's key, null when it has none; another kind: the
     * section it registered).
     *
     * @param  list<int>  $ids
     * @return list<array{section: string|null, item: PermalinkItem}>
     */
    public function items(string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        if ($kind !== self::ENTRIES) {
            $registered = $this->kinds->all()[$kind] ?? null;
            if ($registered === null) {
                return [];
            }

            return array_map(static fn (PermalinkItem $item): array => ['section' => $registered['section'], 'item' => $item], ($registered['items'])($ids));
        }

        $parents = [];
        $chain = 'parent';
        for ($level = 1; $level <= self::PARENT_LEVELS; $level++) {
            $parents[] = $chain.'.translations';
            $chain .= '.parent';
        }
        $entries = Entry::query()->whereIn('id', $ids)->where('status', 'published')->whereIn('type', ['page', 'post'])
            ->with(['translations', 'routes', ...$parents, ...PathBuilder::primaryCategoryRelations()])
            ->orderBy('id')->get();
        $this->sections->prime($entries);

        $out = [];
        foreach ($entries as $entry) {
            $section = $entry->type === 'page' ? 'pages' : ($this->sections->forEntry($entry)['key'] ?? null);
            $out[] = ['section' => $section, 'item' => $this->entryItem($entry)];
        }

        return $out;
    }

    /**
     * Move one route of $item; null when its language has no slug to compose from.
     *
     * @return array{type: string, title: string, locale: string, old: string, new: string, status: string}|null
     */
    private function move(PermalinkItem $item, string $locale, string $old, bool $suffix): ?array
    {
        if (! array_key_exists($locale, $item->slugs)) {
            return null;
        }
        $slug = trim($item->slugs[$locale], '/');
        $row = ['type' => $item->type, 'title' => $item->titles[$locale] ?? '', 'locale' => $locale, 'old' => $old];
        $target = ($item->compose)($locale, $slug);
        if ($target === $old) {
            return $row + ['new' => $old, 'status' => 'unchanged'];
        }

        try {
            return $row + ['new' => ($item->sync)($locale, $slug), 'status' => 'moved'];
        } catch (RouteConflictException) {
            $free = $suffix ? $this->freeSlug($item, $locale, $slug, $target) : null;
            if ($free === null) {
                return $row + ['new' => $old, 'status' => 'conflict'];
            }
        }

        ($item->rename)($locale, $free);

        return $row + ['new' => ($item->sync)($locale, $free), 'status' => 'suffixed'];
    }

    /**
     * The lowest `slug-N` (N from 2) whose address under the same prefix no other item and no page
     * of the platform holds; null when the address is not `{prefix}/{slug}` (the home page).
     */
    private function freeSlug(PermalinkItem $item, string $locale, string $slug, string $target): ?string
    {
        if ($slug === '' || ! str_ends_with($target, '/'.$slug)) {
            return null;
        }
        $prefix = substr($target, 0, strlen($target) - strlen($slug));
        $others = SiteRoute::query()->where('locale', $locale)
            ->where(fn (Builder $query) => $query->whereNull($item->column)->orWhere($item->column, '!=', $item->id));

        $free = Slugger::free($slug, function (string $base) use ($others, $prefix): array {
            $taken = Slugger::takenAmong($others, 'path', $base, $prefix);
            foreach (PathBuilder::RESERVED_PATHS as $reserved) {
                if (str_starts_with($reserved, $prefix)) {
                    $taken[] = substr($reserved, strlen($prefix));
                }
            }

            return $taken;
        });

        return $free === $slug ? null : $free;
    }

    private function entryItem(Entry $entry): PermalinkItem
    {
        $slugs = [];
        $titles = [];
        foreach ($entry->translations as $translation) {
            $slugs[$translation->locale] = (string) $translation->slug;
            $titles[$translation->locale] = (string) $translation->title;
        }
        $routes = [];
        foreach ($entry->routes as $route) {
            $routes[$route->locale] = (string) $route->path;
        }

        return new PermalinkItem(
            type: $entry->type,
            column: 'entry_id',
            id: $entry->id,
            slugs: $slugs,
            titles: $titles,
            routes: $routes,
            compose: fn (string $locale, string $slug): string => $this->paths->forEntry($entry, $locale, $slug),
            sync: fn (string $locale, string $slug): string => $this->routes->sync($entry, $locale, $slug),
            rename: static function (string $locale, string $slug) use ($entry): void {
                $translation = $entry->translations->firstWhere('locale', $locale);
                if ($translation instanceof EntryTranslation) {
                    $translation->update(['slug' => $slug]);
                }
            },
        );
    }

    /** @return literal-string */
    private function column(string $kind): string
    {
        if ($kind === self::ENTRIES) {
            return 'entry_id';
        }
        $column = $this->kinds->all()[$kind]['column'] ?? null;
        if ($column !== 'product_id') {
            throw new RuntimeException("Unknown permalink kind '{$kind}'.");
        }

        return 'product_id';
    }

    /**
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string}>
     */
    private function flattenAll(): array
    {
        $this->permalinks->flattenStored();

        $summary = [];
        foreach ($this->kinds() as $kind) {
            $after = 0;
            do {
                $chunk = $this->chunk($kind, null, [], $after, self::CHUNK, suffix: false);
                foreach ($chunk['rows'] as $row) {
                    unset($row['section']);
                    $summary[] = $row;
                }
                $after = $chunk['last'];
            } while ($after !== null);
        }

        return $summary;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Permalinks;

use App\Modules\Cms\Exceptions\PermalinkMoveFailedException;
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
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

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
 *     move and its 301, when `suffix` is on (the run's settle phase only: during the move the holder
 *     may be an item that moves away later in the same run); without it the item keeps its address
 *     and is reported `conflict`. An address too long for a route is reported `failed` and left.
 *     A chunk opens no savepoint (one per address would pile up subtransactions in a long run, past
 *     what PostgreSQL keeps in memory for the snapshots of every other session): an address that
 *     fails any other way (an error, or a «-2» whose sub-pages find their addresses taken) throws
 *     PermalinkMoveFailedException, the caller's whole transaction rolls back, and the caller runs
 *     the chunk again in a new one with that address skipped (`$skip`: reported `failed` with its
 *     reason, not tried), until the chunk goes through. Every attempt reads its items afresh, so
 *     nothing a rolled-back attempt changed in memory (a «-2» slug) reaches the next.
 *     Runs inside the caller's Tenancy::enter() (an explicit transaction with SET LOCAL, RLS-scoped).
 *   - handle(): the operator command (permalinks:reflow): every section back to flat, clashes
 *     reported, never suffixed. It opens its own transactions, one per chunk, each under the site's
 *     permalink lock; `dryRun` runs the same writes in one transaction (a preview must see every
 *     earlier chunk's moves to report the clashes a real run meets) and rolls it back.
 */
final readonly class ReflowSitePermalinks
{
    /** The kind name of pages and posts; the other kinds are PermalinkKinds' keys. */
    public const string ENTRIES = 'entry';

    /** Items per chunk of the background run. */
    public const int CHUNK = 500;

    /** Parent levels loaded with a page, so a nested address composes without a query per level. */
    private const int PARENT_LEVELS = 4;

    /** The longest address a route stores (routes.path). */
    public const int PATH_MAX = 255;

    /** Why an address could not move: too long for a route, taken with no free slug, or an error. */
    public const string TOO_LONG = 'too_long';

    public const string TAKEN = 'taken';

    public const string ERROR = 'error';

    /** The message that rolls a dry run back. */
    private const string DRY_RUN = self::class.':dry-run';

    public function __construct(
        private RouteSync $routes,
        private PathBuilder $paths,
        private PermalinkConfig $permalinks,
        private SectionResolver $sections,
        private PermalinkKinds $kinds,
        private Tenancy $tenancy,
    ) {}

    /**
     * Every section of $siteId back to flat and every route moved to its flat address (the operator
     * command), chunk by chunk in transactions of its own; outside any transaction.
     *
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null}>
     */
    public function handle(int $siteId, bool $dryRun = false, int $size = self::CHUNK): array
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('permalinks:reflow opens its own transactions, one per chunk: call it outside any transaction.');
        }

        return $dryRun ? $this->preview($siteId, $size) : $this->flatten($siteId, $size);
    }

    /**
     * $skip with the address $failed names added (its cause reported when it was an error), for the
     * next attempt at the chunk that failed.
     *
     * @param  array<string, string>  $skip
     * @return array<string, string>
     */
    public function skipping(array $skip, PermalinkMoveFailedException $failed): array
    {
        if (isset($skip[$failed->route])) {
            throw $failed; // a skipped address is never tried, so this one cannot be the cause
        }
        if ($failed->reason === self::ERROR) {
            report($failed->getPrevious() ?? $failed);
        }
        $skip[$failed->route] = $failed->reason;

        return $skip;
    }

    /** The name of one address of an item in a run's `$skip`: "{column}:{id}:{locale}". */
    public static function route(string $column, int $id, string $locale): string
    {
        return $column.':'.$id.':'.$locale;
    }

    /** @return list<string> the kinds a full run walks, entries first */
    public function kinds(): array
    {
        return [self::ENTRIES, ...array_keys($this->kinds->all())];
    }

    /**
     * Whether a run over $sections has anything to walk in $kind: entries when pages or a post
     * section is among them, another kind when its own section is.
     *
     * @param  list<string>  $sections
     */
    public function walks(string $kind, array $sections): bool
    {
        return $kind === self::ENTRIES ? $this->entryTypes($sections) !== [] : in_array($this->kinds->all()[$kind]['section'] ?? null, $sections, true);
    }

    /**
     * One chunk of a run: the published items of $kind whose id follows $after (at most $limit ids
     * with a route; only pages, or only posts, when $sections names only those), each route moved
     * to the address its section's preset gives — the preset of $targets for the sections named
     * there. `last` is the cursor for the next chunk, null when this was the last one. No savepoint:
     * an address that fails throws PermalinkMoveFailedException for the caller to roll the whole
     * transaction back and run the chunk again with that address in $skip.
     *
     * @param  list<string>|null  $sections  only items of these sections (null: every item)
     * @param  array<string, array{pattern: string, base: string|null}>  $targets
     * @param  array<string, string>  $skip  addresses (route()) an earlier attempt failed on => reason: reported failed, not tried
     * @return array{last: int|null, rows: list<array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null, section: string|null}>}
     */
    public function chunk(string $kind, ?array $sections, array $targets, int $after, int $limit = self::CHUNK, bool $suffix = true, array $skip = []): array
    {
        if ($sections !== null && ! $this->walks($kind, $sections)) {
            return ['last' => null, 'rows' => []];
        }
        $column = $this->column($kind);
        $query = SiteRoute::query()->whereNotNull($column)->where($column, '>', $after);
        if ($kind === self::ENTRIES && $sections !== null && count($types = $this->entryTypes($sections)) === 1) {
            $query->whereIn('entry_id', Entry::query()->select('id')->where('type', $types[0]));
        }
        $ids = array_values(array_map(static fn (mixed $id): int => Cast::int($id), $query
            ->distinct()->orderBy($column)->limit($limit)->pluck($column)->all()));

        $rows = [];
        if ($ids !== []) {
            $rows = $this->permalinks->withTargets($targets, function () use ($kind, $ids, $sections, $suffix, $skip): array {
                $rows = [];
                foreach ($this->items($kind, $ids) as ['section' => $section, 'item' => $item]) {
                    if ($sections !== null && ! in_array($section, $sections, true)) {
                        continue;
                    }
                    foreach ($item->routes as $locale => $old) {
                        $row = $this->moveOrFail($item, $locale, $old, $suffix, $skip);
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
        $this->shareParents($entries);

        $out = [];
        foreach ($entries as $entry) {
            $section = $entry->type === 'page' ? 'pages' : ($this->sections->forEntry($entry)['key'] ?? null);
            $out[] = ['section' => $section, 'item' => $this->entryItem($entry)];
        }

        return $out;
    }

    /**
     * Move one route of $item, or report it failed when an earlier attempt at the chunk failed on it
     * ($skip). Any failure while it moves throws PermalinkMoveFailedException naming it: with no
     * savepoint, what it wrote can only go back with the caller's whole transaction. Null when its
     * language has no slug to compose from.
     *
     * @param  array<string, string>  $skip
     * @return array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null}|null
     */
    private function moveOrFail(PermalinkItem $item, string $locale, string $old, bool $suffix, array $skip): ?array
    {
        if (! array_key_exists($locale, $item->slugs)) {
            return null;
        }
        $route = self::route($item->column, $item->id, $locale);
        if (isset($skip[$route])) {
            return ['type' => $item->type, 'title' => $item->titles[$locale] ?? '', 'locale' => $locale, 'old' => $old, 'new' => $old, 'status' => 'failed', 'reason' => $skip[$route]];
        }

        try {
            return $this->move($item, $locale, $old, $suffix);
        } catch (Throwable $e) {
            // a sub-page following a renamed parent found its address taken (the parent stays too), or an error
            throw new PermalinkMoveFailedException($route, $e instanceof RouteConflictException ? self::TAKEN : self::ERROR, $e);
        }
    }

    /**
     * @return array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null}
     */
    private function move(PermalinkItem $item, string $locale, string $old, bool $suffix): array
    {
        $slug = trim($item->slugs[$locale], '/');
        $row = ['type' => $item->type, 'title' => $item->titles[$locale] ?? '', 'locale' => $locale, 'old' => $old];
        $target = ($item->compose)($locale, $slug);
        if ($target === $old) {
            return $row + ['new' => $old, 'status' => 'unchanged', 'reason' => null];
        }
        if (mb_strlen($target) > self::PATH_MAX) {
            return $row + ['new' => $target, 'status' => 'failed', 'reason' => self::TOO_LONG];
        }

        try {
            return $row + ['new' => ($item->sync)($locale, $slug), 'status' => 'moved', 'reason' => null];
        } catch (RouteConflictException) {
            $free = $suffix ? $this->freeSlug($item, $locale, $slug, $target) : null;
            if ($free === null) {
                return $row + ['new' => $target, 'status' => 'conflict', 'reason' => self::TAKEN];
            }
        }
        if (mb_strlen(($item->compose)($locale, $free)) > self::PATH_MAX) {
            return $row + ['new' => $target, 'status' => 'failed', 'reason' => self::TOO_LONG];
        }

        ($item->rename)($locale, $free);

        return $row + ['new' => ($item->sync)($locale, $free), 'status' => 'suffixed', 'reason' => null];
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
            // the loaded translation is the one every sub-page of the chunk composes from (shareParents);
            // a sub-page elsewhere follows through RouteSync, each with its 301. When anything after
            // this fails, the chunk's attempt is dropped whole and the next reads the slug afresh.
            rename: function (string $locale, string $slug) use ($entry): void {
                $translation = $entry->translations->firstWhere('locale', $locale);
                if ($translation instanceof EntryTranslation) {
                    $translation->update(['slug' => $slug]);
                }
                if ($entry->type === 'page' && $this->permalinks->pagesNested()) {
                    $this->routes->syncDescendants($entry, $locale);
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
     * The entry types a run over $sections walks: `page` for pages, `post` for any post section
     * (a section that is neither pages nor another kind's).
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    private function entryTypes(array $sections): array
    {
        $other = array_column($this->kinds->all(), 'section');
        $types = [];
        foreach ($sections as $section) {
            $type = $section === 'pages' ? 'page' : (in_array($section, $other, true) ? null : 'post');
            if ($type !== null && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Point each loaded page's parent (at every loaded level) at the parent's own instance when the
     * chunk holds it, so a parent's new slug («-2») is the one its sub-pages compose from next.
     *
     * @param  Collection<int, Entry>  $entries
     */
    private function shareParents(Collection $entries): void
    {
        $byId = $entries->keyBy('id');
        $seen = [];
        $stack = $entries->all();
        while ($stack !== []) {
            $entry = array_pop($stack);
            if (isset($seen[spl_object_id($entry)])) {
                continue;
            }
            $seen[spl_object_id($entry)] = true;
            if (! $entry->relationLoaded('parent') || $entry->parent_id === null) {
                continue;
            }
            $shared = $byId->get($entry->parent_id);
            if ($shared instanceof Entry) {
                $entry->setRelation('parent', $shared);
            } elseif ($entry->parent instanceof Entry) {
                $stack[] = $entry->parent;
            }
        }
    }

    /**
     * The real run of handle(): the presets go flat in a short transaction, then each chunk moves in
     * its own, under the site's permalink lock (never beside a chunk of a change's run, or a change
     * being saved); a chunk that fails goes again without the address that failed.
     *
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null}>
     */
    private function flatten(int $siteId, int $size): array
    {
        $this->tenancy->enter($siteId, function () use ($siteId): void {
            ChangeSitePermalinks::lock($siteId);
            $this->permalinks->flattenStored();
        });

        $summary = [];
        try {
            foreach ($this->kinds() as $kind) {
                $after = 0;
                $skip = [];
                while ($after !== null) {
                    try {
                        $chunk = $this->tenancy->enter($siteId, function () use ($siteId, $kind, $after, $size, $skip): array {
                            ChangeSitePermalinks::lock($siteId);
                            $this->permalinks->flush(); // read what the lock now guards

                            return $this->chunk($kind, null, [], $after, $size, suffix: false, skip: $skip);
                        });
                    } catch (PermalinkMoveFailedException $e) {
                        $skip = $this->skipping($skip, $e);

                        continue;
                    }
                    self::summarize($summary, $chunk['rows']);
                    [$after, $skip] = [$chunk['last'], []];
                }
            }
        } finally {
            $this->permalinks->flush();
        }

        return $summary;
    }

    /**
     * The dry run of handle(): the real run's writes in one transaction, rolled back. A failed address
     * rolls the whole preview back; it runs again from the start with that address skipped.
     *
     * @return list<array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null}>
     */
    private function preview(int $siteId, int $size): array
    {
        $skip = [];
        while (true) {
            $summary = [];
            try {
                $this->tenancy->enter($siteId, function () use ($siteId, $size, $skip, &$summary): never {
                    ChangeSitePermalinks::lock($siteId);
                    $this->permalinks->flattenStored();
                    foreach ($this->kinds() as $kind) {
                        $after = 0;
                        do {
                            $chunk = $this->chunk($kind, null, [], $after, $size, suffix: false, skip: $skip);
                            self::summarize($summary, $chunk['rows']);
                            $after = $chunk['last'];
                        } while ($after !== null);
                    }

                    throw new RuntimeException(self::DRY_RUN);
                });
            } catch (PermalinkMoveFailedException $e) {
                $skip = $this->skipping($skip, $e);

                continue;
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== self::DRY_RUN) {
                    throw $e;
                }
                // expected: every write of the preview has just been rolled back
            } finally {
                // the preview cached the flat presets it wrote; those are gone, so read the real ones again
                $this->permalinks->flush();
            }

            return $summary;
        }
    }

    /**
     * @param  list<array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null}>  $summary
     * @param  list<array{type: string, title: string, locale: string, old: string, new: string, status: string, reason: string|null, section: string|null}>  $rows
     */
    private static function summarize(array &$summary, array $rows): void
    {
        foreach ($rows as $row) {
            unset($row['section']);
            $summary[] = $row;
        }
    }
}

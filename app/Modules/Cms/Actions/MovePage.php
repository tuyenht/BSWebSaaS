<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Exceptions\PageMoveRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\PageRules;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a page — with every page under it — to a place among a parent page's sub-pages (null parent =
 * the top level). Both the old and the new sibling lists are renumbered 0..n, in their (position, id)
 * order, so a tree whose pages all carry position 0 keeps the order it shows.
 *
 * The site's page-tree lock is taken FIRST (PageRules::lock); every writer of the tree (a move, a page
 * going to or coming back from the Trash, a content pack) takes it before anything else. Then the page,
 * the parent and both sibling lists are read, their rows locked in id order (the order every other row
 * lock on entries follows, so a bulk action cannot deadlock with a move), and every check runs on the
 * tree as it is now. Where the page lands is worked out inside the lock too: a place named by a
 * neighbour (beside()), one step among its siblings (step()) or the last place under a new parent
 * (into()), so a list drawn a moment ago can never turn «up» into a move to another parent.
 *
 * A new parent must be a live page of this site, not the Home page (it has no sub-pages), not the page
 * itself or a page under it; the parent's level plus the branch's height (sub-pages in the Trash
 * included) may not exceed PageRules::MAX_DEPTH; and the Home page itself keeps its parent. Reordering
 * under the current parent skips those checks, so a tree that predates the rules can still be sorted.
 *
 * Where the site nests page addresses under the parent page's (`nested`), a move to another parent
 * gives the page and its published sub-pages their new address in every language, each with a 301 from
 * the old one (RouteSync); an address another item holds refuses the whole move (path_taken). Under
 * `flat` and `base` addresses never change. The storefront's cache follows by itself (a write to
 * entries / routes / redirects inside the tenant context purges the site after commit).
 *
 * Who may move a page is asked of EntryPolicy for the person signed in: `update` on the page and
 * `fileUnder('page', null)`, so a member limited to some categories never restructures the page tree.
 * With nobody signed in (the console) there is nobody to ask. Runs inside the tenant context.
 */
final readonly class MovePage
{
    /** The most pages one sibling list may hold for a move to renumber it (one statement). */
    public const int SIBLINGS_MAX = PageRules::WALK_SIZE;

    public function __construct(
        private RouteSync $routes,
        private PathBuilder $paths,
        private PermalinkConfig $permalinks,
        private CurrentSite $site,
    ) {}

    /**
     * Put the page at $position among $parent's other sub-pages (clamped to the list). Returns where the
     * page was (for an undo: from_parent, from_position), where it is now, and each language's address
     * that changed.
     *
     * @return array{from_parent: int|null, from_position: int, parent: int|null, position: int, reparented: bool, paths: array<string, array{from: string, to: string}>}
     *
     * @throws AuthorizationException
     * @throws PageMoveRejectedException
     */
    public function handle(Entry $page, ?Entry $parent, int $position): array
    {
        return $this->run($page, fn (): array => [$this->reread($parent), static fn (): int => $position]);
    }

    /**
     * Put the page under $parent as its first sub-page ('child'), or just before / after the sub-page
     * $refUlid ('before' / 'after'). The neighbour is looked up inside the lock; one that left the
     * list meanwhile refuses the move (anchor_gone) rather than guessing a place.
     *
     * @param  'child'|'before'|'after'  $rel
     * @return array{from_parent: int|null, from_position: int, parent: int|null, position: int, reparented: bool, paths: array<string, array{from: string, to: string}>}
     *
     * @throws AuthorizationException
     * @throws PageMoveRejectedException
     */
    public function beside(Entry $page, ?Entry $parent, string $rel, ?string $refUlid): array
    {
        return $this->run($page, function () use ($parent, $rel, $refUlid): array {
            $parent = $this->reread($parent);
            if ($rel === 'child') {
                return [$parent, static fn (): int => 0];
            }
            $refId = $refUlid === null ? null : Entry::query()->where('ulid', $refUlid)->where('type', 'page')->value('id');

            return [$parent, static function (array $siblings) use ($refId, $rel): int {
                $at = $refId === null ? false : array_search(Cast::int($refId), $siblings, true);
                if ($at === false) {
                    throw new PageMoveRejectedException('anchor_gone');
                }

                return $at + ($rel === 'after' ? 1 : 0);
            }];
        });
    }

    /**
     * One place up or down among the page's siblings, under the parent it has NOW: the edge of the
     * list refuses (at_top / at_bottom), and the page never changes parent.
     *
     * @param  'up'|'down'  $dir
     * @return array{from_parent: int|null, from_position: int, parent: int|null, position: int, reparented: bool, paths: array<string, array{from: string, to: string}>}
     *
     * @throws AuthorizationException
     * @throws PageMoveRejectedException
     */
    public function step(Entry $page, string $dir): array
    {
        return $this->run($page, function (Entry $page) use ($dir): array {
            $parent = $page->parent_id === null ? null
                : (Entry::query()->whereKey($page->parent_id)->where('type', 'page')->first() ?? throw new PageMoveRejectedException('parent_invalid'));

            return [$parent, static function (array $siblings, int $from, int $count) use ($dir): int {
                $to = $from + ($dir === 'up' ? -1 : 1);
                if ($to < 0 || $to >= $count) {
                    throw new PageMoveRejectedException($dir === 'up' ? 'at_top' : 'at_bottom');
                }

                return $to;
            }];
        });
    }

    /**
     * The page becomes the last sub-page of $parent (null: the last top page). Its current parent
     * refuses (here_already): «Chuyển đến…» names a new place, never a reorder.
     *
     * @return array{from_parent: int|null, from_position: int, parent: int|null, position: int, reparented: bool, paths: array<string, array{from: string, to: string}>}
     *
     * @throws AuthorizationException
     * @throws PageMoveRejectedException
     */
    public function into(Entry $page, ?Entry $parent): array
    {
        return $this->run($page, function (Entry $page) use ($parent): array {
            $parent = $this->reread($parent);
            if ($parent?->id === $page->parent_id) {
                throw new PageMoveRejectedException('here_already');
            }

            return [$parent, static fn (): int => PHP_INT_MAX];
        });
    }

    /**
     * Would putting the page under $parent break a rule of the tree? Asked inside the lock, on the page
     * and parent as they are now.
     *
     * @throws PageMoveRejectedException
     */
    public function checkReparent(Entry $page, ?Entry $parent): void
    {
        if (PageRules::isHome($page)) {
            throw new PageMoveRejectedException('home_locked');
        }
        if ($parent !== null && ($parent->id === $page->id || in_array($page->id, PageRules::ancestorsOf($parent), true))) {
            throw new PageMoveRejectedException('cycle');
        }
        if ($parent !== null && PageRules::isHome($parent)) {
            throw new PageMoveRejectedException('parent_home');
        }
        $height = PageRules::heightOf($page) ?? throw new PageMoveRejectedException('branch_too_big');
        $level = $parent === null ? 0 : PageRules::levelOf($parent);
        if ($level + $height > PageRules::MAX_DEPTH) {
            throw new PageMoveRejectedException('too_deep', $height);
        }
    }

    /**
     * The move itself, in one transaction: the lock, the page read again, then $target — called with the
     * page as it is now — names the new parent and a function that picks the place among that parent's
     * other sub-pages (it gets them in order, the page's place in its current list and that list's
     * length).
     *
     * @param  Closure(Entry): array{0: Entry|null, 1: Closure(list<int>, int, int): int}  $target
     * @return array{from_parent: int|null, from_position: int, parent: int|null, position: int, reparented: bool, paths: array<string, array{from: string, to: string}>}
     */
    private function run(Entry $page, Closure $target): array
    {
        return DB::transaction(function () use ($page, $target): array {
            PageRules::lock($this->site->id());
            $page = Entry::query()->whereKey($page->id)->where('type', 'page')->first()
                ?? throw new PageMoveRejectedException('page_invalid');
            $this->authorizeMove($page);
            [$parent, $place] = $target($page);

            $oldParent = $page->parent_id;
            $newParent = $parent?->id;
            $reparented = $newParent !== $oldParent;
            $oldSiblings = $this->siblings($oldParent);
            $siblings = $reparented ? $this->siblings($newParent) : array_values(array_diff($oldSiblings, [$page->id]));

            // the rows the move writes or rests on, locked in id order; a page or parent gone meanwhile is refused
            $locked = $this->lockRows([$page->id, ...($newParent === null ? [] : [$newParent]), ...$oldSiblings, ...$siblings]);
            if (! $locked->has($page->id)) {
                throw new PageMoveRejectedException('page_invalid');
            }
            if ($newParent !== null && ! $locked->has($newParent)) {
                throw new PageMoveRejectedException('parent_invalid');
            }
            if ($reparented) {
                $this->checkReparent($page, $parent);
            }

            $fromPosition = Cast::int(array_search($page->id, $oldSiblings, true));
            $position = max(0, min($place($siblings, $fromPosition, count($oldSiblings)), count($siblings)));
            $before = $reparented ? $this->addresses($page) : [];

            array_splice($siblings, $position, 0, [$page->id]);
            $this->renumber($newParent, $siblings, $locked);
            if ($reparented) {
                $this->renumber($oldParent, array_values(array_diff($oldSiblings, [$page->id])), $locked);
            }

            $paths = [];
            if ($reparented && $this->permalinks->pagesNested()) {
                $page = Entry::query()->whereKey($page->id)->firstOrFail();
                $paths = $this->repath($page, $before);
            }

            Audit::record('cms.page.moved', [
                'auditable_type' => Entry::class,
                'auditable_id' => $page->id,
                'meta' => [
                    'from_parent' => $oldParent,
                    'from_position' => $fromPosition,
                    'parent' => $newParent,
                    'position' => $position,
                    'paths' => count($paths),
                ],
            ]);

            return ['from_parent' => $oldParent, 'from_position' => $fromPosition, 'parent' => $newParent, 'position' => $position,
                'reparented' => $reparented, 'paths' => $paths];
        });
    }

    /**
     * The parent page as it is now (null stays null), or the refusal when it is no longer a live page.
     *
     * @throws PageMoveRejectedException
     */
    private function reread(?Entry $parent): ?Entry
    {
        return $parent === null ? null : (Entry::query()->whereKey($parent->id)->where('type', 'page')->first()
            ?? throw new PageMoveRejectedException('parent_invalid'));
    }

    /**
     * Who may move this page: `update` on it and room to file a page at all (a member limited to some
     * categories may change no page). Nobody signed in (the console): nobody to ask.
     *
     * @throws AuthorizationException
     */
    private function authorizeMove(Entry $page): void
    {
        $user = Auth::user();
        if ($user instanceof User) {
            $gate = Gate::forUser($user);
            $gate->authorize('update', $page);
            $gate->authorize('fileUnder', [Entry::class, 'page', null]);
        }
    }

    /**
     * The live pages under $parentId (the top pages when null), in order. A list past SIBLINGS_MAX is
     * refused (siblings_too_many) rather than renumbered in part.
     *
     * @return list<int>
     *
     * @throws PageMoveRejectedException
     */
    private function siblings(?int $parentId): array
    {
        $ids = Entry::query()
            ->where('type', 'page')
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->orderBy('position')->orderBy('id')
            ->limit(self::SIBLINGS_MAX + 1)
            ->pluck('id')->all();
        if (count($ids) > self::SIBLINGS_MAX) {
            throw new PageMoveRejectedException('siblings_too_many');
        }

        return array_values(array_map(static fn (mixed $id): int => Cast::int($id), $ids));
    }

    /**
     * Lock these live rows, in id order, and return their place in the tree as it is now, by id.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, Entry>
     */
    private function lockRows(array $ids): EloquentCollection
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return Entry::query()->whereKey($ids)->orderBy('id')->lockForUpdate()
            ->get(['id', 'site_id', 'parent_id', 'position'])->keyBy('id');
    }

    /**
     * Put these pages under $parentId, numbered 0..n in this order, in one statement. Only rows that
     * change are written, and their «last edited» stays: a place in the tree is not an edit of the page.
     *
     * @param  list<int>  $order
     * @param  EloquentCollection<int, Entry>  $current  the rows as they were locked, by id
     */
    private function renumber(?int $parentId, array $order, EloquentCollection $current): void
    {
        $bindings = [];
        foreach ($order as $position => $id) {
            $row = $current->get($id);
            if ($row instanceof Entry && ($row->parent_id !== $parentId || $row->position !== $position)) {
                array_push($bindings, $id, $position);
            }
        }
        if ($bindings === []) {
            return;
        }
        $values = implode(', ', array_fill(0, intdiv(count($bindings), 2), '(?::bigint, ?::integer)'));
        $table = (new Entry)->getTable();

        DB::update(
            "update {$table} as e set parent_id = ?, position = v.position from (values {$values}) as v(id, position) where e.id = v.id and e.site_id = ?",
            [$parentId, ...$bindings, $this->site->id()],
        );
    }

    /**
     * The page's address in each of its languages, as the site's preset composes it now.
     *
     * @return array<string, string>
     */
    private function addresses(Entry $page): array
    {
        $out = [];
        foreach ($page->translations()->get(['id', 'entry_id', 'locale', 'slug']) as $translation) {
            $out[$translation->locale] = $this->paths->forEntry($page, $translation->locale, (string) $translation->slug);
        }

        return $out;
    }

    /**
     * Give the moved page (when published in that language) and its published sub-pages their nested
     * address in every language the page is written in, each with a 301 from the old one.
     *
     * @param  array<string, string>  $before
     * @return array<string, array{from: string, to: string}>
     *
     * @throws PageMoveRejectedException
     */
    private function repath(Entry $page, array $before): array
    {
        $changed = [];
        try {
            foreach ($page->translations()->get() as $translation) {
                $locale = $translation->locale;
                $slug = (string) $translation->slug;
                if ($page->status === 'published' && $translation->status === 'published') {
                    $this->routes->sync($page, $locale, $slug);
                }
                $this->routes->syncDescendants($page, $locale);
                $to = $this->paths->forEntry($page, $locale, $slug);
                if (($before[$locale] ?? $to) !== $to) {
                    $changed[$locale] = ['from' => $before[$locale] ?? $to, 'to' => $to];
                }
            }
        } catch (RouteConflictException $e) {
            throw new PageMoveRejectedException('path_taken', path: $e->path, previous: $e);
        }

        return $changed;
    }
}

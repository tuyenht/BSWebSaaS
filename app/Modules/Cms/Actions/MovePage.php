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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a page — with every page under it — to a position among a parent page's sub-pages (null parent
 * = the top level). Both the old and the new sibling lists are renumbered 0..n, in their (position, id)
 * order, so a tree whose pages all carry position 0 keeps the order it shows.
 *
 * The site's page-tree lock is taken FIRST (PageRules::lock), then the page and the parent are read
 * again and every check runs on what they are now: two moves of different pages that each pass the
 * loop and depth checks on their own could otherwise make a loop or a fourth level together. A new
 * parent must be a live page of this site, not the Home page (it has no sub-pages), not the page itself
 * or a page under it; the parent's level plus the branch's height (sub-pages in the Trash included) may
 * not exceed PageRules::MAX_DEPTH; and the Home page itself keeps its parent. Reordering under the
 * current parent skips those checks, so a tree that predates the rules can still be sorted.
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
    public function __construct(
        private RouteSync $routes,
        private PathBuilder $paths,
        private PermalinkConfig $permalinks,
        private CurrentSite $site,
    ) {}

    /**
     * Returns where the page was (for an undo), where it is now, and each language's address that changed.
     *
     * @return array{from_parent: int|null, from_position: int, parent: int|null, position: int, reparented: bool, paths: array<string, array{from: string, to: string}>}
     *
     * @throws AuthorizationException
     * @throws PageMoveRejectedException
     */
    public function handle(Entry $page, ?Entry $parent, int $position): array
    {
        return DB::transaction(function () use ($page, $parent, $position): array {
            PageRules::lock($this->site->id());
            $page = Entry::query()->whereKey($page->id)->where('type', 'page')->first()
                ?? throw new PageMoveRejectedException('page_invalid');
            $this->authorizeMove($page);
            $parent = $parent === null ? null : (Entry::query()->whereKey($parent->id)->where('type', 'page')->first()
                ?? throw new PageMoveRejectedException('parent_invalid'));

            $oldParent = $page->parent_id;
            $newParent = $parent?->id;
            $reparented = $newParent !== $oldParent;
            if ($reparented) {
                $this->checkReparent($page, $parent);
            }

            $before = $reparented ? $this->addresses($page) : [];
            $oldSiblings = $this->siblings($oldParent);
            $fromPosition = Cast::int(array_search($page->id, $oldSiblings, true));

            $siblings = $this->siblings($newParent, except: $page->id);
            $position = max(0, min($position, count($siblings)));
            array_splice($siblings, $position, 0, [$page->id]);
            $this->renumber($newParent, $siblings);
            if ($reparented) {
                $this->renumber($oldParent, array_values(array_filter($oldSiblings, static fn (int $id): bool => $id !== $page->id)));
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
     * Would putting the page under $parent break a rule of the tree? Asked inside the lock, on the page
     * and parent as they are now; also what the screen asks for each place it offers.
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
     * The live pages under $parentId (the top pages when null), in order.
     *
     * @return list<int>
     */
    private function siblings(?int $parentId, ?int $except = null): array
    {
        $ids = Entry::query()
            ->where('type', 'page')
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all();

        return array_values(array_map(static fn (mixed $id): int => Cast::int($id), $ids));
    }

    /**
     * Put these pages under $parentId, numbered 0..n in this order. Only rows that change are written,
     * and their «last edited» stays: a place in the tree is not an edit of the page.
     *
     * @param  list<int>  $order
     */
    private function renumber(?int $parentId, array $order): void
    {
        $current = Entry::query()->whereKey($order)->get(['id', 'parent_id', 'position'])->keyBy('id');

        foreach ($order as $position => $id) {
            $row = $current->get($id);
            if ($row instanceof Entry && ($row->parent_id !== $parentId || $row->position !== $position)) {
                Entry::query()->whereKey($id)->toBase()->update(['parent_id' => $parentId, 'position' => $position]);
            }
        }
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

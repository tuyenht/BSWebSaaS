<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\CategoryChange;
use App\Modules\Cms\Routing\CategoryRelinker;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * Delete a category. Nothing filed under it or below it is lost by accident — the caller decides:
 *  - its entries and products ($items) move onto a target category of the same vocabulary, taking
 *    the deleted one's rank (so a primary category stays primary), or simply lose it; facet terms
 *    are never touched. Without a decision, a category with items is kept (has_items);
 *  - its sub-categories ($children) move up to its parent, taking its place among the siblings, or
 *    are deleted with it (their items handled the same way). Without a decision, a category with
 *    sub-categories is kept (has_children).
 * A category a content section is built on is never deleted (section_bound): the section's posts
 * would lose their home. Posts filed primarily under a deleted category that belongs to a section
 * must not leave it as a side effect — moved onto a category of another section (or of none), or
 * detached — unless the caller confirms it ($evenIfSectionChanges): a section carries their
 * permissions and approval workflow (section_changed). Posts whose section runs an active approval
 * workflow never leave it this way, confirmed or not (workflow_governed): the editor refuses the same
 * re-filing post by post, and a category change must not be the way around it.
 *
 * Every address that stops existing answers with a 301: each deleted category page (per language)
 * points to the target's page, else the nearest remaining category above, else the home page; posts
 * and products whose address carried a deleted or lifted category move with RouteSync's 301s
 * (CategoryRelinker). All in one transaction. Menu links to a deleted category follow its items onto
 * the target category when there is one; with no target they stay, and the website stops showing
 * a link whose target is gone.
 *
 * Who may manage categories (`taxonomies.manage`) is the caller's check. Runs inside the tenant
 * context. Returns the counts the screen reports.
 */
final readonly class DeleteTaxonomy
{
    use EditsTaxonomyTree;

    public function __construct(
        private CategoryRelinker $relinker,
        private RouteSync $routes,
        private TaxonomyPaths $paths,
        private SectionResolver $sections,
        private EntryWorkflowResolver $workflows,
        private CurrentSite $site,
    ) {}

    /**
     * @return array{categories: int, items: int, routes_moved: int, redirects: int, menu_links: int}
     *
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(
        Taxonomy $taxonomy,
        ?CategoryItemsOnDelete $items = null,
        ?Taxonomy $target = null,
        ?CategoryChildrenOnDelete $children = null,
        bool $evenIfSectionChanges = false,
    ): array {
        return DB::transaction(function () use ($taxonomy, $items, $target, $children, $evenIfSectionChanges): array {
            $this->lockTree($this->site->id());
            $taxonomy = $this->reload($taxonomy, 'taxonomy_invalid');

            $branch = TaxonomyTree::branchOf($taxonomy->id);
            $childIds = array_keys(array_filter($branch, static fn (int $depth): bool => $depth === 1));
            if ($childIds !== [] && $children === null) {
                throw new TaxonomyChangeRejectedException('has_children');
            }
            $deleted = $children === CategoryChildrenOnDelete::Delete ? array_keys($branch) : [$taxonomy->id];

            if ($this->sections->boundAmong($deleted) !== []) {
                throw new TaxonomyChangeRejectedException('section_bound');
            }
            $targetId = $this->targetId($deleted, $items, $target);
            $leaving = $this->leavingSection($deleted, $targetId);
            // Every deleted category shares one section (a nested section root is refused above as
            // section_bound), so the first one answers for all: one workflow lookup, not one per row.
            $governed = $leaving !== [] && $this->workflows->forCategory($leaving[0]) !== null;
            if ($governed && $this->relinker->hasPrimaryPosts($this->ids($leaving))) {
                throw new TaxonomyChangeRejectedException('workflow_governed');
            }
            if (! $evenIfSectionChanges && $this->relinker->hasPrimaryPosts($this->ids($leaving))) {
                throw new TaxonomyChangeRejectedException('section_changed');
            }

            $slugs = TaxonomyTranslation::query()->whereIn('taxonomy_id', $deleted)->orderBy('taxonomy_id')->pluck('slug')->all();
            $retired = SiteRoute::query()->whereIn('taxonomy_id', $deleted)->get(['locale', 'path']);
            // Where visitors of a deleted category page land: the target, else the nearest remaining category above.
            $landings = [...($targetId === null ? [] : [$targetId]), ...array_reverse(TaxonomyTree::ancestorsOf($taxonomy->id))];

            $lifted = $children === CategoryChildrenOnDelete::Lift ? $childIds : [];
            if ($lifted !== []) {
                $this->lift($taxonomy, $lifted);
                // Which section a post belongs to follows its category's ancestors: forget the old answer.
                $this->sections->flush();
            }

            try {
                $refiled = $this->relinker->refile($deleted, $targetId);
                $liftedBranches = array_merge([], ...array_map(static fn (int $child): array => array_keys(TaxonomyTree::branchOf($child)), $lifted));
                $routesMoved = $refiled['routes'] + $this->relinker->relink($liftedBranches, CategoryChange::Moved);
            } catch (RouteConflictException) {
                throw new TaxonomyChangeRejectedException('path_taken');
            }

            $landingPaths = [];
            foreach ($retired as $route) {
                $landingPaths[$route->locale] ??= $this->landingPath($landings, $route->locale);
                $this->routes->retire($route->locale, $route->path, $landingPaths[$route->locale]);
            }

            // Menu links follow the items: the person deleting chose where the category's content lives now.
            $menuLinks = $targetId === null ? 0 : MenuItem::query()
                ->where('routable_type', 'taxonomy')
                ->whereIn('routable_id', $deleted)
                ->update(['routable_id' => $targetId]);

            // The category rows go last; their translations, routes and (now empty) pivot rows cascade.
            $count = Cast::int(Taxonomy::query()->whereKey($deleted)->delete());

            Audit::record('cms.taxonomy.deleted', [
                'auditable_type' => Taxonomy::class,
                'auditable_id' => $taxonomy->id,
                'meta' => [
                    'ids' => $deleted,
                    'slugs' => $slugs,
                    'items' => $items?->value,
                    'target' => $targetId,
                    'children' => $children?->value,
                    'items_refiled' => $refiled['items'],
                    'routes_moved' => $routesMoved,
                    'redirects' => $retired->count(),
                    'menu_links' => $menuLinks,
                ],
            ]);

            return ['categories' => $count, 'items' => $refiled['items'], 'routes_moved' => $routesMoved, 'redirects' => $retired->count(), 'menu_links' => $menuLinks];
        });
    }

    /**
     * The category the items move onto, null when they are detached — refusing a missing decision
     * while items exist, or a target that is not a remaining category of the same vocabulary.
     *
     * @param  list<int>  $deleted
     *
     * @throws TaxonomyChangeRejectedException
     */
    private function targetId(array $deleted, ?CategoryItemsOnDelete $items, ?Taxonomy $target): ?int
    {
        if ($items === null) {
            if ($this->relinker->hasItems($deleted)) {
                throw new TaxonomyChangeRejectedException('has_items');
            }

            return null;
        }
        if ($items === CategoryItemsOnDelete::Detach) {
            return null;
        }

        $target = $target === null ? null : Taxonomy::query()->whereKey($target->id)->first();
        if ($target === null || in_array($target->id, $deleted, true)) {
            throw new TaxonomyChangeRejectedException('target_invalid');
        }
        $vocabularies = Taxonomy::query()->whereKey($deleted)->distinct()->pluck('vocabulary')->all();
        if (array_diff(Cast::strings($vocabularies), [$target->vocabulary]) !== []) {
            throw new TaxonomyChangeRejectedException('vocabulary_mismatch');
        }

        return $target->id;
    }

    /**
     * The deleted categories whose posts would end up outside the section they are in now: moved
     * onto a category of another section (or of none), or detached — since whatever category ranks
     * next may sit anywhere.
     *
     * @param  list<int>  $deleted
     * @return list<Taxonomy>
     */
    private function leavingSection(array $deleted, ?int $targetId): array
    {
        $target = $targetId === null ? null : Taxonomy::query()->whereKey($targetId)->first();
        $after = $target === null ? null : $this->sections->forCategory($target);

        return array_values(Taxonomy::query()->whereKey($deleted)->get()
            ->filter(function (Taxonomy $category) use ($target, $after): bool {
                $before = $this->sections->forCategory($category);

                return $before !== null && ($target === null || $before !== $after);
            })
            ->all());
    }

    /**
     * @param  list<Taxonomy>  $categories
     * @return list<int>
     */
    private function ids(array $categories): array
    {
        return array_map(static fn (Taxonomy $category): int => $category->id, $categories);
    }

    /**
     * Move the children up to the deleted category's parent: those of its vocabulary take its place
     * among its siblings (in their own order), any of another vocabulary join the end of theirs.
     *
     * @param  list<int>  $childIds
     */
    private function lift(Taxonomy $taxonomy, array $childIds): void
    {
        $children = Taxonomy::query()->whereKey($childIds)->orderBy('position')->orderBy('id')->get(['id', 'vocabulary']);

        foreach ($children->groupBy('vocabulary') as $vocabulary => $group) {
            $siblings = $this->siblings(Cast::string($vocabulary), $taxonomy->parent_id);
            $at = array_search($taxonomy->id, $siblings, true);
            $moving = array_values(array_map(static fn (mixed $id): int => Cast::int($id), $group->modelKeys()));

            if ($at === false) {
                array_push($siblings, ...$moving);
            } else {
                array_splice($siblings, $at, 1, $moving);
            }
            $this->renumber($taxonomy->parent_id, $siblings);
        }

        foreach ($childIds as $child) {
            $this->paths->refreshBranch($child);
        }
    }

    /**
     * The first of the candidate categories with a page in this language, else the home page.
     *
     * @param  list<int>  $candidates
     */
    private function landingPath(array $candidates, string $locale): string
    {
        if ($candidates === []) {
            return '/';
        }

        $paths = SiteRoute::query()->whereIn('taxonomy_id', $candidates)->where('locale', $locale)->pluck('path', 'taxonomy_id');
        foreach ($candidates as $id) {
            if (isset($paths[$id])) {
                return Cast::string($paths[$id], '/');
            }
        }

        return '/';
    }
}

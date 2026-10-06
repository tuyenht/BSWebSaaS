<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\CategoryChange;
use App\Modules\Cms\Routing\CategoryRelinker;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * Move a category — with everything under it — to a position among a parent's children of the same
 * vocabulary (null parent = top level). Both the old and the new sibling lists are renumbered 0..n.
 *
 * A new parent must be on this site, of the same vocabulary, not the category itself or anything
 * under it, and deep enough room must be left: the parent's level plus the moved branch's height may
 * not exceed TaxonomyRules::MAX_DEPTH. A section carries its posts' permissions and approval workflow,
 * so a move that would take posts out of their section (to another one, or to none) is refused as
 * section_changed unless the caller confirms it ($evenIfSectionChanges — the screen shows what moves
 * where first); a branch without posts, or posts joining a section from none, move freely. Posts whose
 * section runs an active approval workflow never leave it this way, confirmed or not
 * (workflow_governed): the editor refuses the same re-filing post by post, and a category move must
 * not be the way around it.
 * Reordering under the current parent skips those checks, so a tree that predates the rules can
 * still be sorted. On a new parent the branch's structural paths are
 * rewritten and — where the permalink presets carry the category chain — the posts and products
 * filed under the branch follow, each with a 301 (CategoryRelinker), in the same transaction.
 *
 * Who may manage categories (`taxonomies.manage`) is the caller's check. Runs inside the tenant
 * context.
 */
final readonly class MoveTaxonomy
{
    use EditsTaxonomyTree;

    public function __construct(
        private TaxonomyPaths $paths,
        private CategoryRelinker $relinker,
        private SectionResolver $sections,
        private EntryWorkflowResolver $workflows,
        private CurrentSite $site,
    ) {}

    /**
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(Taxonomy $taxonomy, ?Taxonomy $parent, int $position, bool $evenIfSectionChanges = false): void
    {
        DB::transaction(function () use ($taxonomy, $parent, $position, $evenIfSectionChanges): void {
            $this->lockTree($this->site->id());
            $taxonomy = $this->reload($taxonomy, 'taxonomy_invalid');
            $parent = $parent === null ? null : $this->reload($parent, 'parent_invalid');

            $branch = TaxonomyTree::branchOf($taxonomy->id);
            $oldParent = $taxonomy->parent_id;
            $newParent = $parent?->id;
            $reparented = $newParent !== $oldParent;

            if ($parent !== null && isset($branch[$parent->id])) {
                throw new TaxonomyChangeRejectedException('parent_invalid');
            }
            if ($reparented && $parent !== null && $parent->vocabulary !== $taxonomy->vocabulary) {
                throw new TaxonomyChangeRejectedException('vocabulary_mismatch');
            }
            $parentLevel = $parent === null ? 0 : TaxonomyTree::levelOf($parent->id);
            if ($reparented && $parentLevel + TaxonomyTree::heightOf($branch) > TaxonomyRules::MAX_DEPTH) {
                throw new TaxonomyChangeRejectedException('depth_exceeded');
            }
            $leaving = $reparented ? $this->postsLeavingSection($taxonomy, $parent, array_keys($branch)) : false;
            if ($leaving && $this->workflows->forCategory($taxonomy) !== null) {
                throw new TaxonomyChangeRejectedException('workflow_governed');
            }
            if ($leaving && ! $evenIfSectionChanges) {
                throw new TaxonomyChangeRejectedException('section_changed');
            }

            $siblings = $this->siblings($taxonomy->vocabulary, $newParent, except: $taxonomy->id);
            array_splice($siblings, max(0, min($position, count($siblings))), 0, [$taxonomy->id]);
            $this->renumber($newParent, $siblings);

            $routesMoved = 0;
            if ($reparented) {
                $this->renumber($oldParent, $this->siblings($taxonomy->vocabulary, $oldParent));
                $this->paths->refreshBranch($taxonomy->id);
                // Which section a post belongs to follows its category's ancestors: forget the old answer.
                $this->sections->flush();

                try {
                    $routesMoved = $this->relinker->relink(array_keys($branch), CategoryChange::Moved);
                } catch (RouteConflictException) {
                    throw new TaxonomyChangeRejectedException('path_taken');
                }
            }

            Audit::record('cms.taxonomy.moved', [
                'auditable_type' => Taxonomy::class,
                'auditable_id' => $taxonomy->id,
                'meta' => [
                    'from_parent' => $oldParent,
                    'parent' => $newParent,
                    'position' => array_search($taxonomy->id, $siblings, true),
                    'categories' => count($branch),
                    'routes_moved' => $routesMoved,
                ],
            ]);
        });
    }

    /**
     * Whether the move takes posts out of the section they are in: the posts filed primarily under
     * the part of the branch that gets its section through the moved category (a section root inside
     * the branch keeps its own). A section root itself never changes section, and posts joining a
     * section from none gain an approval workflow rather than escape one.
     *
     * @param  list<int>  $branch
     */
    private function postsLeavingSection(Taxonomy $taxonomy, ?Taxonomy $parent, array $branch): bool
    {
        $before = $this->sections->forCategory($taxonomy);
        if ($before === null || $this->sections->boundAmong([$taxonomy->id]) !== []) {
            return false;
        }
        $after = $parent === null ? null : $this->sections->forCategory($parent);
        if ($after === $before) {
            return false;
        }

        $following = Taxonomy::query()->whereKey($branch)->get()
            ->filter(fn (Taxonomy $category): bool => $this->sections->forCategory($category) === $before)
            ->modelKeys();

        return $this->relinker->hasPrimaryPosts(array_values(array_map(static fn (mixed $id): int => Cast::int($id), $following)));
    }
}

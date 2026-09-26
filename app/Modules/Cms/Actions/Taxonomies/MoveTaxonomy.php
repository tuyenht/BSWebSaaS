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
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * Move a category — with everything under it — to a position among a parent's children of the same
 * vocabulary (null parent = top level). Both the old and the new sibling lists are renumbered 0..n.
 *
 * A new parent must be on this site, of the same vocabulary, not the category itself or anything
 * under it, and deep enough room must be left: the parent's level plus the moved branch's height may
 * not exceed TaxonomyRules::MAX_DEPTH, and the branch's posts must stay in the section they are in
 * (a section carries their permissions and approval workflow — moving them across is refused as
 * section_changed rather than done silently). Reordering under the current parent skips those checks, so a
 * tree that predates the rules can still be sorted. On a new parent the branch's structural paths are
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
        private CurrentSite $site,
    ) {}

    /**
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(Taxonomy $taxonomy, ?Taxonomy $parent, int $position): void
    {
        DB::transaction(function () use ($taxonomy, $parent, $position): void {
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
            // A post's section decides which permissions and which approval workflow govern it; a
            // move must not hand the branch's posts to another section (or to none) as a side effect.
            if ($reparented && $this->sectionAfter($taxonomy, $parent) !== $this->sections->forCategory($taxonomy)) {
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
     * The section the moved category's posts would belong to under $parent: its own when the
     * category is a section's root, otherwise whatever the new parent resolves to (none at the top).
     *
     * @return array{key: string, type: string}|null
     */
    private function sectionAfter(Taxonomy $taxonomy, ?Taxonomy $parent): ?array
    {
        if ($this->sections->boundAmong([$taxonomy->id]) !== []) {
            return $this->sections->forCategory($taxonomy);
        }

        return $parent === null ? null : $this->sections->forCategory($parent);
    }
}

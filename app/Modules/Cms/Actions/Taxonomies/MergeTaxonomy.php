<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Gộp vào chuyên mục khác": every item of $source moves onto $target, then $source is removed —
 * a DeleteTaxonomy with the items reassigned and the sub-categories lifted to $source's parent, so
 * every rule of a delete holds (same vocabulary, section-bound roots kept, a section change
 * confirmed, workflow-governed posts kept in their section, member limits moved onto the target,
 * 301s from every address that stops existing). The target must lie outside $source's branch:
 * a sub-category would be lifted while it receives the items (target_invalid).
 *
 * Who may manage categories is checked through TaxonomyPolicy for the person signed in. Runs inside
 * the tenant context. Returns DeleteTaxonomy's counts.
 */
final readonly class MergeTaxonomy
{
    use EditsTaxonomyTree;

    public function __construct(
        private DeleteTaxonomy $delete,
        private CurrentSite $site,
    ) {}

    /**
     * @return array{categories: int, items: int, routes_moved: int, redirects: int, menu_links: int}
     *
     * @throws AuthorizationException
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(Taxonomy $source, Taxonomy $target, bool $evenIfSectionChanges = false): array
    {
        $this->authorizeManage();

        return DB::transaction(function () use ($source, $target, $evenIfSectionChanges): array {
            // The same lock the delete takes, taken first so the branch read here is the one it deletes.
            $this->lockTree($this->site->id());
            $source = $this->reload($source, 'taxonomy_invalid');
            $target = $this->reload($target, 'target_invalid');
            if (isset(TaxonomyTree::branchOf($source->id)[$target->id])) {
                throw new TaxonomyChangeRejectedException('target_invalid');
            }

            return $this->delete->handle($source, CategoryItemsOnDelete::Reassign, $target, CategoryChildrenOnDelete::Lift, $evenIfSectionChanges);
        });
    }
}

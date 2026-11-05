<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions\Taxonomies;

use App\Modules\Cms\Actions\Taxonomies\Concerns\EditsTaxonomyTree;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyTree;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Hoàn tác" of a move: the category goes back under its old parent ($parent, null = top level)
 * at its old position. Only while the tree is still the one the move left — $version is
 * TaxonomyTree::version() of the vocabulary right after the move; once anything was added, moved
 * or deleted since (by this person in another tab, or by someone else), undoing would put the
 * category back into a tree that is no longer there, so it is refused (move_stale) and the screen
 * reloads instead.
 *
 * The way back is an ordinary MoveTaxonomy, so every rule of a move holds (depth, own branch,
 * member limits, workflow-governed posts). The section change it reverses was confirmed when the
 * move was made, so it is not asked again. Runs inside the tenant context.
 */
final readonly class UndoTaxonomyMove
{
    use EditsTaxonomyTree;

    public function __construct(
        private MoveTaxonomy $move,
        private CurrentSite $site,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws TaxonomyChangeRejectedException
     */
    public function handle(Taxonomy $taxonomy, ?Taxonomy $parent, int $position, string $version): void
    {
        $this->authorizeManage();

        DB::transaction(function () use ($taxonomy, $parent, $position, $version): void {
            // Held until the transaction ends, so nothing moves between the check and the move.
            $this->lockTree($this->site->id());
            $taxonomy = $this->reload($taxonomy, 'taxonomy_invalid');
            if (! hash_equals(TaxonomyTree::version($taxonomy->vocabulary), $version)) {
                throw new TaxonomyChangeRejectedException('move_stale');
            }

            $this->move->handle($taxonomy, $parent, $position, evenIfSectionChanges: true);
        });
    }
}

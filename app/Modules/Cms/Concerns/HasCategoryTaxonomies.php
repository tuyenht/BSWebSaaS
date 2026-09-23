<?php

declare(strict_types=1);

namespace App\Modules\Cms\Concerns;

use App\Modules\Cms\Models\Taxonomy;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

/**
 * An item filed through ONE ordered taxonomy pivot (entry_taxonomies / product_taxonomies) that
 * carries both its categories and its facet terms (sector, tag…).
 *
 * Invariant: pivot positions 0..n-1 are the categories, and position 0 is THE primary category —
 * routing, the section a post belongs to (so which workflow governs it), the category listings and
 * the admin filter all read exactly that row (several of them in SQL, `position = 0`). Facet terms
 * live from FACET_POSITION_BASE up, so no reader can take one for a category and category writes
 * leave them alone.
 *
 * The host provides taxonomies() ordered by pivot position with `position` on the pivot, plus the
 * `site_id` every pivot row is stamped with (the pivots are RLS-protected; sync() won't fill it).
 */
trait HasCategoryTaxonomies
{
    /** Pivot positions from here up belong to facet terms; below it, to categories. */
    public const int FACET_POSITION_BASE = 1000;

    /**
     * @return BelongsToMany<Taxonomy, $this>
     */
    abstract public function taxonomies(): BelongsToMany;

    /**
     * Replace the item's categories with $taxonomyIds, in order (index 0 = primary). Facet terms
     * ride along untouched: sync() diffs against EVERY pivot row, so a facet missing from the
     * payload would be detached.
     *
     * @param  list<int>  $taxonomyIds
     */
    public function assignCategories(array $taxonomyIds): void
    {
        if (count($taxonomyIds) >= self::FACET_POSITION_BASE) {
            throw new InvalidArgumentException('Too many categories: pivot positions from '.self::FACET_POSITION_BASE.' up belong to facet terms.');
        }

        $payload = [];
        foreach ($taxonomyIds as $position => $id) {
            $payload[$id] = ['position' => $position, 'site_id' => $this->site_id];
        }

        $relation = $this->taxonomies();
        $facets = $relation->newPivotQuery()
            ->where('position', '>=', self::FACET_POSITION_BASE)
            ->pluck('position', $relation->getRelatedPivotKeyName());

        foreach ($facets as $taxonomyId => $position) {
            if (is_numeric($position)) {
                $payload[(int) $taxonomyId] ??= ['position' => (int) $position, 'site_id' => $this->site_id];
            }
        }

        $relation->sync($payload);
    }

    /** The primary category — the taxonomy at pivot position 0 — or null when there is none. */
    public function primaryCategory(): ?Taxonomy
    {
        if ($this->relationLoaded('taxonomies')) {
            return $this->taxonomies->first(fn (Taxonomy $taxonomy): bool => self::pivotPosition($taxonomy) === 0);
        }

        return $this->taxonomies()->wherePivot('position', 0)->first();
    }

    private static function pivotPosition(Taxonomy $taxonomy): ?int
    {
        $pivot = $taxonomy->relationLoaded('pivot') ? $taxonomy->getRelation('pivot') : null;
        $position = $pivot instanceof Pivot ? $pivot->getAttribute('position') : null;

        return is_numeric($position) ? (int) $position : null;
    }
}

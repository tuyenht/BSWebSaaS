<?php

declare(strict_types=1);

namespace App\Modules\Cms\Concerns;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryItemCounts;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
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
 * It uses SoftDeletes.
 *
 * The main category's total (`taxonomies.items_count`, CategoryItemCounts) follows the item here,
 * for every caller: a change of main category in assignCategories(), and the trash, restore and
 * permanent delete of an item through the model events below. A host whose rows do not all
 * count (Entry: posts only) narrows countsInCategoryTotals().
 *
 * Each of them first locks the item's row (FOR UPDATE, in the caller's transaction) and takes the
 * item's trash state from that locked row, then reads its main category: two saves, trashes or
 * restores of one item at once queue behind each other, so the second sees what the first wrote
 * and never counts the same change twice. The row is locked before any category row (the counter
 * updates come last), the order every other writer of an item keeps.
 */
trait HasCategoryTaxonomies
{
    /** Pivot positions from here up belong to facet terms; below it, to categories. */
    public const int FACET_POSITION_BASE = 1000;

    /** The main category a delete under way takes the item out of (null: it did not count). */
    private ?int $mainLeftByDelete = null;

    /** Whether the restore under way brings the item back from the trash. */
    private bool $restoringFromTrash = false;

    public static function bootHasCategoryTaxonomies(): void
    {
        // A soft or permanent delete of a LIVE item leaves its main category's total. Read while
        // the pivot rows are still there (a permanent delete cascades them), applied once the row
        // is gone — a delete another listener cancels changes nothing, and deleting an item that
        // is already in the trash (a second trash, a purge) does not count it out twice.
        static::registerModelEvent('deleting', static function (self $item): void {
            $item->mainLeftByDelete = $item->countsInCategoryTotals() && $item->lockLiveState() === true ? $item->mainCategoryId() : null;
        });
        static::registerModelEvent('deleted', static function (self $item): void {
            $main = $item->mainLeftByDelete;
            $item->mainLeftByDelete = null;
            if ($main !== null) {
                app(CategoryItemCounts::class)->adjust([$main => -1]);
            }
        });
        // Only a restore out of the trash brings it back (restore() on a live row saves it again).
        // Both the copy and the stored row must be in the trash: restore() writes only what it
        // changed on the copy, so a copy that already looks live writes nothing.
        static::registerModelEvent('restoring', static function (self $item): void {
            $item->restoringFromTrash = $item->trashed() && $item->countsInCategoryTotals() && $item->lockLiveState() === false;
        });
        static::registerModelEvent('restored', static function (self $item): void {
            $fromTrash = $item->restoringFromTrash;
            $item->restoringFromTrash = false;
            $main = $fromTrash ? $item->mainCategoryId() : null;
            if ($main !== null) {
                app(CategoryItemCounts::class)->adjust([$main => 1]);
            }
        });
    }

    /** Whether this kind of row counts in its main category's total at all (trash aside). */
    public function countsInCategoryTotals(): bool
    {
        return true;
    }

    /**
     * @return BelongsToMany<Taxonomy, $this>
     */
    abstract public function taxonomies(): BelongsToMany;

    /**
     * Replace the item's categories with $taxonomyIds, in order (index 0 = primary). Facet terms
     * ride along untouched: sync() diffs against EVERY pivot row, so a facet missing from the
     * payload would be detached. A category listed twice is filed once, at its first place.
     *
     * @param  list<int>  $taxonomyIds
     */
    public function assignCategories(array $taxonomyIds): void
    {
        // [A, B, A] would give A two positions, the later one winning in the payload: A would
        // leave position 0 while its count went up.
        $taxonomyIds = array_values(array_unique($taxonomyIds));
        if (count($taxonomyIds) >= self::FACET_POSITION_BASE) {
            throw new InvalidArgumentException('Too many categories: pivot positions from '.self::FACET_POSITION_BASE.' up belong to facet terms.');
        }

        $payload = [];
        foreach ($taxonomyIds as $position => $id) {
            $payload[$id] = ['position' => $position, 'site_id' => $this->site_id];
        }

        // Before reading the old main category: see the class comment.
        $counts = $this->exists && $this->countsInCategoryTotals() && $this->lockLiveState() === true;

        // The facet terms to keep and the main category now, in one read.
        $relation = $this->taxonomies();
        $kept = $relation->newPivotQuery()
            ->where(static fn (Builder $rows) => $rows->where('position', 0)->orWhere('position', '>=', self::FACET_POSITION_BASE))
            ->pluck('position', $relation->getRelatedPivotKeyName());

        $from = null;
        foreach ($kept as $taxonomyId => $position) {
            if (! is_numeric($position)) {
                continue;
            }
            if ((int) $position === 0) {
                $from = (int) $taxonomyId;
            } else {
                $payload[(int) $taxonomyId] ??= ['position' => (int) $position, 'site_id' => $this->site_id];
            }
        }

        $relation->sync($payload);
        // A loaded list is stale now, and the count events read the main category from it.
        $this->unsetRelation('taxonomies');

        if ($counts) {
            app(CategoryItemCounts::class)->moved($from, $taxonomyIds[0] ?? null);
        }
    }

    /** The primary category — the taxonomy at pivot position 0 — or null when there is none. */
    public function primaryCategory(): ?Taxonomy
    {
        if ($this->relationLoaded('taxonomies')) {
            return $this->taxonomies->first(fn (Taxonomy $taxonomy): bool => self::pivotPosition($taxonomy) === 0);
        }

        return $this->taxonomies()->wherePivot('position', 0)->first();
    }

    /** The main category's id, from the loaded list when there is one (a bulk action loads it with the rows). */
    private function mainCategoryId(): ?int
    {
        if ($this->relationLoaded('taxonomies')) {
            return $this->primaryCategory()?->id;
        }

        $relation = $this->taxonomies();
        $id = $relation->newPivotQuery()
            ->where('site_id', $this->site_id)
            ->where('position', 0)
            ->value($relation->getRelatedPivotKeyName());

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Lock this item's row for the rest of the caller's transaction and tell whether it is out of
     * the trash, as stored (null: the row is gone). The site scope stays on.
     */
    private function lockLiveState(): ?bool
    {
        $row = $this->newQueryWithoutScope(SoftDeletingScope::class)
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->first([$this->getKeyName(), $this->getDeletedAtColumn()]);

        return $row === null ? null : ! $row->trashed();
    }

    private static function pivotPosition(Taxonomy $taxonomy): ?int
    {
        $pivot = $taxonomy->relationLoaded('pivot') ? $taxonomy->getRelation('pivot') : null;
        $position = $pivot instanceof Pivot ? $pivot->getAttribute('position') : null;

        return is_numeric($position) ? (int) $position : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Cms\Concerns;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Shared\Support\Cast;
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
 * It uses SoftDeletes, and marks its translations' content as changed (markContentChanged()) when
 * its main category changes: the page shows that one in its breadcrumb.
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
 *
 * The categories' public totals per language (CategoryPublicCounts) follow the item on the same
 * roads — a change of its categories, the trash, restore and permanent delete — and on a change of
 * its `status` (an item is public only once published), read before and after each write on the
 * locked row. A caller that wraps several writes of one item in CategoryPublicCounts::track() is
 * counted once, and these events stay silent for it.
 */
trait HasCategoryTaxonomies
{
    /** Pivot positions from here up belong to facet terms; below it, to categories. */
    public const int FACET_POSITION_BASE = 1000;

    /** The main category a delete under way takes the item out of (null: it did not count). */
    private ?int $mainLeftByDelete = null;

    /** Whether the restore under way brings the item back from the trash. */
    private bool $restoringFromTrash = false;

    /**
     * The public totals' reading taken before the write under way (CategoryPublicCounts::begin()).
     *
     * @var array{kind: string, id: int, before: array<string, array{0: int, 1: int}>, asIfLive: bool}|null
     */
    private ?array $publicCountsBefore = null;

    /** Whether $publicCountsBefore belongs to the status update under way (not to a restore). */
    private bool $publicCountsOnUpdate = false;

    /** Whether the status save under way puts the item on the website (so what it adds after is read). */
    private bool $publicCountsAfter = false;

    /**
     * Whether the row lockLiveState() just locked is published: an item that is not adds nothing to
     * the public totals before or after a trash, a restore or a re-filing, so they are not read.
     */
    private bool $lockedPublished = false;

    public static function bootHasCategoryTaxonomies(): void
    {
        // A soft or permanent delete of a LIVE item leaves its main category's total. Read while
        // the pivot rows are still there (a permanent delete cascades them), applied once the row
        // is gone — a delete another listener cancels changes nothing, and deleting an item that
        // is already in the trash (a second trash, a purge) does not count it out twice.
        static::registerModelEvent('deleting', static function (self $item): void {
            $live = $item->countsInCategoryTotals() && $item->lockLiveState() === true;
            $item->mainLeftByDelete = $live ? $item->mainCategoryId() : null;
            // Its public share is read now, while its translations and filings are still there — or,
            // for a trash inside a bulk action, read with the others when the action ends.
            $public = app(CategoryPublicCounts::class);
            $pivot = $item->taxonomies()->getTable();
            $trash = ! $item->isForceDeleting();   // both hosts keep a trash (SoftDeletes)
            $id = Cast::int($item->getKey());
            // A permanent delete of an item that went off the website earlier in the bulk action is
            // read too (as if live): the action's end takes out what the item added when it began.
            $item->publicCountsBefore = ($live && $item->lockedPublished && ! ($trash && $public->deferred('out', $pivot, $id)))
                || (! $trash && $item->countsInCategoryTotals() && $public->wentOff($pivot, $id))
                ? $public->begin($pivot, $id, locked: true)
                : null;
        });
        static::registerModelEvent('deleted', static function (self $item): void {
            $main = $item->mainLeftByDelete;
            $item->mainLeftByDelete = null;
            if ($main !== null) {
                app(CategoryItemCounts::class)->adjust([$main => -1]);
            }
            $public = $item->publicCountsBefore;
            $item->publicCountsBefore = null;
            app(CategoryPublicCounts::class)->end($public, countsAfter: false);
        });
        // Only a restore out of the trash brings it back (restore() on a live row saves it again).
        // Both the copy and the stored row must be in the trash: restore() writes only what it
        // changed on the copy, so a copy that already looks live writes nothing.
        static::registerModelEvent('restoring', static function (self $item): void {
            $item->restoringFromTrash = $item->trashed() && $item->countsInCategoryTotals() && $item->lockLiveState() === false;
            // In the trash it adds nothing to the public totals: nothing to read before. Inside a bulk
            // action it is read with the others when the action ends.
            $public = app(CategoryPublicCounts::class);
            $pivot = $item->taxonomies()->getTable();
            $item->publicCountsBefore = $item->restoringFromTrash && $item->lockedPublished && ! $public->deferred('in', $pivot, Cast::int($item->getKey()))
                ? $public->begin($pivot, Cast::int($item->getKey()), locked: true, countsNow: false)
                : null;
        });
        static::registerModelEvent('restored', static function (self $item): void {
            $fromTrash = $item->restoringFromTrash;
            $item->restoringFromTrash = false;
            $main = $fromTrash ? $item->mainCategoryId() : null;
            if ($main !== null) {
                app(CategoryItemCounts::class)->adjust([$main => 1]);
            }
            $public = $item->publicCountsBefore;
            $item->publicCountsBefore = null;
            app(CategoryPublicCounts::class)->end($public);
        });
        // A stored item going on or off the website (its status to or from published; a change
        // between two unpublished statuses moves nothing). Inside a restore (its own save) the
        // restore's reading covers it; an item in the trash adds nothing either way. In an open batch
        // (a bulk change) it is read once with the others when the batch closes.
        static::registerModelEvent('updating', static function (self $item): void {
            $item->publicCountsOnUpdate = false;   // never a reading left by a save that failed
            $item->publicCountsAfter = false;
            if ($item->restoringFromTrash || ! $item->isDirty('status') || $item->trashed() || ! $item->countsInCategoryTotals()) {
                return;
            }
            $was = $item->getOriginal('status') === 'published';
            $now = $item->getAttribute('status') === 'published';
            $public = app(CategoryPublicCounts::class);
            $pivot = $item->taxonomies()->getTable();
            if ($was === $now || $public->deferred($was ? 'out' : 'in', $pivot, Cast::int($item->getKey()))) {
                return;
            }
            // What it added before is read only if it was on the website, what it adds after only if it is.
            $item->publicCountsBefore = $public->begin($pivot, Cast::int($item->getKey()), countsNow: $was);
            $item->publicCountsOnUpdate = $item->publicCountsBefore !== null;
            $item->publicCountsAfter = $now;
        });
        static::registerModelEvent('updated', static function (self $item): void {
            if ($item->publicCountsOnUpdate) {
                $public = $item->publicCountsBefore;
                $item->publicCountsBefore = null;
                $item->publicCountsOnUpdate = false;
                app(CategoryPublicCounts::class)->end($public, $item->publicCountsAfter);
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
     * Mark that the main content of these items changed, in every language.
     *
     * @param  list<int>  $ids
     */
    abstract public static function markContentChanged(array $ids): void;

    /**
     * Replace the item's categories with $taxonomyIds, in order (index 0 = primary). Facet terms
     * ride along untouched: sync() diffs against EVERY pivot row, so a facet missing from the
     * payload would be detached. A category listed twice is filed once, at its first place.
     *
     * Returns whether the main category of a stored item changed. Such a change marks the item's
     * content as changed, unless $markContent is false: a bulk caller then marks all the items it
     * moved in one statement (markContentChanged()), in the same transaction.
     *
     * @param  list<int>  $taxonomyIds
     */
    public function assignCategories(array $taxonomyIds, bool $markContent = true): bool
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
        // The public totals: what the item adds before the re-filing (the row is locked above) — also
        // for an item that went off the website earlier in the bulk action (read as if live).
        $publicCounts = app(CategoryPublicCounts::class);
        $pivotTable = $this->taxonomies()->getTable();
        $public = ($counts && $this->lockedPublished) || ($this->exists && $this->countsInCategoryTotals() && $publicCounts->wentOff($pivotTable, Cast::int($this->getKey())))
            ? $publicCounts->begin($pivotTable, Cast::int($this->getKey()), locked: true)
            : null;

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
        app(CategoryPublicCounts::class)->end($public);
        // Only the main category is on the page (the breadcrumb); the others are not.
        $mainChanged = $this->exists && $from !== ($taxonomyIds[0] ?? null);
        if ($mainChanged && $markContent) {
            static::markContentChanged([Cast::int($this->getKey())]);
        }

        return $mainChanged;
    }

    /** The primary category — the taxonomy at pivot position 0 — or null when there is none. */
    public function primaryCategory(): ?Taxonomy
    {
        if ($this->relationLoaded('taxonomies')) {
            return $this->taxonomies->first(fn (Taxonomy $taxonomy): bool => self::pivotPosition($taxonomy) === 0);
        }

        return $this->taxonomies()->wherePivot('position', 0)->first();
    }

    /**
     * The main category's id, from the loaded list when there is one (a bulk action loads it with the
     * rows). A caller that loads the list must do so after it has locked the rows, or this reads a
     * main category another save has already changed.
     */
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
     * the trash, as stored (null: the row is gone); whether it is published is kept in
     * $lockedPublished. The site scope stays on.
     */
    private function lockLiveState(): ?bool
    {
        $row = $this->newQueryWithoutScope(SoftDeletingScope::class)
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->first([$this->getKeyName(), $this->getDeletedAtColumn(), 'status']);
        $this->lockedPublished = $row !== null && $row->getAttribute('status') === 'published';

        return $row === null ? null : ! $row->trashed();
    }

    private static function pivotPosition(Taxonomy $taxonomy): ?int
    {
        $pivot = $taxonomy->relationLoaded('pivot') ? $taxonomy->getRelation('pivot') : null;
        $position = $pivot instanceof Pivot ? $pivot->getAttribute('position') : null;

        return is_numeric($position) ? (int) $position : null;
    }
}

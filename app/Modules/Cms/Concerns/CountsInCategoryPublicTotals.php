<?php

declare(strict_types=1);

namespace App\Modules\Cms\Concerns;

use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Shared\Support\Cast;

/**
 * A translation row of an item filed under categories (a post's, a product's): an item is public
 * in a language only with its translation there (a post's must be published too), so a translation
 * added, removed, published or unpublished — or moved to another language or item — changes the
 * categories' public totals in that language (CategoryPublicCounts). Read before and after the
 * write, with the item's row locked first; silent when nothing that counts changed, or when a
 * CategoryPublicCounts::track() around the write already counts the item.
 *
 * The host names the pivot that files its item under categories and the column naming the item
 * (the same column on both tables), and which of its columns decide whether it counts.
 */
trait CountsInCategoryPublicTotals
{
    /**
     * The readings taken before the write under way, one per item it touches.
     *
     * @var list<array{kind: string, id: int, before: array<string, array{0: int, 1: int}>}>
     */
    private array $publicCountsBefore = [];

    /** The pivot that files this row's item under categories, e.g. 'entry_taxonomies'. */
    abstract public function categoryPivot(): string;

    /** The column naming this row's item, e.g. 'entry_id'. */
    abstract public function categoryItemColumn(): string;

    /**
     * The columns besides the language and the item whose change makes the row count or not.
     *
     * @return list<string>
     */
    public function publicCountColumns(): array
    {
        return [];
    }

    public static function bootCountsInCategoryPublicTotals(): void
    {
        static::registerModelEvent('saving', static function (self $row): void {
            $row->publicCountsBefore = [];   // never a reading left by a save that failed
            $column = $row->categoryItemColumn();
            if (! $row->exists || $row->isDirty(['locale', $column, ...$row->publicCountColumns()])) {
                $row->beginPublicCounts($row->exists ? [$row->getOriginal($column), $row->getAttribute($column)] : [$row->getAttribute($column)]);
            }
        });
        static::registerModelEvent('saved', static function (self $row): void {
            $row->endPublicCounts();
        });
        static::registerModelEvent('deleting', static function (self $row): void {
            $row->beginPublicCounts([$row->getAttribute($row->categoryItemColumn())]);
        });
        static::registerModelEvent('deleted', static function (self $row): void {
            $row->endPublicCounts();
        });
    }

    /** @param  list<mixed>  $items */
    private function beginPublicCounts(array $items): void
    {
        $counts = app(CategoryPublicCounts::class);
        $this->publicCountsBefore = [];
        $ids = array_values(array_unique(array_filter(array_map(static fn (mixed $id): ?int => is_numeric($id) ? Cast::int($id) : null, $items))));
        sort($ids);   // the item rows are locked in id order
        foreach ($ids as $id) {
            $token = $counts->begin($this->categoryPivot(), $id);
            if ($token !== null) {
                $this->publicCountsBefore[] = $token;
            }
        }
    }

    private function endPublicCounts(): void
    {
        $tokens = $this->publicCountsBefore;
        $this->publicCountsBefore = [];
        foreach ($tokens as $token) {
            app(CategoryPublicCounts::class)->end($token);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Cms\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A per-language content row (entry_translations, product_translations) that records in its hidden
 * `content_changed_at` when the page's own main content last changed: the text search engines read
 * on the page (title, body, summary, the title and description shown in results). The sitemap's
 * lastmod, a post's Article dateModified and its "Cập nhật" date read it (contentDate()).
 *
 * The mark moves only when one of the host's contentFields() really changes: a save of anything
 * else (the language's review status, an unused column) leaves it, and a save that changes nothing
 * writes nothing at all. A new row starts with a mark. What the page shows from other tables (the
 * owner's cover, its main category, a product's pictures) is marked by the owner model's
 * markContentChanged(), called where those are written.
 *
 * A row from before the column has no mark; the readers then fall back to its updated_at. The first
 * save that does not move the mark fills it from the updated_at the row had until then, with a
 * guarded `whereNull` statement, so it never overwrites a mark another write has just set.
 *
 * Quiet saves (saveQuietly, the body re-sanitizing command) fire no event, so they never move it.
 */
trait TracksContentChange
{
    /**
     * The columns of this row the page shows as its main content.
     *
     * @return list<string>
     */
    abstract public function contentFields(): array;

    public function initializeTracksContentChange(): void
    {
        $this->mergeCasts(['content_changed_at' => 'datetime']);
    }

    public static function bootTracksContentChange(): void
    {
        static::saving(static function (self $row): void {
            // A new row starts marked (unless its writer gave a mark); a stored one moves on content only.
            $moves = $row->exists
                ? $row->isDirty($row->contentFields())
                : $row->getAttribute('content_changed_at') === null;
            if ($moves) {
                $row->setAttribute('content_changed_at', $row->freshTimestamp());
            }
        });

        // Runs only when a column really changed. getOriginal() still holds the values read before
        // this save here (the model syncs them after `saved`).
        static::updated(static function (self $row): void {
            $before = $row->getRawOriginal('updated_at');
            if ($row->getAttribute('content_changed_at') !== null || $before === null) {
                return;
            }

            $filled = static::query()
                ->whereKey($row->getKey())
                ->whereNull('content_changed_at')
                ->toBase()
                ->update(['content_changed_at' => $before]);
            if ($filled > 0) {
                $row->setAttribute('content_changed_at', $row->getOriginal('updated_at'));
                $row->syncOriginalAttribute('content_changed_at');
            }
        });
    }

    /**
     * When this language's content last changed, as the page tells it: the mark (updated_at for a
     * row that has none yet), never earlier than the owner went public. An empty publish time is
     * left out, as PostgreSQL's greatest() leaves out NULL (the sitemap reads the same rule in SQL).
     */
    public function contentDate(?CarbonInterface $publishedAt): ?Carbon
    {
        $mark = $this->getAttribute('content_changed_at') ?? $this->getAttribute('updated_at');
        $mark = $mark instanceof CarbonInterface ? Carbon::instance($mark) : null;
        if ($publishedAt !== null && ($mark === null || $mark->lessThan($publishedAt))) {
            return Carbon::instance($publishedAt);
        }

        return $mark;
    }
}

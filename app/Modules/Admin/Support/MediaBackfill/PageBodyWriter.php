<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\MediaBackfill;

use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Shared\Support\Cast;
use InvalidArgumentException;

/**
 * The media-refs backfill's write of page bodies (a post's, a pending change's, a shared block's):
 * through the model, so its sanitizing cast runs as on every body write, as a quiet save with
 * timestamps off, one per row (ResanitizePageBodies' road). Each row is written only while it still
 * holds the body that was read (under the batch's row lock it always does).
 *
 * Kept apart from MediaRefsBackfill, which also reads the catalog's tables: the quiet save here
 * touches page bodies only.
 */
final class PageBodyWriter
{
    /** The tables it writes. */
    public const array TABLES = ['entry_translations', 'entry_drafts', 'content_block_translations'];

    /**
     * @param  array<int, array{0: string, 1: string}>  $docs  row id => [the new body, the one read] as JSON
     * @return list<int> the ids written
     */
    public static function write(string $table, array $docs): array
    {
        $model = match ($table) {
            'entry_translations' => EntryTranslation::class,
            'entry_drafts' => EntryDraft::class,
            'content_block_translations' => ContentBlockTranslation::class,
            default => throw new InvalidArgumentException("Not a page-body table: {$table}."),
        };
        $column = $table === 'entry_drafts' ? 'content' : 'body';
        $written = [];
        // only the id and the body are read, so only the body is written (the title and excerpt,
        // whose storefront search words the model events keep, are never touched)
        foreach ($model::query()->whereKey(array_keys($docs))->get(['id', $column]) as $row) {
            $id = Cast::int($row->getKey());
            [$new, $old] = $docs[$id];
            if ($row->getRawOriginal($column) !== $old) {
                continue;
            }
            $row->setAttribute($column, json_decode($new, true, 512, JSON_THROW_ON_ERROR));
            $row->timestamps = false;
            $row->saveQuietly();
            $written[] = $id;
        }

        return $written;
    }
}

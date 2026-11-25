<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use Illuminate\Support\Facades\DB;

/**
 * The library's "in use" row of an entry's featured / share picture (`mediables`, collection
 * `cover`, owner the entry). The picture is named by its storage key inside entries.meta
 * (`image_url`), which no index serves, so the library's use chips, its «Đang dùng / Chưa dùng»
 * filter and the drawer read this row instead of every entry's meta: the anti-scan contract at
 * tenant scale, as BlockMediaUsage keeps it for block pictures.
 *
 * sync() makes the entry's cover rows say what its meta says now: none without a picture, else
 * the one row naming the site's media with that key (the Trash included, so a restorable picture
 * keeps its use). The Entry observer calls it when an entry is created with a picture or its
 * picture changes, inside that save's transaction, so every road that saves an entry (the editor,
 * an approval, a revision restore, a copy, the content pack) keeps it; media:reconcile-content-usage
 * rebuilds it for content written past the model. An unchanged save writes nothing.
 *
 * Stateless, so it is Octane-safe.
 */
final class EntryCoverUsage
{
    public const string COLLECTION = 'cover';

    public static function sync(Entry $entry): void
    {
        $key = self::keyOf($entry->meta);
        $rows = Mediable::query()
            ->where('mediable_type', 'entry')
            ->where('mediable_id', $entry->id)
            ->where('collection', self::COLLECTION);

        if ($key === null) {
            $rows->delete();

            return;
        }

        $found = Media::withTrashed()->where('storage_key', $key)->value('id');
        if (! is_numeric($found)) {
            $rows->delete(); // a key no file of this site has (an outside address, a file purged since)

            return;
        }
        $media = (int) $found;

        $rows->where(fn ($query) => $query->whereNull('media_id')->orWhere('media_id', '!=', $media))->delete();
        DB::table('mediables')->insertOrIgnore([
            'media_id' => $media,
            'mediable_type' => 'entry',
            'mediable_id' => $entry->id,
            'site_id' => $entry->site_id,
            'collection' => self::COLLECTION,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The picture's storage key in an entry's meta, null when it has none. */
    public static function keyOf(mixed $meta): ?string
    {
        $key = is_array($meta) ? ($meta['image_url'] ?? null) : null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}

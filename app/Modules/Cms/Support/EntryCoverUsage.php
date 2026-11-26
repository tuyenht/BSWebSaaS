<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Support\MediaRefs;
use Illuminate\Support\Facades\DB;

/**
 * The library's "in use" row of an entry's featured / share picture (`mediables`, collection
 * `cover`, owner the entry). The picture is named by entries.cover_media_id or, until every cover is
 * converted, by its storage key inside entries.meta (`image_url`), which no index serves; the id
 * wins when it names a file of this site, the key is the fallback (EntryCover). The library's use chips, its «Đang dùng / Chưa dùng»
 * filter and the drawer read this row instead of every entry's meta: the anti-scan contract at
 * tenant scale, as BlockMediaUsage keeps it for block pictures.
 *
 * sync() makes the entry's cover rows say what its cover says now: none without a picture, else
 * the one row naming the site's media with that id or key (the Trash included, so a restorable picture
 * keeps its use). The Entry observer calls it when an entry is created with a picture or its
 * picture changes or it comes back from the Trash, inside that save's transaction, so every road that saves an entry (the editor,
 * an approval, a revision restore, a copy, the content pack) keeps it; media:reconcile-content-usage
 * rebuilds it for content written past the model. An unchanged save writes nothing.
 *
 * Stateless, so it is Octane-safe.
 */
final class EntryCoverUsage
{
    public const string COLLECTION = 'cover';

    /**
     * The id of the file an entry `e` names as its cover, the Trash included: the one its
     * cover_media_id names, else the one with the key in its meta, else NULL.
     */
    private const string COVER_ID_SQL = <<<'SQL'
        COALESCE(
            (SELECT m.id FROM media m WHERE m.site_id = e.site_id AND m.id = e.cover_media_id),
            (SELECT m.id FROM media m WHERE m.site_id = e.site_id AND m.storage_key = e.meta->>'image_url')
        )
        SQL;

    public static function sync(Entry $entry): void
    {
        $id = MediaRefs::id($entry->getAttribute('cover_media_id'));
        $key = self::keyOf($entry->meta);
        $rows = Mediable::query()
            ->where('mediable_type', 'entry')
            ->where('mediable_id', $entry->id)
            ->where('collection', self::COLLECTION);

        if ($id === null && $key === null) {
            $rows->delete();

            return;
        }

        // one read for both shapes: the file the id names, else the one with the old key
        $found = Media::withTrashed()
            ->where(fn ($query) => $query->when($id !== null, fn ($q) => $q->orWhere('id', $id))->when($key !== null, fn ($q) => $q->orWhere('storage_key', $key)))
            ->get(['id']);
        $match = ($id !== null ? $found->firstWhere('id', $id) : null) ?? $found->first();
        if ($match === null) {
            $rows->delete(); // neither names a file of this site (an outside address, a file purged since)

            return;
        }
        $media = $match->id;

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

    /**
     * Rebuilds the current site's cover rows from its entries' covers in two set-based statements,
     * the Trash included on both sides (a post in the Trash still reserves its picture, and a
     * picture in the Trash still has its use): one INSERT … SELECT finding each entry's file by
     * cover_media_id (primary key), else by (site_id, storage_key) from meta (UNIQUE), adds the
     * rows that are missing, then one DELETE drops every cover row
     * that the entry's current meta no longer names (another file, no picture, a gone entry, a
     * purged file's NULL). Not chunked: each statement is a single join over the site's entries
     * (site_id index) and media (UNIQUE site_id+storage_key), cheap next to a round trip per entry.
     *
     * Insert first, delete second, each reading the meta as it is when that statement starts: a
     * cover changed by a save that commits meanwhile is never undone (its new row is not visible
     * to an earlier snapshot, so the DELETE cannot take it, and its old row is dropped by that save
     * or by the DELETE). The worst a race leaves is one extra row for the old picture, which keeps
     * that file "used" until the next save or run, never a picture unprotected.
     *
     * Runs inside the site's tenancy transaction (RLS); the explicit site_id keeps the indexes in
     * play and matches the global scope's contract.
     *
     * @return array{inserted: int, deleted: int}
     */
    public static function reconcileSite(int $siteId): array
    {
        $cover = self::COVER_ID_SQL;
        $inserted = DB::affectingStatement(<<<SQL
            INSERT INTO mediables (media_id, mediable_type, mediable_id, site_id, collection, sort_order, created_at, updated_at)
            SELECT c.media_id, 'entry', c.id, c.site_id, ?, 0, now(), now()
            FROM (
                SELECT e.id, e.site_id, {$cover} AS media_id
                FROM entries e
                WHERE e.site_id = ? AND (e.cover_media_id IS NOT NULL OR e.meta->>'image_url' <> '')
            ) c
            WHERE c.media_id IS NOT NULL
            ON CONFLICT DO NOTHING
            SQL, [self::COLLECTION, $siteId]);

        $deleted = DB::affectingStatement(<<<SQL
            DELETE FROM mediables mb
            WHERE mb.site_id = ? AND mb.mediable_type = 'entry' AND mb.collection = ?
              AND NOT EXISTS (
                SELECT 1 FROM entries e
                WHERE e.id = mb.mediable_id AND e.site_id = mb.site_id AND {$cover} = mb.media_id
              )
            SQL, [$siteId, self::COLLECTION]);

        return ['inserted' => $inserted, 'deleted' => $deleted];
    }

    /** The picture's storage key in an entry's meta, null when it has none. */
    public static function keyOf(mixed $meta): ?string
    {
        $key = is_array($meta) ? ($meta['image_url'] ?? null) : null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}

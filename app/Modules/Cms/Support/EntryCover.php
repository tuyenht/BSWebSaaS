<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaRefs;
use Illuminate\Database\Eloquent\Builder;

/**
 * An entry's cover (its featured / share picture) as every reader sees it while covers move from an
 * address to a row: `entries.cover_media_id` first (the website's file outside the Trash that it
 * names), else the old `meta.image_url` value (a storage key, a glyph name or an outside https
 * address), else none. The value comes back in the old string shape, so the code that draws it is
 * unchanged: a key, a glyph, an address or ''.
 *
 * An id that names no live file of this website (trashed, purged, another website's) falls back to
 * meta.image_url, and to no picture when that is empty.
 *
 * Three ways to read it, each at the reader's existing query cost:
 *  - a correlated subselect in the reader's own query (keySubquery / keySql, read back by value());
 *  - one MediaRefs batch for a page of entries (refs, then value($entry, $refs));
 *  - shared fields of a draft, revision or pending change (resolve).
 *
 * Stateless, so it is Octane-safe.
 */
final class EntryCover
{
    /** The column the subselect adds: the storage key the row's cover_media_id names. */
    public const string KEY = 'cover_key';

    /**
     * The storage key of the live file of the current website that entries.cover_media_id names,
     * for addSelect([EntryCover::KEY => EntryCover::keySubquery()]). The Media scopes add
     * site_id = ? and deleted_at IS NULL; the lookup is the media primary key.
     *
     * @return Builder<Media>
     */
    public static function keySubquery(string $table = 'entries'): Builder
    {
        return Media::query()
            ->select('media.storage_key')
            ->whereColumn('media.id', $table.'.cover_media_id')
            ->whereColumn('media.site_id', $table.'.site_id')
            ->limit(1);
    }

    /**
     * The same subselect in raw SQL for hand-written queries, $alias being the entries alias.
     * The site comes from the entry row; RLS keeps both sides to the current website.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function keySql(string $alias): string
    {
        return "(select m.storage_key from media m where m.site_id = {$alias}.site_id and m.id = {$alias}.cover_media_id and m.deleted_at is null)";
    }

    /**
     * The one batch for a page of entries: every cover_media_id, plus (with $keys) every old
     * key-shaped meta.image_url so a reader also gets its width. No query when none names a file.
     *
     * @param  iterable<Entry>  $entries
     */
    public static function refs(iterable $entries, bool $keys = true): MediaRefs
    {
        $ids = [];
        $old = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->getAttribute('cover_media_id');
            if ($keys) {
                $old[] = self::metaValue($entry->getAttribute('meta'));
            }
        }

        return MediaRefs::rows($ids, [], $old);
    }

    /**
     * The entry's cover in the old string shape: the key of the file its cover_media_id names (from
     * $refs, else from the KEY subselect when the reader's query added it), else meta.image_url.
     */
    public static function value(Entry $entry, ?MediaRefs $refs = null): string
    {
        if ($refs !== null) {
            return self::resolve($entry->getAttribute('cover_media_id'), $entry->getAttribute('meta'), $refs);
        }
        $key = $entry->getAttribute(self::KEY);
        if (is_string($key) && $key !== '') {
            return $key;
        }

        return self::metaValue($entry->getAttribute('meta'));
    }

    /**
     * A cover from stored shared fields (an entry, a draft, a revision snapshot, a pending change):
     * the key of the live file the id names, else the meta's image_url, else ''.
     */
    public static function resolve(mixed $coverMediaId, mixed $meta, MediaRefs $refs): string
    {
        $row = $refs->byId($coverMediaId);
        if ($row !== null && $row->storage_key !== '') {
            return $row->storage_key;
        }

        return self::metaValue($meta);
    }

    /** The old cover value inside a meta array: a non-empty string, else ''. */
    public static function metaValue(mixed $meta): string
    {
        $value = is_array($meta) ? ($meta['image_url'] ?? null) : null;

        return is_string($value) ? $value : '';
    }
}

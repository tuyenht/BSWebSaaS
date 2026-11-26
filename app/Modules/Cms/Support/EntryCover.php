<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Entry;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaRefs;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * An entry's cover (its featured / share picture) as every reader sees it while covers move from an
 * address to a row: with `entries.cover_media_id` set, the website's file outside the Trash that it
 * names; with it NULL, the old `meta.image_url` value (a storage key, a glyph name or an outside
 * https address); else none. The value comes back in the old string shape, so the code that draws it is
 * unchanged: a key, a glyph, an address or ''.
 *
 * A cover_media_id that is set decides on its own: when it names no live file of this website
 * (trashed, purged, another website's) the entry has no cover, and meta.image_url is never read.
 * The old value counts only while cover_media_id is NULL.
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
     * The whole cover in raw SQL, in the old string shape: the key keySql() finds when
     * cover_media_id is set (NULL when it names no live file: no cover), else meta.image_url.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function valueSql(string $alias): string
    {
        return "(case when {$alias}.cover_media_id is null then {$alias}.meta->>'image_url' else ".self::keySql($alias).' end)';
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
     * The entry's cover in the old string shape. With cover_media_id set: the key of the live file
     * it names (from $refs, else from the KEY subselect the reader's query added), '' when it names
     * none. With cover_media_id NULL: meta.image_url.
     *
     * A reader that adds the KEY subselect must also select entries.cover_media_id, or the id could
     * not be told apart from no id: that mistake throws in local and testing (CI catches it) and, in
     * production, is reported and draws no cover, so a missing picture never takes a page down.
     */
    public static function value(Entry $entry, ?MediaRefs $refs = null): string
    {
        $attributes = $entry->getAttributes();
        if ($refs === null && array_key_exists(self::KEY, $attributes) && ! array_key_exists('cover_media_id', $attributes)) {
            return self::misuse('A query that reads EntryCover::KEY must also select entries.cover_media_id.');
        }
        $id = $attributes['cover_media_id'] ?? null;
        if (! self::named($id)) {
            return self::metaValue($entry->getAttribute('meta'));
        }
        if ($refs !== null) {
            return self::live($refs->byId($id));
        }
        if (! array_key_exists(self::KEY, $attributes)) {
            return self::misuse('An entry cover named by id needs the EntryCover::KEY subselect or a MediaRefs batch.');
        }
        $key = $attributes[self::KEY];

        return is_string($key) ? $key : '';
    }

    /**
     * A cover from stored shared fields (an entry, a draft, a revision snapshot, a pending change):
     * with an id, the key of the live file it names, else ''; with none, the meta's image_url.
     */
    public static function resolve(mixed $coverMediaId, mixed $meta, MediaRefs $refs): string
    {
        return self::named($coverMediaId) ? self::live($refs->byId($coverMediaId)) : self::metaValue($meta);
    }

    /** Whether a stored cover_media_id names a row (any value but NULL), so meta.image_url is not read. */
    public static function named(mixed $coverMediaId): bool
    {
        return $coverMediaId !== null && $coverMediaId !== '';
    }

    /** The live row's key, '' for a cover id that names no live file. */
    private static function live(?Media $row): string
    {
        return $row !== null ? $row->storage_key : '';
    }

    /** The old cover value inside a meta array: a non-empty string, else ''. */
    public static function metaValue(mixed $meta): string
    {
        $value = is_array($meta) ? ($meta['image_url'] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    /** A reader built the query wrong: loud where tests run, a reported error and no cover elsewhere. */
    private static function misuse(string $message): string
    {
        $error = new LogicException($message);
        if (app()->environment('local', 'testing')) {
            throw $error;
        }
        report($error);

        return '';
    }
}

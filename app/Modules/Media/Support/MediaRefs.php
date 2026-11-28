<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * The media rows a page names, read in ONE query whatever shape names them: a bigint id (a typed
 * column such as entries.cover_media_id), a ulid (JSON that reaches the browser) or, until every
 * stored address is converted, an old storage key. Only the current website's files outside the
 * Trash are found (the Media model's site scope and soft delete: site_id = ? AND deleted_at IS NULL),
 * so a value naming another website's file or a trashed one simply finds nothing.
 *
 * A reader collects every value of a page first, asks once, then looks each value up here: the new
 * shape first, then the old one, at the cost of one query (none when nothing is named).
 *
 * A value object built per call, so it is Octane-safe.
 */
final readonly class MediaRefs
{
    /** The columns every reader gets (callers may ask for more, e.g. alt and title). */
    public const array COLUMNS = ['id', 'ulid', 'storage_key', 'mime', 'width', 'height'];

    private const string ULID = '/\A[0-9A-HJKMNP-TV-Z]{26}\z/i';

    /** A storage key is never longer than this (longer strings are never a key and are not sent). */
    private const int KEY_MAX = 512;

    /**
     * @param  array<int, Media>  $byId
     * @param  array<string, Media>  $byUlid  upper-case ulid => row
     * @param  array<string, Media>  $byKey
     */
    private function __construct(private array $byId, private array $byUlid, private array $byKey) {}

    /** Nothing named, nothing read. */
    public static function none(): self
    {
        return new self([], [], []);
    }

    /**
     * One query for every id, ulid and storage key given (values of the wrong shape are left out:
     * an id that is not a positive integer, a string that is not a ulid, an empty string or a full
     * address as a key). No query when nothing is left.
     *
     * @param  iterable<mixed>  $ids
     * @param  iterable<mixed>  $ulids
     * @param  iterable<mixed>  $keys
     * @param  list<string>  $columns  extra columns beyond COLUMNS
     * @param  bool  $withTrashed  also the files in the Trash (a backfill that names them by row; readers never)
     * @param  bool  $lock  FOR KEY SHARE, so a purge of a file found waits for the caller's transaction
     */
    public static function rows(iterable $ids, iterable $ulids = [], iterable $keys = [], array $columns = [], bool $withTrashed = false, bool $lock = false): self
    {
        $idList = [];
        foreach ($ids as $id) {
            $id = self::id($id);
            if ($id !== null) {
                $idList[$id] = $id;
            }
        }
        $ulidList = [];
        foreach ($ulids as $ulid) {
            if (is_string($ulid) && preg_match(self::ULID, $ulid) === 1) {
                $ulidList[strtoupper($ulid)] = true;
            }
        }
        $keyList = [];
        foreach ($keys as $key) {
            if (is_string($key) && self::looksLikeKey($key)) {
                $keyList[$key] = true;
            }
        }
        if ($idList === [] && $ulidList === [] && $keyList === []) {
            return self::none();
        }

        $rows = ($withTrashed ? Media::withTrashed() : Media::query())
            ->where(function (Builder $query) use ($idList, $ulidList, $keyList): void {
                if ($idList !== []) {
                    $query->orWhereIn('id', array_values($idList));
                }
                if ($ulidList !== []) {
                    $query->orWhereIn('ulid', array_keys($ulidList));
                }
                if ($keyList !== []) {
                    $query->orWhereIn('storage_key', array_map('strval', array_keys($keyList)));
                }
            })
            ->when($lock, fn (Builder $query) => $query->lock('for key share'))
            ->get(array_values(array_unique([...self::COLUMNS, ...$columns])));

        $byId = [];
        $byUlid = [];
        $byKey = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
            $byUlid[strtoupper($row->ulid)] = $row;
            $byKey[$row->storage_key] = $row;
        }

        return new self($byId, $byUlid, $byKey);
    }

    /** A stored id as a positive integer, null for anything else. */
    public static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Whether a stored string has the shape of a storage key: a path with a slash and no scheme.
     * A glyph name (no slash) and a full address (a scheme) are not.
     */
    public static function looksLikeKey(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= self::KEY_MAX
            && str_contains($value, '/') && ! str_contains($value, '://');
    }

    public function byId(mixed $id): ?Media
    {
        $id = self::id($id);

        return $id !== null ? ($this->byId[$id] ?? null) : null;
    }

    public function byUlid(mixed $ulid): ?Media
    {
        return is_string($ulid) ? ($this->byUlid[strtoupper($ulid)] ?? null) : null;
    }

    public function byKey(mixed $key): ?Media
    {
        return is_string($key) ? ($this->byKey[$key] ?? null) : null;
    }
}

<?php

namespace App\Modules\Admin\Support;

use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the media library page by page from a cursor (the site's files, newest first by default).
 * Rows are addressed by their public ulid only: the cursor is a ulid, the tie-break is the ulid, so
 * no bigint id ever reaches the browser. Every query goes through the Media model's site scope (RLS
 * behind it), so a ulid of another site never resolves.
 */
final class MediaLibraryDirectory
{
    public const array PER_PAGE = [24, 48, 96];

    /** Sort key => [column expression, direction]. */
    public const array SORTS = [
        'new' => ['created_at', 'desc'],
        'old' => ['created_at', 'asc'],
        'az' => ["coalesce(original_filename, '')", 'asc'],
        'size' => ['size', 'desc'],
    ];

    public const array KINDS = ['img', 'pdf'];

    /** Format chip => the mimes it stands for. */
    public const array FORMATS = [
        'jpg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
    ];

    public const array USES = ['used', 'unused'];

    public const int SEARCH_MAX = 100;

    public function __construct(private MediaUsageLocator $usage) {}

    /**
     * The files of one place (all, unfiled, one folder, or the Trash) matching the conditions.
     *
     * @param  array{folder: int|null, unfiled: bool, trash: bool, search: string, kinds: list<string>, formats: list<string>, uses: list<string>}  $f
     * @return Builder<Media>
     */
    public function filtered(array $f): Builder
    {
        $query = $f['trash'] ? Media::onlyTrashed() : Media::query();

        if (! $f['trash']) {
            if ($f['unfiled']) {
                $query->whereNull('folder_id');
            } elseif ($f['folder'] !== null) {
                $query->where('folder_id', $f['folder']);
            }
        }

        $kinds = array_values(array_intersect($f['kinds'], self::KINDS));
        if (count($kinds) === 1) {
            $kinds[0] === 'pdf'
                ? $query->where('mime', 'application/pdf')
                : $query->where('mime', '<>', 'application/pdf');
        }

        $mimes = [];
        foreach ($f['formats'] as $format) {
            $mimes = [...$mimes, ...(self::FORMATS[$format] ?? [])];
        }
        if ($mimes !== []) {
            $query->whereIn('mime', $mimes);
        }

        $uses = array_values(array_intersect($f['uses'], self::USES));
        if (! $f['trash'] && count($uses) === 1) {
            $this->usage->constrain($query, $uses[0] === 'used');
        }

        $term = mb_substr(trim($f['search']), 0, self::SEARCH_MAX);
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('original_filename', 'ilike', $like)
                    ->orWhereRaw('exists (select 1 from jsonb_each_text(media.alt) as a where a.value ilike ?)', [$like]);
            });
        }

        return $query;
    }

    /**
     * One page after (older) or before (newer) a row, in the chosen order. A cursor that no longer
     * resolves in this list (trashed in another tab, filtered out, forged) gives the first page and
     * `lost` = true, so the caller can say "page 1" again.
     *
     * @param  Builder<Media>  $query
     * @return array{rows: Collection<int, Media>, older: string|null, newer: string|null, lost: bool}
     */
    public function page(Builder $query, string $sort, int $per, string $after, string $before): array
    {
        [$column, $dir] = self::SORTS[$sort] ?? self::SORTS['new'];
        $cursorUlid = $after !== '' ? $after : $before;
        $cursor = $cursorUlid === '' ? null : (clone $query)->where('ulid', $cursorUlid)
            ->selectRaw($column.' as k, ulid')->toBase()->first();
        $backwards = $after === '' && $before !== '' && $cursor !== null;

        $rows = (clone $query)->select(['id', 'ulid', 'storage_key', 'original_filename', 'mime', 'size', 'width', 'height', 'alt', 'folder_id', 'created_at', 'deleted_at']);
        if ($cursor !== null) {
            // forward on a desc order reads smaller keys; going back flips it
            $smaller = ($dir === 'desc') !== $backwards;
            $rows->whereRaw('('.$column.', ulid) '.($smaller ? '<' : '>').' (?, ?)', [$cursor->k, $cursor->ulid]);
        }
        $order = $backwards ? ($dir === 'desc' ? 'asc' : 'desc') : $dir;
        $rows = $rows->orderByRaw($column.' '.$order)->orderBy('ulid', $order)->limit($per + 1)->get();

        $more = $rows->count() > $per;
        $rows = $rows->take($per);
        if ($backwards) {
            $rows = $rows->reverse();
        }
        $rows = $rows->values();

        $hasNext = $backwards ? true : $more;
        $hasPrev = $backwards ? $more : $cursor !== null;
        $first = $rows->first();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'older' => $hasNext && $last instanceof Media ? $last->ulid : null,
            'newer' => $hasPrev && $first instanceof Media ? $first->ulid : null,
            'lost' => $cursorUlid !== '' && $cursor === null,
        ];
    }

    /**
     * How many files match and their total bytes, in one statement (the footer and "N trong M").
     *
     * @param  Builder<Media>  $query
     * @return array{n: int, bytes: int}
     */
    public function totals(Builder $query): array
    {
        $row = (clone $query)->toBase()->selectRaw('count(*) as n, coalesce(sum(size), 0) as bytes')->first();

        return ['n' => Cast::int($row->n ?? 0), 'bytes' => Cast::int($row->bytes ?? 0)];
    }

    /**
     * Live files per folder (null key = unfiled) and the Trash total, in one statement.
     *
     * @return array{byFolder: array<int, int>, unfiled: int, all: int, trash: int}
     */
    public function counts(bool $withTrash): array
    {
        $rows = DB::table('media')
            ->where('site_id', app(CurrentSite::class)->id())
            ->groupByRaw('grouping sets ((folder_id, deleted_at is null), (deleted_at is null))')
            ->get([DB::raw('folder_id'), DB::raw('deleted_at is null as live'), DB::raw('grouping(folder_id) as g'), DB::raw('count(*) as n')]);

        $byFolder = [];
        $unfiled = $all = $trash = 0;
        foreach ($rows as $row) {
            $n = Cast::int($row->n);
            if (Cast::int($row->g) === 1) {
                $row->live ? $all = $n : $trash = $n;

                continue;
            }
            if (! $row->live) {
                continue;
            }
            $row->folder_id === null ? $unfiled = $n : $byFolder[Cast::int($row->folder_id)] = $n;
        }

        return ['byFolder' => $byFolder, 'unfiled' => $unfiled, 'all' => $all, 'trash' => $withTrash ? $trash : 0];
    }
}

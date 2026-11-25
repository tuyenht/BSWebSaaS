<?php

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
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

    /** «Thời gian» by upload day: every time, today, the last 7 / 30 / 90 days, or RANGE (the two dates decide). */
    public const array WHEN = ['', 'today', '7', '30', '90', self::RANGE];

    public const string RANGE = 'range';

    public const int SEARCH_MAX = 100;

    public function __construct(private MediaUsageLocator $usage) {}

    /**
     * The files of one place (all, unfiled, one folder with its sub-folders, or the Trash) matching
     * the conditions. `folders` lists the folder and its sub-folders; null is every file. `by` names
     * the uploaders (user ids; none = anyone); `since` / `until` bound the upload time (until is the
     * first instant after the window). Each is a plain condition on the site's own rows and keeps the
     * cursor pages. The (site_id, created_at) index serves the time window; no index leads with
     * created_by yet, so «Người tạo» reads the site's rows in upload order and keeps that person's.
     *
     * @param  array{folders: list<int>|null, unfiled: bool, trash: bool, search: string, kinds: list<string>, formats: list<string>, uses: list<string>, by?: list<int>, since?: CarbonImmutable|null, until?: CarbonImmutable|null}  $f
     * @return Builder<Media>
     */
    public function filtered(array $f): Builder
    {
        $query = $f['trash'] ? Media::onlyTrashed() : Media::query();

        if (! $f['trash']) {
            if ($f['unfiled']) {
                $query->whereNull('folder_id');
            } elseif ($f['folders'] !== null) {
                $query->whereIn('folder_id', $f['folders']);
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
            $this->usage->constrain($query, $uses[0] === 'used', liveOwners: true);
        }

        if (($f['by'] ?? []) !== []) {
            $query->whereIn('created_by', $f['by']);
        }
        if (($f['since'] ?? null) !== null) {
            $query->where('created_at', '>=', $f['since']);
        }
        if (($f['until'] ?? null) !== null) {
            $query->where('created_at', '<', $f['until']);
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
     * The upload-time window of a «Thời gian» choice by the site's own calendar (as the post list
     * reads it): today from its midnight, the last N days counting today, a range from the start of
     * its first day to the end of its last. [since, until) — either end null when open.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public static function window(string $when, string $from = '', string $to = ''): array
    {
        $zone = app(SiteTime::class)->zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        if ($when === self::RANGE) {
            if ($from !== '' && $to !== '' && $from > $to) {
                [$from, $to] = [$to, $from]; // «Từ» after «Đến»: the same days, read the right way round
            }
            $day = fn (string $date): ?CarbonImmutable => $date === '' ? null : CarbonImmutable::parse($date, $zone)->startOfDay();

            return [$day($from)?->utc(), $day($to)?->addDay()->utc()];
        }
        if ($when === '' || ! in_array($when, self::WHEN, true)) {
            return [null, null];
        }
        $start = $when === 'today' ? $today : $today->subDays(Cast::int($when) - 1);

        return [$start->utc(), $today->addDay()->utc()];
    }

    /**
     * The chosen «Người tạo» people who uploaded a file here (live or in the Trash), by ulid, with
     * their id, ulid and name only. Anyone else's ulid (another site's member, one made up) is left
     * out, so no other site's name reaches the page and it never counts as a condition.
     *
     * @param  list<string>  $ulids
     * @return Collection<int, User>
     */
    public function uploadersAmong(array $ulids): Collection
    {
        return User::query()->whereIn('ulid', $ulids)
            ->whereExists(Media::withTrashed()->selectRaw('1')->whereColumn('media.created_by', 'users.id'))
            ->get(['id', 'ulid', 'name']);
    }

    /**
     * How many of a query's files each uploader sent (user id => count), in one statement: the
     * «Người tạo» counts, read with every condition but that one.
     *
     * @param  Builder<Media>  $query
     * @return array<int, int>
     */
    public function uploaderCounts(Builder $query): array
    {
        $out = [];
        foreach ((clone $query)->toBase()->whereNotNull('created_by')->groupBy('created_by')->get(['created_by', DB::raw('count(*) as n')]) as $row) {
            $out[Cast::int($row->created_by)] = Cast::int($row->n);
        }

        return $out;
    }

    /**
     * How many of a query's files each «Thời gian» choice holds, in one statement (RANGE: the dates
     * given, every file while none is): read with every condition but that one.
     *
     * @param  Builder<Media>  $query
     * @return array<int|string, int> keyed by the WHEN choice (PHP keeps '7' / '30' / '90' as int keys)
     */
    public function whenCounts(Builder $query, string $from, string $to): array
    {
        $select = [];
        $bindings = [];
        foreach (self::WHEN as $i => $when) {
            [$since, $until] = self::window($when, $from, $to);
            $parts = [];
            if ($since !== null) {
                $parts[] = 'created_at >= ?';
                $bindings[] = $since;
            }
            if ($until !== null) {
                $parts[] = 'created_at < ?';
                $bindings[] = $until;
            }
            $select[] = $parts === [] ? "count(*) as w{$i}" : 'count(*) filter (where '.implode(' and ', $parts).") as w{$i}";
        }
        $row = (clone $query)->toBase()->selectRaw(implode(', ', $select), $bindings)->first();
        $out = [];
        foreach (self::WHEN as $i => $when) {
            $out[$when] = Cast::int($row->{"w{$i}"} ?? 0);
        }

        return $out;
    }

    /**
     * Live files per folder (null key = unfiled), the Trash total and whether two or more people
     * uploaded files here (live or in the Trash: the lowest and highest uploader differ), in one
     * statement. With $uploaders, also how many files each uploader sent (the «Người tạo» list, read
     * while the filters are open only: it groups every file of the site by person).
     *
     * @return array{byFolder: array<int, int>, unfiled: int, all: int, trash: int, many: bool, uploaders: array<int, int>}
     */
    public function counts(bool $withTrash, bool $uploaders = false): array
    {
        $columns = [DB::raw('folder_id'), DB::raw('deleted_at is null as live'), DB::raw('grouping(folder_id) as g'), DB::raw('count(*) as n'),
            DB::raw('min(created_by) as lo'), DB::raw('max(created_by) as hi')];
        if ($uploaders) {
            $columns[] = DB::raw('created_by');
            $columns[] = DB::raw('grouping(created_by) as gc');
        }
        $rows = DB::table('media')
            ->where('site_id', app(CurrentSite::class)->id())
            ->groupByRaw('grouping sets ((folder_id, deleted_at is null), (deleted_at is null)'.($uploaders ? ', (created_by)' : '').')')
            ->get($columns);

        $byFolder = [];
        $people = [];
        $unfiled = $all = $trash = 0;
        $lo = $hi = null;
        foreach ($rows as $row) {
            $n = Cast::int($row->n);
            if ($uploaders && Cast::int($row->gc) === 0) {
                if ($row->created_by !== null) {
                    $people[Cast::int($row->created_by)] = $n;
                }

                continue;
            }
            if (Cast::int($row->g) === 1) {
                $row->live ? $all = $n : $trash = $n;
                if ($row->lo !== null) {
                    $lo = min($lo ?? Cast::int($row->lo), Cast::int($row->lo));
                    $hi = max($hi ?? Cast::int($row->hi), Cast::int($row->hi));
                }

                continue;
            }
            if (! $row->live) {
                continue;
            }
            $row->folder_id === null ? $unfiled = $n : $byFolder[Cast::int($row->folder_id)] = $n;
        }

        return ['byFolder' => $byFolder, 'unfiled' => $unfiled, 'all' => $all, 'trash' => $withTrash ? $trash : 0,
            'many' => $lo !== null && $lo !== $hi, 'uploaders' => $people];
    }
}

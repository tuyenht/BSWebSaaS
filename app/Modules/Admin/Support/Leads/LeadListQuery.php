<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The lead list of "Khách liên hệ" as statements: the search (name and email without diacritics,
 * the phone by its digits), the forms ticked, the time window (in the site's own day), the status
 * group, and the counts beside each of them. Every count stops at CAP + 1, so a site with millions
 * of leads never walks them all to print one number. Rows come newest first with the id as the
 * tie-break, read up to a cursor (the last row loaded), never by OFFSET.
 */
final class LeadListQuery
{
    public const int CAP = 10000;

    /** The status groups: "all" is every lead but spam (spam has its own group). */
    public const array TABS = ['all', 'new', 'read', 'archived', 'spam'];

    public const array STATUSES = ['new', 'read', 'archived', 'spam'];

    /** "Thời gian": a preset, or RANGE while the two dates decide. */
    public const array WHEN = ['', 'today', '7', '30'];

    public const string RANGE = 'range';

    /** What a row needs, and nothing more. */
    private const array ROW_COLUMNS = ['id', 'ulid', 'form_id', 'name', 'email', 'phone', 'source_url', 'ip_address', 'user_agent', 'status', 'is_spam', 'data', 'created_at'];

    /**
     * @param  list<int>  $formIds  the forms ticked (ids of this site's forms, already checked)
     */
    public function __construct(
        private readonly string $search,
        private readonly array $formIds,
        private readonly string $when,
        private readonly string $from,
        private readonly string $to,
        private readonly SiteTime $time,
    ) {}

    /**
     * The leads that match the search and the conditions, before the status group.
     *
     * @return Builder<FormSubmission>
     */
    public function base(bool $forms = true, bool $dates = true): Builder
    {
        $query = FormSubmission::query();
        $this->searchIn($query);
        if ($forms && $this->formIds !== []) {
            $query->whereIn('form_id', $this->formIds);
        }
        if ($dates) {
            $this->window($query, $this->when);
        }

        return $query;
    }

    /**
     * @param  Builder<FormSubmission>  $query
     * @return Builder<FormSubmission>
     */
    public static function inTab(Builder $query, string $tab): Builder
    {
        return $tab === 'all' ? $query->where('status', '<>', 'spam') : $query->where('status', $tab);
    }

    /**
     * Each status group's count under the search and the conditions, in one statement.
     *
     * @return array<string, int>
     */
    public function tabCounts(): array
    {
        $parts = [];
        foreach (self::TABS as $tab) {
            $parts[$tab] = self::inTab($this->base(), $tab);
        }

        return $this->bounded($parts);
    }

    /** The group's count with no search and no condition ("Tìm thấy N trong M liên hệ"). */
    public function total(string $tab): int
    {
        return $this->bounded(['total' => self::inTab(FormSubmission::query(), $tab)])['total'];
    }

    /**
     * How many leads each form holds under the search, the time and the group (null past the cap:
     * a site that large shows its forms without numbers).
     *
     * @param  list<int>  $formIds  every form of the site
     * @return array<int, int>|null
     */
    public function formCounts(string $tab, array $formIds, int $groupTotal): ?array
    {
        if ($formIds === [] || $groupTotal > self::CAP) {
            return null;
        }
        $rows = self::inTab($this->base(forms: false), $tab)->toBase()
            ->select('form_id', DB::raw('count(*) as n'))->groupBy('form_id')->limit(count($formIds))->get();
        $out = [];
        foreach ($rows as $row) {
            $out[Cast::int($row->form_id)] = Cast::int($row->n);
        }

        return $out;
    }

    /**
     * How many leads each preset of "Thời gian" holds under the search, the forms and the group.
     *
     * @return array<int|string, int>
     */
    public function whenCounts(string $tab): array
    {
        $parts = [];
        foreach (self::WHEN as $when) {
            $query = self::inTab($this->base(dates: false), $tab);
            $this->window($query, $when);
            $parts['w'.$when] = $query;
        }
        $counts = $this->bounded($parts);
        $out = [];
        foreach (self::WHEN as $when) {
            $out[$when] = $counts['w'.$when] ?? 0;
        }

        return $out;
    }

    /**
     * The rows of a group, newest first: the first $limit, or every row down to the cursor
     * (`created_at|id` of the last row loaded) when one is given, at most $max.
     *
     * @return Collection<int, FormSubmission>
     */
    public function rows(string $tab, ?string $until, int $limit, int $max): Collection
    {
        $query = self::inTab($this->base(), $tab)->select(self::ROW_COLUMNS);
        $cursor = self::cursor($until);
        if ($cursor !== null) {
            $query->whereRaw('(created_at, id) >= (?::timestamptz, ?)', $cursor);
            $limit = $max;
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * The cursor $more rows further down than $until (or than the first $first rows), or null when
     * nothing follows.
     */
    public function nextCursor(string $tab, ?string $until, int $first, int $more): ?string
    {
        $query = self::inTab($this->base(), $tab)->toBase()->select('created_at', 'id');
        $cursor = self::cursor($until);
        if ($cursor !== null) {
            $query->whereRaw('(created_at, id) < (?::timestamptz, ?)', $cursor)->limit($more);
        } else {
            $query->limit($first + $more);
        }
        $found = $query->orderByDesc('created_at')->orderByDesc('id')->get();
        $last = $found->last();

        return $last === null ? $until : CarbonImmutable::parse(Cast::string($last->created_at))->utc()->format('Y-m-d H:i:s.uP').'|'.Cast::int($last->id);
    }

    /** @return array{0: string, 1: int}|null */
    private static function cursor(?string $until): ?array
    {
        if ($until === null || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?[+-]\d{2}:\d{2})\|(\d{1,19})$/', $until, $m) !== 1) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /** @param  Builder<FormSubmission>  $query */
    private function searchIn(Builder $query): void
    {
        $words = trim($this->search);
        if ($words === '') {
            return;
        }
        $needle = VietnameseFold::like(VietnameseFold::text($words));
        $digits = preg_replace('/\D+/', '', $words) ?? '';
        $query->where(function (Builder $q) use ($needle, $digits): void {
            $q->whereRaw(VietnameseFold::sql("coalesce(name, '')").' like ?', [$needle])
                ->orWhereRaw(VietnameseFold::sql("coalesce(email, '')").' like ?', [$needle]);
            // a phone matches by its digits once three are typed: "0912 345" finds "0912345678"
            if (strlen($digits) >= 3) {
                $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%'.$digits.'%']);
            }
        });
    }

    /**
     * A time window by the site's own calendar: today from its midnight, the last 7 or 30 days back
     * from now, a range from the start of its first day to the end of its last.
     *
     * @param  Builder<FormSubmission>  $query
     */
    private function window(Builder $query, string $when): void
    {
        $now = $this->time->now();
        match ($when) {
            'today' => $query->where('created_at', '>=', $now->startOfDay()->utc()),
            '7' => $query->where('created_at', '>=', $now->subDays(7)->utc()),
            '30' => $query->where('created_at', '>=', $now->subDays(30)->utc()),
            self::RANGE => $this->range($query),
            default => null,
        };
    }

    /** @param  Builder<FormSubmission>  $query */
    private function range(Builder $query): void
    {
        if ($this->from !== '') {
            $query->where('created_at', '>=', $this->time->toStorage($this->from.' 00:00:00'));
        }
        if ($this->to !== '') {
            $query->where('created_at', '<', $this->time->toStorage($this->to.' 00:00:00')->addDay());
        }
    }

    /**
     * One statement: each query counted up to CAP + 1.
     *
     * @param  array<string, Builder<FormSubmission>>  $parts
     * @return array<string, int>
     */
    private function bounded(array $parts): array
    {
        $select = [];
        $bindings = [];
        $keys = array_keys($parts);
        foreach ($keys as $i => $key) {
            $rows = $parts[$key]->toBase()->select(DB::raw('1'))->limit(self::CAP + 1);
            $select[] = '(select count(*) from ('.$rows->toSql().") as bounded) as c{$i}";
            $bindings = [...$bindings, ...$rows->getBindings()];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);
        $out = [];
        foreach ($keys as $i => $key) {
            $out[$key] = Cast::int($row["c{$i}"] ?? 0);
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadSearchTerms;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The lead list of "Khách liên hệ" as statements: the search (each typed word starts a word of the
 * name or the email, without diacritics, or a piece of the phone's digits: LeadSearchTerms), the forms ticked, the time window (in the site's own day), the status
 * group, and the counts beside each of them. Every count stops at CAP + 1, so a site with millions
 * of leads never walks them all to print one number. Rows come newest first with the code (ulid) as the
 * tie-break, read up to a cursor (the last row loaded), never by OFFSET. Leads of a form being
 * deleted are never listed or counted (leads()).
 */
final class LeadListQuery
{
    public const int CAP = 10000;

    /** Word rows of the rarest typed word up to which a search gathers its leads first (searchIn). */
    public const int GATHER = 100000;

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
     * @param  bool  $noForms  forms were ticked but none is a form of this site: nothing matches
     * @param  list<int>|null  $hiddenFormIds  the site's forms being deleted, when the caller has read them (see leads())
     */
    public function __construct(
        private readonly string $search,
        private readonly array $formIds,
        private readonly string $when,
        private readonly string $from,
        private readonly string $to,
        private readonly SiteTime $time,
        private readonly bool $noForms = false,
        private readonly ?array $hiddenFormIds = null,
        private readonly int $gather = self::GATHER,
    ) {}

    /** @var array<string, array<string, int>> the word counts of each search read so far (termCounts()) */
    private array $termCounts = [];

    /** "Now" for the time window, when a long read must not see it move (pinNow()). */
    private ?CarbonImmutable $now = null;

    /** The last search read gathered its leads first (searchIn()): their ids are a bounded list. */
    private bool $gathers = false;

    /**
     * Fix "now" for the presets (today, 7 and 30 days), so a read in many statements — the CSV
     * file, chunk after chunk — keeps one window from its first statement to its last.
     */
    public function pinNow(): self
    {
        $this->now = $this->time->now();

        return $this;
    }

    /**
     * The ids of a tab's leads, newest first (undated ones first), when the search gathers its
     * leads (at most the gather limit of them, see searchIn()); null when there is no search or the
     * search walks the list instead. A read in many statements takes them once and then reads the
     * leads by id, so the gathering runs once and not once per statement.
     *
     * @return list<int>|null
     */
    public function gatheredIds(string $tab): ?array
    {
        if (trim($this->search) === '') {
            return null;
        }
        $query = self::inTab($this->base(), $tab)->toBase();
        if (! $this->gathers) {
            return null;
        }

        return array_values(array_map(fn (mixed $id): int => Cast::int($id), $query->orderByRaw('created_at desc nulls first')->orderByDesc('ulid')->pluck('id')->all()));
    }

    /**
     * The list a screen's filter asks for: the search, the forms ticked (by ulid, kept only when
     * they are among $forms), the time window. Forms ticked but none of them this site's (gone,
     * another website's, past the forms read) match no rows, never the whole list.
     *
     * @param  list<string>  $formUlids
     * @param  Collection<int, Form>  $forms  the site's forms (knownForms())
     * @param  list<int>  $hidden  the site's forms being deleted (knownForms())
     */
    public static function of(string $search, array $formUlids, string $when, string $from, string $to, Collection $forms, array $hidden, SiteTime $time): self
    {
        $ids = array_values($forms->whereIn('ulid', $formUlids)->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all());

        return new self(trim($search), $ids, $when, $from, $to, $time, $formUlids !== [] && $ids === [], $hidden);
    }

    /**
     * The site's forms (disabled ones too: their leads stay), each with its fields for the answers,
     * and the ids of those being deleted, whose leads no list shows, read in the same statement
     * (first, so the 200 never crowd them out).
     *
     * @return array{0: Collection<int, Form>, 1: list<int>}
     */
    public static function knownForms(): array
    {
        $read = Form::query()->withoutGlobalScope(Form::NOT_DELETING)->select(['id', 'ulid', 'key', 'name', 'status'])
            ->orderByRaw('status = ? desc', [Form::DELETING])->orderBy('id')->limit(200)->get();
        $forms = $read->filter(fn (Form $form): bool => $form->status !== Form::DELETING)->values();
        $hidden = array_values($read->filter(fn (Form $form): bool => $form->status === Form::DELETING)->map(fn (Form $form): int => $form->id)->all());
        $fields = FormField::query()->whereIn('form_id', $forms->pluck('id')->all())
            ->select(['id', 'form_id', 'key', 'type', 'label', 'options', 'sort_order'])->orderBy('sort_order')->orderBy('id')->get()->groupBy('form_id');
        foreach ($forms as $form) {
            $form->setRelation('fields', $fields->get($form->id) ?? new Collection);
        }

        return [$forms, $hidden];
    }

    /**
     * The site's leads a screen may show: none of a form being deleted, which reads as gone
     * everywhere while DeleteLargeForm deletes its leads in batches.
     *
     * Given the ids of those forms (read with the site's forms, almost always none), the filter is
     * that constant list, or nothing at all, so a count stays an index-only walk of a list index,
     * which holds no form_id. Without them, one uncorrelated subquery on the site's forms (a few
     * dozen rows), hashed once per statement: for reads that fetch the lead rows anyway (one lead,
     * the dashboard's newest ones). No query per row either way.
     *
     * @param  list<int>|null  $hiddenFormIds
     * @return Builder<FormSubmission>
     */
    public static function leads(?array $hiddenFormIds = null): Builder
    {
        $query = FormSubmission::query();
        if ($hiddenFormIds === null) {
            return $query->whereNotIn('form_submissions.form_id', Form::query()
                ->withoutGlobalScope(Form::NOT_DELETING)->where('forms.status', Form::DELETING)->select('forms.id'));
        }

        return $hiddenFormIds === [] ? $query : $query->whereNotIn('form_submissions.form_id', $hiddenFormIds);
    }

    /**
     * The leads that match the search and the conditions, before the status group.
     *
     * @return Builder<FormSubmission>
     */
    public function base(bool $forms = true, bool $dates = true): Builder
    {
        $query = self::leads($this->hiddenFormIds);
        $this->searchIn($query);
        if ($forms && $this->noForms) {
            $query->whereRaw('false');
        } elseif ($forms && $this->formIds !== []) {
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
        return $this->bounded(['total' => self::inTab(self::leads($this->hiddenFormIds), $tab)])['total'];
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
        // the group's total above already holds the forms ticked; the grouped scan does not, so it
        // is gated on the count without them
        if ($this->formIds !== [] || $this->noForms) {
            $all = $this->bounded(['all' => self::inTab($this->base(forms: false), $tab)])['all'];
            if ($all > self::CAP) {
                return null;
            }
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
     * (`created_at|ulid` of the last row loaded) when one is given, at most $max.
     *
     * @return Collection<int, FormSubmission>
     */
    public function rows(string $tab, ?string $until, int $limit, int $max): Collection
    {
        $query = self::inTab($this->base(), $tab)->select(self::ROW_COLUMNS);
        $cursor = self::cursor($until);
        if ($cursor !== null) {
            $query->whereRaw('(created_at, ulid) >= (?::timestamptz, ?)', $cursor);
            $limit = $max;
        }

        return $query->orderByDesc('created_at')->orderByDesc('ulid')->limit($limit)->get();
    }

    /**
     * The cursor $more rows further down than $until (or than the first $first rows), or null when
     * nothing follows.
     */
    public function nextCursor(string $tab, ?string $until, int $first, int $more): ?string
    {
        $query = self::inTab($this->base(), $tab)->toBase()->select('created_at', 'ulid');
        $cursor = self::cursor($until);
        if ($cursor !== null) {
            $query->whereRaw('(created_at, ulid) < (?::timestamptz, ?)', $cursor)->limit($more);
        } else {
            $query->limit($first + $more);
        }
        $found = $query->orderByDesc('created_at')->orderByDesc('ulid')->get();
        $last = $found->last();

        return $last === null ? $until : CarbonImmutable::parse(Cast::string($last->created_at))->utc()->format('Y-m-d H:i:s.uP').'|'.Cast::string($last->ulid);
    }

    /** @return array{0: string, 1: string}|null */
    private static function cursor(?string $until): ?array
    {
        if ($until === null || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?[+-]\d{2}:\d{2})\|([0-9A-Za-z]{26})$/', $until, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * Each typed word starts one of the lead's words (LeadSearchTerms), in any order: accents and case
     * are ignored, `%` and `_` are letters like any other. Every condition is a range of the word
     * table's key (leakproof, so an index condition under RLS; LeadSearchTermsIndexesTest), as the
     * products search does (ProductListQuery::whereWords).
     *
     * The rarest word decides the shape, by its word rows counted up to the gather limit + 1 (one
     * statement for every word, read once per list):
     * - none: nothing is found;
     * - at most the gather limit: its leads are gathered first, each checked for the other words, and
     *   the list reads them by id (`id = any(array(…))`), newest first;
     * - more (every word is very common): the list walks newest first through its own indexes and
     *   checks each row's words until the page or the bounded count is full (`offset 0` keeps each a
     *   probe per row), fast because matches that common are dense.
     *
     * @param  Builder<FormSubmission>  $query
     */
    private function searchIn(Builder $query): void
    {
        $this->gathers = false;
        $words = trim($this->search);
        if ($words === '') {
            return;
        }
        $needles = LeadSearchTerms::needles($words);
        if ($needles === []) {
            // nothing that can start a word ("%", only signs): nothing is found, never the whole list
            $query->whereRaw('false');

            return;
        }
        $siteId = app(CurrentSite::class)->id();
        $counts = $this->termCounts($siteId, $needles);
        asort($counts);
        $rarest = (string) array_key_first($counts);
        if (($counts[$rarest] ?? 0) === 0) {
            $query->whereRaw('false');

            return;
        }

        $others = array_slice(array_map(strval(...), array_keys($counts)), 1);
        if ($counts[$rarest] <= $this->gather) {
            [$range, $bindings] = LeadSearchTerms::range('t.term', $rarest);
            $sql = 'select t.lead_id from lead_search_terms t where t.site_id = ? and '.$range;
            $bindings = [$siteId, ...$bindings];
            foreach ($others as $needle) {
                [$also, $values] = LeadSearchTerms::range('o.term', $needle);
                $sql .= ' and exists (select 1 from lead_search_terms o where o.site_id = ? and o.lead_id = t.lead_id and '.$also.')';
                $bindings = [...$bindings, $siteId, ...$values];
            }
            $query->whereRaw("form_submissions.id = any (array({$sql}))", $bindings);
            $this->gathers = true;

            return;
        }

        foreach ([$rarest, ...$others] as $needle) {
            [$range, $bindings] = LeadSearchTerms::range('w.term', $needle);
            $query->whereExists(fn (QueryBuilder $has) => $has->selectRaw('1')->from('lead_search_terms as w')
                ->where('w.site_id', $siteId)
                ->whereColumn('w.lead_id', 'form_submissions.id')
                ->whereRaw($range, $bindings)
                ->offset(0));
        }
    }

    /**
     * How many word rows of the site start with each needle, each counted up to the gather limit + 1, in one statement.
     *
     * @param  list<string>  $needles
     * @return array<string, int>
     */
    private function termCounts(int $siteId, array $needles): array
    {
        $key = implode("\0", $needles);
        if (isset($this->termCounts[$key])) {
            return $this->termCounts[$key];
        }

        $select = [];
        $bindings = [];
        foreach ($needles as $i => $needle) {
            [$range, $values] = LeadSearchTerms::range('term', $needle);
            $select[] = "(select count(*) from (select 1 from lead_search_terms where site_id = ? and {$range} limit ?) as bounded) as c{$i}";
            $bindings = [...$bindings, $siteId, ...$values, $this->gather + 1];
        }
        $row = (array) DB::selectOne('select '.implode(', ', $select), $bindings);
        $counts = [];
        foreach ($needles as $i => $needle) {
            $counts[$needle] = Cast::int($row["c{$i}"] ?? 0);
        }

        return $this->termCounts[$key] = $counts;
    }

    /**
     * A time window by the site's own calendar: today from its midnight, the last 7 or 30 days back
     * from now, a range from the start of its first day to the end of its last.
     *
     * @param  Builder<FormSubmission>  $query
     */
    private function window(Builder $query, string $when): void
    {
        $now = $this->now ?? $this->time->now();
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
            $query->where('created_at', '<', CarbonImmutable::parse($this->to.' 00:00:00', $this->time->zone())->addDay()->utc());
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

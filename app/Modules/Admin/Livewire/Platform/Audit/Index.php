<?php

namespace App\Modules\Admin\Livewire\Platform\Audit;

use App\Modules\Admin\Support\AuditCatalog;
use App\Modules\Admin\Support\AuditEntryView;
use App\Modules\Audit\AuditService;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\BoundedRead;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Operator Audit log (platform plane, read-only). `audit_logs` is GLOBAL (D6 — no RLS; nullable
 * site_id for context) so operators get cross-site visibility directly. Gated by platform.audit.view,
 * re-checked in boot() on every request, actions included.
 *
 * Built for a table of many millions of rows:
 * - Pages move by the last row seen (keyset on created_at, id), never by counting or skipping rows.
 *   The URL carries that row's ulid, never its id (the cursor Laravel builds would encode the id).
 * - Every read runs under a 3-second limit (BoundedRead): a free-text search no index can serve
 *   gives up with a plain message instead of holding the worker.
 * - The "Người làm" choices come from the newest rows that match the other filters, not from a
 *   scan of the whole table; the Website choices are searched by name.
 *
 * Search ignores diacritics and case, and looks at the action's sentence, the people's names and
 * emails, the website's name, the IP address and the row's details.
 */
#[Layout('admin::layouts.platform')]
final class Index extends Component
{
    /** @var list<int> */
    public const array PER_PAGE = [10, 20, 50];

    /** The "Thời gian" choices; RANGE means the two dates below them are in use. */
    public const array WHEN = ['', 'today', '7', '30', '90'];

    public const string RANGE = 'range';

    /** The "Người làm" choices are the people on this many newest matching rows. */
    public const int PEOPLE_SAMPLE = 500;

    public const int SITE_CHOICES = 20;

    /** The value of the "Nền tảng" choice under Website: rows with no website. */
    public const string NO_SITE = 'platform';

    private const int SEARCH_MAX = 100;

    /** At most this many people or websites can be chosen at once. */
    private const int CHOSEN_MAX = 50;

    /** The details searched: those the screen shows as written text (AuditEntryView). */
    private const string SEARCHED_DETAILS = "concat_ws(' ', meta->>'reason', meta->>'hostname', meta->>'role', meta->>'name', meta->>'member', meta->>'was', meta->>'value')";

    #[Url]
    public string $q = '';

    /** @var list<string> group keys of AuditCatalog::GROUPS */
    #[Url]
    public array $groups = [];

    /** @var list<string> site ulids, or NO_SITE */
    #[Url]
    public array $sites = [];

    /** @var list<string> user ulids */
    #[Url]
    public array $people = [];

    #[Url]
    public string $when = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public bool $support = false;

    #[Url]
    public string $session = '';

    /** The ulid of the row the page starts after (older rows) … */
    #[Url]
    public string $after = '';

    /** … or before (newer rows). */
    #[Url]
    public string $before = '';

    #[Url]
    public int $per = 20;

    /** What the Website filter section is searching for. */
    public string $siteQuery = '';

    /** The ulid of the row whose details are open: in the URL, so a link can point at one entry. */
    #[Url]
    public string $open = '';

    public function boot(): void
    {
        $user = auth()->user();
        $platform = app(PlatformAccess::class);

        abort_unless(
            $user instanceof User && ($platform->isSuperAdmin($user) || $platform->can($user, 'platform.audit.view')),
            403,
        );
    }

    public function updated(string $property): void
    {
        $name = Str::before($property, '.');
        if ($name === 'when' && $this->when !== self::RANGE) {
            $this->from = $this->to = '';
        }
        if ($name === 'from' || $name === 'to') {
            // no preset radio stays checked while the dates decide
            $this->when = self::dateOr($this->from) !== '' || self::dateOr($this->to) !== '' ? self::RANGE : '';
        }
        if (in_array($name, ['q', 'groups', 'sites', 'people', 'when', 'from', 'to', 'support', 'session', 'per'], true)) {
            $this->firstPage();
        }
    }

    public function older(string $ulid): void
    {
        $this->after = self::ulidOr($ulid);
        $this->before = '';
    }

    public function newer(string $ulid): void
    {
        $this->before = self::ulidOr($ulid);
        $this->after = '';
    }

    public function clearFilters(): void
    {
        $this->q = $this->when = $this->from = $this->to = $this->session = $this->siteQuery = '';
        $this->groups = $this->sites = $this->people = [];
        $this->support = false;
        $this->firstPage();
    }

    public function clearSearch(): void
    {
        $this->q = '';
        $this->firstPage();
    }

    public function clearWhen(): void
    {
        $this->when = $this->from = $this->to = '';
        $this->firstPage();
    }

    public function removeGroup(string $group): void
    {
        $this->groups = array_values(array_diff($this->groups, [$group]));
        $this->firstPage();
    }

    public function removeSite(string $site): void
    {
        $this->sites = array_values(array_diff($this->sites, [$site]));
        $this->firstPage();
    }

    public function removePerson(string $person): void
    {
        $this->people = array_values(array_diff($this->people, [$person]));
        $this->firstPage();
    }

    public function clearSupport(): void
    {
        $this->support = false;
        $this->firstPage();
    }

    public function clearSession(): void
    {
        $this->session = '';
        $this->firstPage();
    }

    public function openDetail(string $ulid): void
    {
        $this->open = self::ulidOr($ulid);
    }

    public function closeDetail(): void
    {
        $this->open = '';
    }

    /** "Mọi thao tác của phiên này": the session of the row whose details are open. */
    public function showSession(): void
    {
        $row = $this->open === '' ? null : AuditLog::query()->where('ulid', $this->open)->first(['support_session_ulid']);
        $session = Cast::string($row?->getAttribute('support_session_ulid'));
        if ($session !== '') {
            $this->session = $session;
            $this->open = '';
            $this->firstPage();
        }
    }

    public function render(): View
    {
        $this->clean();
        $catalog = app(AuditCatalog::class);
        $failed = null;

        try {
            $data = app(BoundedRead::class)->run(fn (): array => $this->read($catalog));
        } catch (QueryException $e) {
            if (! BoundedRead::timedOut($e)) {
                report($e);
            }
            $failed = BoundedRead::timedOut($e) ? 'timeout' : 'error';
            $data = ['rows' => new Collection, 'older' => null, 'newer' => null, 'names' => [], 'siteNames' => [], 'peopleChoices' => [], 'siteChoices' => [], 'openRow' => null, 'reason' => null];
        }

        $view = view('admin::livewire.platform.audit.index', $data + [
            'failed' => $failed,
            'catalog' => $catalog,
            'entries' => new AuditEntryView($data['names'], Cast::string(config('admin.brand.name'))),
            'siteTime' => app(SiteTime::class),
            'filtering' => $this->filtering(),
        ]);
        $view->title(__('admin.platform_audit'));

        return $view;
    }

    /**
     * @return array{rows: Collection<int, AuditLog>, older: string|null, newer: string|null, names: array<int, string>,
     *               siteNames: array<int, string>, peopleChoices: list<array{value: string, label: string}>,
     *               siteChoices: list<array{value: string, label: string}>, openRow: AuditLog|null, reason: string|null}
     */
    private function read(AuditCatalog $catalog): array
    {
        ['rows' => $rows, 'older' => $older, 'newer' => $newer] = $this->page($this->filtered($catalog));

        // the people on the newest rows matching every other filter, plus everyone already chosen
        $sample = $this->filtered($catalog, skipPeople: true)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(self::PEOPLE_SAMPLE)
            ->get(['actor_id', 'impersonator_id']);
        $open = $this->open === '' ? null : AuditLog::query()->where('ulid', $this->open)->first();
        if ($open === null) {
            $this->open = '';
        }

        $ids = [];
        foreach ([$rows, $sample, $open === null ? [] : [$open]] as $set) {
            foreach ($set as $row) {
                foreach (['actor_id', 'impersonator_id'] as $column) {
                    if ($row->getAttribute($column) !== null) {
                        $ids[Cast::int($row->getAttribute($column))] = true;
                    }
                }
            }
        }
        $users = User::query()
            ->where(fn (Builder $w) => $w->whereIn('id', array_keys($ids))->orWhereIn('ulid', $this->people))
            ->get(['id', 'ulid', 'name']);
        $names = [];
        foreach ($users as $user) {
            $names[Cast::int($user->getKey())] = Cast::string($user->getAttribute('name'));
        }
        $sampled = [];
        foreach ($sample as $row) {
            foreach (['actor_id', 'impersonator_id'] as $column) {
                if ($row->getAttribute($column) !== null) {
                    $sampled[Cast::int($row->getAttribute($column))] = true;
                }
            }
        }
        $people = [];
        foreach ($users as $user) {
            $ulid = Cast::string($user->getAttribute('ulid'));
            if (isset($sampled[Cast::int($user->getKey())]) || in_array($ulid, $this->people, true)) {
                $people[] = ['value' => $ulid, 'label' => Cast::string($user->getAttribute('name'))];
            }
        }
        usort($people, fn (array $a, array $b): int => strcmp(VietnameseFold::text($a['label']), VietnameseFold::text($b['label'])));

        $siteIds = [];
        foreach ([...$rows, ...($open === null ? [] : [$open])] as $row) {
            if ($row->site_id !== null) {
                $siteIds[$row->site_id] = true;
            }
        }
        $siteNames = [];
        foreach (Site::query()->whereIn('id', array_keys($siteIds))->get(['id', 'name']) as $site) {
            $siteNames[Cast::int($site->getKey())] = Cast::string($site->getAttribute('name'));
        }

        $reason = null;
        $session = Cast::string($open?->getAttribute('support_session_ulid'));
        if ($open !== null && $session !== '') {
            $issued = $open->action === AuditService::SUPPORT_SESSION_ISSUED ? $open : AuditLog::query()
                ->where('support_session_ulid', $session)->where('action', AuditService::SUPPORT_SESSION_ISSUED)
                ->first(['meta']);
            $reason = Cast::string($issued?->meta['reason'] ?? null) ?: null;
        }

        return [
            'rows' => $rows, 'older' => $older, 'newer' => $newer, 'names' => $names, 'siteNames' => $siteNames,
            'peopleChoices' => $people, 'siteChoices' => $this->siteChoices(), 'openRow' => $open, 'reason' => $reason,
        ];
    }

    /**
     * One page and where the next ones start: the ulid of the oldest row shown when older rows
     * exist, of the newest when newer ones do.
     *
     * @param  Builder<AuditLog>  $query
     * @return array{rows: Collection<int, AuditLog>, older: string|null, newer: string|null}
     */
    private function page(Builder $query): array
    {
        $anchorUlid = $this->before !== '' ? $this->before : $this->after;
        $newer = $this->before !== '';
        $anchor = $anchorUlid === '' ? null : AuditLog::query()->where('ulid', $anchorUlid)->first(['id', 'created_at']);
        if ($anchor === null) {
            $this->after = $this->before = '';
            $newer = false;
        }

        $direction = $newer ? 'asc' : 'desc';
        $rows = (clone $query)
            ->when($anchor !== null, fn (Builder $w) => $w->whereRaw('(created_at, id) '.($newer ? '>' : '<').' (?, ?)', [$anchor?->getRawOriginal('created_at'), $anchor?->getKey()]))
            ->orderBy('created_at', $direction)->orderBy('id', $direction)->limit($this->per + 1)
            ->get(['id', 'ulid', 'created_at', 'action', 'actor_id', 'actor_label', 'impersonator_id', 'impersonator_label',
                'site_id', 'ip_address', 'user_agent', 'meta', 'support_session_ulid']);
        if ($anchor !== null && $rows->isEmpty()) {
            // a page link gone stale (nothing older or newer any more): show the first page
            $this->after = $this->before = '';

            return $this->page($query);
        }
        $more = $rows->count() > $this->per;
        $rows = $rows->take($this->per);
        if ($newer) {
            $rows = $rows->reverse();
        }
        $rows = $rows->values();

        $hasOlder = $newer ? $rows->isNotEmpty() : $more;
        $hasNewer = $newer ? $more : $anchor !== null;

        return [
            'rows' => $rows,
            'older' => $hasOlder && $rows->isNotEmpty() ? $rows->last()->ulid : null,
            'newer' => $hasNewer && $rows->isNotEmpty() ? $rows->first()->ulid : null,
        ];
    }

    /**
     * Every chosen filter as one query.
     *
     * @return Builder<AuditLog>
     */
    private function filtered(AuditCatalog $catalog, bool $skipPeople = false): Builder
    {
        $query = AuditLog::query();

        if ($this->groups !== []) {
            $query->whereIn('action', $catalog->codesIn($this->groups));
        }

        if ($this->sites !== []) {
            $ids = Site::query()->whereIn('ulid', $this->sites)->pluck('id')->all();
            $platform = in_array(self::NO_SITE, $this->sites, true);
            $query->where(fn (Builder $w) => $w->whereIn('site_id', $ids)->when($platform, fn (Builder $o) => $o->orWhereNull('site_id')));
        }

        if (! $skipPeople && $this->people !== []) {
            $ids = User::query()->whereIn('ulid', $this->people)->pluck('id')->all();
            $query->where(fn (Builder $w) => $w->whereIn('actor_id', $ids)->orWhereIn('impersonator_id', $ids));
        }

        [$start, $end] = $this->period();
        $query->when($start !== null, fn (Builder $w) => $w->where('created_at', '>=', $start))
            ->when($end !== null, fn (Builder $w) => $w->where('created_at', '<', $end));

        if ($this->support) {
            // written exactly as the partial index for "Chỉ thao tác hỗ trợ" will be, so it can serve it
            $query->whereRaw("(impersonator_id IS NOT NULL OR action LIKE 'support.%')");
        }

        if ($this->session !== '') {
            $query->where('support_session_ulid', $this->session);
        }

        $needle = VietnameseFold::text(trim($this->q));
        if ($needle !== '') {
            $like = VietnameseFold::like($needle);
            $codes = $catalog->codesMatching($needle);
            // plan features are stored by key and shown by name: search the name
            $features = array_keys(array_filter(FeatureRegistry::all(), fn (array $f): bool => str_contains(VietnameseFold::text($f['name']), $needle)));
            // every matching person and website, as subqueries: a common name ("nguyễn") may match many;
            // trigram indexes on these exact expressions serve them (2039_01_01_000100), so keep them as written
            $users = fn () => User::query()->select('id')
                ->where(fn (Builder $w) => $w->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])->orWhereRaw('lower(email) LIKE ?', [$like]));
            $sites = Site::query()->select('id')->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like]);

            $query->where(function (Builder $w) use ($like, $codes, $features, $users, $sites): void {
                $w->whereRaw(VietnameseFold::sql("coalesce(actor_label, '')").' LIKE ?', [$like])
                    ->orWhereRaw(VietnameseFold::sql("coalesce(impersonator_label, '')").' LIKE ?', [$like])
                    ->orWhereRaw("ip_address LIKE ? ESCAPE '\\'", [addcslashes(trim($this->q), '\\%_').'%'])
                    // the values of the details the screen shows, not their keys ("reason" itself finds
                    // nothing) and not the hidden ones (ids, codes); `->>` on a row stored as `[]` is NULL
                    ->orWhereRaw(VietnameseFold::sql(self::SEARCHED_DETAILS).' LIKE ?', [$like])
                    ->orWhereIn('actor_id', $users())->orWhereIn('impersonator_id', $users())
                    ->orWhereIn('site_id', $sites);
                if ($codes !== []) {
                    $w->orWhereIn('action', $codes);
                }
                if ($features !== []) {
                    $w->orWhereIn(DB::raw("meta->>'feature'"), $features);
                }
            });
        }

        return $query;
    }

    /**
     * The chosen time span as UTC bounds [start, end), days counted in the platform's zone.
     *
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function period(): array
    {
        $zone = app(SiteTime::class)->zone();
        $today = CarbonImmutable::now($zone)->startOfDay();

        if ($this->when !== '' && $this->when !== self::RANGE) {
            $start = $this->when === 'today' ? $today : $today->subDays(Cast::int($this->when) - 1);

            return [$start->utc(), null];
        }

        $day = fn (string $date): ?CarbonImmutable => $date === '' ? null : CarbonImmutable::parse($date, $zone)->startOfDay();
        $start = $day($this->from);
        $end = $day($this->to)?->addDay();

        return [$start?->utc(), $end?->utc()];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function siteChoices(): array
    {
        $needle = VietnameseFold::text(trim($this->siteQuery));
        $sites = Site::query()
            ->when($needle !== '', fn (Builder $w) => $w->whereRaw(VietnameseFold::sql('name').' LIKE ?', [VietnameseFold::like($needle)]))
            ->orderBy('name')->orderBy('id')->limit(self::SITE_CHOICES)
            ->get(['ulid', 'name']);
        $chosen = array_values(array_diff($this->sites, [self::NO_SITE]));
        $missing = array_diff($chosen, Cast::strings($sites->pluck('ulid')->all()));
        if ($missing !== []) {
            $sites = $sites->concat(Site::query()->whereIn('ulid', $missing)->get(['ulid', 'name']));
        }

        $choices = [['value' => self::NO_SITE, 'label' => __('admin.audit.no_site')]];
        foreach ($sites as $site) {
            $choices[] = ['value' => Cast::string($site->getAttribute('ulid')), 'label' => Cast::string($site->getAttribute('name'))];
        }

        return $choices;
    }

    private function filtering(): bool
    {
        return trim($this->q) !== '' || $this->groups !== [] || $this->sites !== [] || $this->people !== []
            || $this->when !== '' || $this->from !== '' || $this->to !== '' || $this->support || $this->session !== '';
    }

    /** Public properties come from the browser: keep only values the screen knows. */
    private function clean(): void
    {
        $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        $this->groups = array_values(array_intersect(array_keys(AuditCatalog::GROUPS), $this->strings($this->groups)));
        $this->sites = array_slice(array_values(array_filter($this->strings($this->sites), fn (string $v): bool => $v === self::NO_SITE || Str::isUlid($v))), 0, self::CHOSEN_MAX);
        $this->people = array_slice(array_values(array_filter($this->strings($this->people), fn (string $v): bool => Str::isUlid($v))), 0, self::CHOSEN_MAX);
        $this->from = self::dateOr($this->from);
        $this->to = self::dateOr($this->to);
        if ($this->from !== '' && $this->to !== '' && $this->from > $this->to) {
            // dates picked the wrong way round mean the same span
            [$this->from, $this->to] = [$this->to, $this->from];
        }
        $ranged = $this->from !== '' || $this->to !== '';
        $this->when = $ranged ? self::RANGE : (in_array($this->when, self::WHEN, true) ? $this->when : '');
        $this->session = self::ulidOr($this->session);
        $this->after = self::ulidOr($this->after);
        $this->before = self::ulidOr($this->before);
        $this->per = in_array($this->per, self::PER_PAGE, true) ? $this->per : 20;
        $this->siteQuery = mb_substr($this->siteQuery, 0, self::SEARCH_MAX);
        $this->open = self::ulidOr($this->open);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_unique(array_filter($values, 'is_string')));
    }

    private function firstPage(): void
    {
        $this->after = $this->before = '';
    }

    private static function ulidOr(string $value): string
    {
        return Str::isUlid($value) ? $value : '';
    }

    /** "2026-10-08" as the date field sends it, a real day; anything else is dropped. */
    private static function dateOr(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }
}

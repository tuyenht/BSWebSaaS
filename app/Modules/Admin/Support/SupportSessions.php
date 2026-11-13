<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Models\Site;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The console's list of support sessions (design.md v2.34), read out of the audit trail: one
 * session is the `support.session.entered` row that carries its code, ended by the first
 * `support.session.ended` row with the same code, or by its time running out.
 *
 * Every read stays on the two partial indexes of audit_logs, so the predicates below are written
 * word for word as those indexes are (AuditLogIndexesTest pins the index side):
 *  - the sessions themselves: `(created_at, id) WHERE impersonator_id IS NOT NULL OR action LIKE 'support.%'`;
 *  - their end rows, by code: `(support_session_ulid, created_at, id) WHERE support_session_ulid IS NOT NULL`.
 *
 * Pages move by the last session seen (keyset on created_at, id), the cursor being the session's
 * code, never a row id. Sites and people come in one `whereIn` each, so the number of queries
 * does not grow with the rows. The member's name and roles are those written into the entered
 * row on the site (no role table of any site is read here); a row written before that shows the
 * member's current name and no role. A deleted website still names its sessions. Callers wrap a
 * read in BoundedRead, as the audit viewer does.
 */
final readonly class SupportSessions
{
    public const string TAB_ALL = 'all';

    public const string TAB_OPEN = 'open';

    public const string TAB_ENDED = 'ended';

    /** @var list<string> */
    public const array TABS = [self::TAB_ALL, self::TAB_OPEN, self::TAB_ENDED];

    /** @var list<int> the "Thời gian" choices, in days */
    public const array PERIODS = [7, 30, 90];

    public const string ENTERED = 'support.session.entered';

    public const string ENDED = 'support.session.ended';

    /** Written exactly as the partial index audit_logs_support_created_at_index. */
    private const string SUPPORT_ROWS = "(impersonator_id IS NOT NULL OR action LIKE 'support.%')";

    /** Written exactly as the partial index audit_logs_support_session_created_at_index. */
    private const string HAS_CODE = 'support_session_ulid IS NOT NULL';

    public function __construct(private PlatformAccess $platform) {}

    /**
     * One page of sessions, newest first. $after (older) / $before (newer) is the code of the
     * session the previous page ended / started on; an unknown code gives the first page.
     *
     * @param  list<string>  $staff  ulids of the staff members to keep (none = everyone)
     * @return array{rows: list<array{session: string, site_ulid: string|null, site_name: string|null,
     *               member: string, member_roles: list<string>, staff: string, reason: string, minutes: int,
     *               started_at: CarbonImmutable, ends_at: CarbonImmutable, status: string, why: string|null,
     *               ended_by: string|null, can_end: bool}>, older: string|null, newer: string|null}
     *
     * @throws AuthorizationException the viewer may not sign in as members
     */
    public function page(User $viewer, string $tab = self::TAB_ALL, array $staff = [], ?int $days = null, string $search = '', string $after = '', string $before = '', int $per = 25): array
    {
        $this->assertViewer($viewer);
        $now = CarbonImmutable::now();
        $per = max(1, min($per, 100));
        $query = $this->filtered($now, $staff, $days, $search);
        if ($tab === self::TAB_OPEN || $tab === self::TAB_ENDED) {
            [$sql, $bindings] = $this->openCondition($now);
            $query->whereRaw(($tab === self::TAB_OPEN ? '' : 'NOT ').$sql, $bindings);
        }

        $newer = $before !== '';
        $anchor = $this->anchor($newer ? $before : $after);
        if ($anchor === null) {
            $newer = false;
        }

        $direction = $newer ? 'asc' : 'desc';
        $rows = $query
            // The plain bound repeats the row comparison so the planner can skip the months on the
            // far side of the anchor (it does not prune on a row value).
            ->when($anchor !== null, fn (Builder $w) => $w
                ->where('created_at', $newer ? '>=' : '<=', $anchor?->getRawOriginal('created_at'))
                ->whereRaw('(created_at, id) '.($newer ? '>' : '<').' (?, ?)', [$anchor?->getRawOriginal('created_at'), $anchor?->getKey()]))
            ->orderBy('created_at', $direction)->orderBy('id', $direction)->limit($per + 1)
            ->get(['id', 'created_at', 'actor_id', 'actor_label', 'impersonator_id', 'impersonator_label', 'site_id', 'meta', 'support_session_ulid']);

        if ($anchor !== null && $rows->isEmpty()) {
            // a page link gone stale (nothing older or newer any more): the first page
            return $this->page($viewer, $tab, $staff, $days, $search, '', '', $per);
        }

        $more = $rows->count() > $per;
        $rows = $rows->take($per);
        if ($newer) {
            $rows = $rows->reverse();
        }
        $rows = $rows->values();

        $hasOlder = $newer ? $rows->isNotEmpty() : $more;
        $hasNewer = $newer ? $more : $anchor !== null;
        $shown = $this->shown($viewer, $rows, $now);

        return [
            'rows' => $shown,
            'older' => $hasOlder && $shown !== [] ? $shown[array_key_last($shown)]['session'] : null,
            'newer' => $hasNewer && $shown !== [] ? $shown[0]['session'] : null,
        ];
    }

    /**
     * How many sessions each tab holds under the same filters, in one query.
     *
     * @param  list<string>  $staff
     * @return array{all: int, open: int, ended: int}
     *
     * @throws AuthorizationException
     */
    public function counts(User $viewer, array $staff = [], ?int $days = null, string $search = ''): array
    {
        $this->assertViewer($viewer);
        $now = CarbonImmutable::now();
        [$sql, $bindings] = $this->openCondition($now);

        $row = $this->filtered($now, $staff, $days, $search)->toBase()
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE '.$sql.') AS open', $bindings)
            ->first();
        $total = Cast::int($row->total ?? 0);
        $open = Cast::int($row->open ?? 0);

        return ['all' => $total, 'open' => $open, 'ended' => $total - $open];
    }

    /**
     * Whether the session with this code has an end row (written by the console, by a newer
     * session of the same staff member, or by the session itself). One indexed lookup; $since
     * (the earliest the session can have started) keeps it on the recent months.
     */
    public function hasEnded(string $sessionUlid, CarbonImmutable $since): bool
    {
        return AuditLog::query()
            ->whereRaw(self::HAS_CODE)
            ->where('support_session_ulid', $sessionUlid)
            ->where('action', self::ENDED)
            ->where('created_at', '>=', $since)
            ->exists();
    }

    /**
     * The entered row of a session, by its code, or null.
     */
    public function entered(string $sessionUlid): ?AuditLog
    {
        if ($sessionUlid === '') {
            return null;
        }

        return AuditLog::query()
            ->whereRaw(self::HAS_CODE)
            ->where('support_session_ulid', $sessionUlid)
            ->where('action', self::ENTERED)
            ->orderBy('created_at')->orderBy('id')
            ->first(['id', 'created_at', 'actor_id', 'impersonator_id', 'site_id', 'meta', 'support_session_ulid']);
    }

    /**
     * Whether a session entered at $started is still open now: no end row, and its time not up.
     */
    public function isOpen(AuditLog $entered, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $started = self::at($entered);
        $code = Cast::string($entered->getAttribute('support_session_ulid'));

        return $code !== ''
            && $started->addMinutes($this->minutesOf($entered))->greaterThan($now)
            && ! $this->hasEnded($code, $started);
    }

    /**
     * The sessions $staffId has open now, code => site id (one query, on the recent rows only).
     *
     * @return array<string, int|null>
     */
    public function openCodesOf(int $staffId): array
    {
        $now = CarbonImmutable::now();
        [$sql, $bindings] = $this->openCondition($now);

        return AuditLog::query()
            ->where('action', self::ENTERED)
            ->whereRaw(self::SUPPORT_ROWS)
            ->whereRaw(self::HAS_CODE)
            ->where('impersonator_id', $staffId)
            ->whereRaw($sql, $bindings)
            ->get(['support_session_ulid', 'site_id'])
            ->mapWithKeys(fn (AuditLog $row): array => [
                Cast::string($row->getAttribute('support_session_ulid')) => $row->site_id,
            ])->all();
    }

    /** The longest a session may last, in minutes. */
    public static function longest(): int
    {
        return max(SupportSignIn::DURATIONS);
    }

    /** @throws AuthorizationException */
    private function assertViewer(User $viewer): void
    {
        if (! $this->platform->can($viewer, 'platform.sites.impersonate')) {
            throw new AuthorizationException(__('admin.support.not_allowed'));
        }
    }

    /**
     * The entered rows matching the filters (no tab, no paging).
     *
     * @param  list<string>  $staff
     * @return Builder<AuditLog>
     */
    private function filtered(CarbonImmutable $now, array $staff, ?int $days, string $search): Builder
    {
        $query = AuditLog::query()
            ->where('action', self::ENTERED)
            ->whereRaw(self::SUPPORT_ROWS)
            ->whereRaw(self::HAS_CODE);

        if ($staff !== []) {
            $query->whereIn('impersonator_id', User::query()->select('id')->whereIn('ulid', $staff));
        }

        if ($days !== null && in_array($days, self::PERIODS, true)) {
            $query->where('created_at', '>=', $now->subDays($days));
        }

        $needle = VietnameseFold::text(trim($search));
        if ($needle !== '') {
            $like = VietnameseFold::like($needle);
            // the same expressions the trigram indexes of users.name and sites.name are built on
            $members = User::query()->select('id')
                ->where(fn (Builder $w) => $w->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like])->orWhereRaw('lower(email) LIKE ?', [$like]));
            $sites = Site::withTrashed()->select('id')->whereRaw(VietnameseFold::sql('name').' LIKE ?', [$like]);

            $query->where(fn (Builder $w) => $w
                ->whereRaw(VietnameseFold::sql("coalesce(impersonator_label, '')").' LIKE ?', [$like])
                ->orWhereRaw(VietnameseFold::sql("concat_ws(' ', meta->>'reason', meta->>'member_name', actor_label)").' LIKE ?', [$like])
                ->orWhereIn('actor_id', $members)
                ->orWhereIn('site_id', $sites));
        }

        return $query;
    }

    /**
     * "Open" for an entered row of the outer query: entered within the longest duration, its own
     * duration not over, and no end row with its code.
     *
     * @return array{0: literal-string, 1: list<mixed>}
     */
    private function openCondition(CarbonImmutable $now): array
    {
        $sql = '(audit_logs.created_at >= ?'
            ." AND audit_logs.created_at + make_interval(mins => coalesce((audit_logs.meta->>'minutes')::int, ?)) > ?"
            .' AND NOT EXISTS (SELECT 1 FROM audit_logs AS ended WHERE ended.support_session_ulid IS NOT NULL'
            .' AND ended.support_session_ulid = audit_logs.support_session_ulid'
            .' AND ended.action = ? AND ended.created_at >= audit_logs.created_at))';

        return [$sql, [$now->subMinutes(self::longest()), self::longest(), $now, self::ENDED]];
    }

    private function anchor(string $sessionUlid): ?AuditLog
    {
        return $sessionUlid === '' ? null : $this->entered($sessionUlid);
    }

    private static function at(AuditLog $row): CarbonImmutable
    {
        $at = $row->getAttribute('created_at');

        return $at instanceof CarbonInterface ? $at->toImmutable() : CarbonImmutable::parse(Cast::string($at));
    }

    private function minutesOf(AuditLog $row): int
    {
        $minutes = $row->meta['minutes'] ?? null;

        return is_int($minutes) && $minutes > 0 ? $minutes : self::longest();
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return list<array{session: string, site_ulid: string|null, site_name: string|null,
     *              member: string, member_roles: list<string>, staff: string, reason: string, minutes: int,
     *              started_at: CarbonImmutable, ends_at: CarbonImmutable, status: string, why: string|null,
     *              ended_by: string|null, can_end: bool}>
     */
    private function shown(User $viewer, Collection $rows, CarbonImmutable $now): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $codes = $rows->map(fn (AuditLog $r): string => Cast::string($r->getAttribute('support_session_ulid')))->all();
        $earliest = $rows->min(fn (AuditLog $r): string => Cast::string($r->getRawOriginal('created_at')));
        $ends = AuditLog::query()
            ->whereRaw(self::HAS_CODE)
            ->whereIn('support_session_ulid', $codes)
            ->where('action', self::ENDED)
            ->where('created_at', '>=', $earliest)
            ->orderBy('created_at')->orderBy('id')
            ->get(['created_at', 'actor_id', 'actor_label', 'meta', 'support_session_ulid'])
            // the first end row is the end; any later one (two requests racing) changes nothing
            ->groupBy(fn (AuditLog $r): string => Cast::string($r->getAttribute('support_session_ulid')))
            ->map(fn (Collection $group): ?AuditLog => $group->first());

        $siteIds = $rows->pluck('site_id')->filter()->unique()->values()->all();
        $sites = $siteIds === [] ? collect() : Site::withTrashed()->whereIn('id', $siteIds)->get(['id', 'ulid', 'name'])->keyBy('id');
        $userIds = $rows->pluck('actor_id')->merge($ends->pluck('actor_id'))->filter()->unique()->values()->all();
        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        $viewerId = Cast::int($viewer->getKey());
        $super = $this->platform->isSuperAdmin($viewer);

        $shown = [];
        foreach ($rows as $row) {
            $code = Cast::string($row->getAttribute('support_session_ulid'));
            $meta = $row->meta;
            $minutes = $this->minutesOf($row);
            $started = self::at($row);
            $deadline = $started->addMinutes($minutes);
            $end = $ends->get($code);
            $site = $sites->get(Cast::int($row->site_id));
            $member = $users->get(Cast::int($row->getAttribute('actor_id')));

            if ($end instanceof AuditLog) {
                $endedAt = self::at($end);
                $endMeta = $end->meta;
                $status = 'ended';
                $why = is_string($endMeta['why'] ?? null) ? $endMeta['why'] : null;
                $endsAt = $endedAt->lessThan($deadline) ? $endedAt : $deadline;
                $by = $users->get(Cast::int($end->getAttribute('actor_id')));
                $endedBy = $why === 'ended_by'
                    ? ($by instanceof User ? SupportSession::labelOf($by) : Cast::string($end->getAttribute('actor_label')))
                    : null;
            } else {
                $status = $deadline->greaterThan($now) ? 'open' : 'expired';
                $why = $status === 'expired' ? 'expired' : null;
                $endsAt = $deadline;
                $endedBy = null;
            }

            $name = is_string($meta['member_name'] ?? null) && trim($meta['member_name']) !== '' ? $meta['member_name'] : null;
            $shown[] = [
                'session' => $code,
                'site_ulid' => $site instanceof Site ? Cast::string($site->getAttribute('ulid')) : null,
                'site_name' => $site instanceof Site ? Cast::string($site->getAttribute('name')) : null,
                'member' => $name ?? ($member instanceof User ? SupportSession::labelOf($member) : Cast::string($row->getAttribute('actor_label'))),
                'member_roles' => $this->roles($meta['member_roles'] ?? null),
                'staff' => Cast::string($row->getAttribute('impersonator_label')),
                'reason' => Cast::string($meta['reason'] ?? null),
                'minutes' => $minutes,
                'started_at' => $started,
                'ends_at' => $endsAt,
                'status' => $status,
                'why' => $why,
                'ended_by' => $endedBy,
                'can_end' => $status === 'open' && ($super || Cast::int($row->getAttribute('impersonator_id')) === $viewerId),
            ];
        }

        return $shown;
    }

    /**
     * The roles written into the entered row, as people read them, highest first.
     *
     * @return list<string>
     */
    private function roles(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $labels = [];
        foreach ($stored as $role) {
            if (is_array($role) && is_string($role['name'] ?? null) && $role['name'] !== '') {
                $labels[] = RoleLabel::forName($role['name'], ($role['system'] ?? false) === true);
            }
        }

        return $labels;
    }
}

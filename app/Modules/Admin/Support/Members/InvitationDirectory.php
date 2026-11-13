<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Members;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The current site's invitations, read page by page for the "Lời mời" tab of the members screen.
 * `invitations` is a tenant table (RLS on site_id); every read here also names the site, so the
 * planner keeps to the site's own rows.
 *
 * An invitation is in exactly one state: accepted, revoked, expired (neither, and past its expiry)
 * or pending. Newest mail first: sorted by sent_at, then id, and paged by a keyset cursor on that
 * pair addressed by the boundary invitation's ulid, so a page never counts the rows it skips. The
 * search matches the email as typed, case-insensitively (`%` and `_` only match themselves).
 */
final class InvitationDirectory
{
    /** @var list<string> the states, in the order the screen offers them */
    public const array STATES = ['pending', 'expired', 'revoked', 'accepted'];

    public function __construct(private readonly CurrentSite $site) {}

    /**
     * One page of invitations in $state, with the cursors of the pages either side (null: no page that way).
     *
     * @param  list<string>  $roles  role names to keep (any of them); [] keeps every invitation
     * @return array{rows: Collection<int, Invitation>, older: ?string, newer: ?string}
     */
    public function page(string $state, string $search, array $roles, int $per, string $after, string $before): array
    {
        $query = $this->filtered($state, $search, $roles)
            ->select(['invitations.id', 'invitations.ulid', 'invitations.site_id', 'invitations.email', 'invitations.message', 'invitations.invited_by',
                'invitations.sent_at', 'invitations.expires_at', 'invitations.accepted_at', 'invitations.revoked_at'])
            ->with(['roles:id,name,level,is_system', 'inviter:id,ulid,name']);

        $cursor = $after !== '' ? $this->cursorOf($after) : ($before !== '' ? $this->cursorOf($before) : null);
        $backwards = $after === '' && $before !== '' && $cursor !== null;
        if ($cursor !== null) {
            // newest first: the next page holds older mail (smaller pairs), the previous page newer
            $query->whereRaw('(invitations.sent_at, invitations.id) '.($backwards ? '>' : '<').' (?, ?)', [$cursor['sent_at'], $cursor['id']]);
        }

        $direction = $backwards ? 'asc' : 'desc';
        $rows = $query->orderBy('invitations.sent_at', $direction)->orderBy('invitations.id', $direction)->limit($per + 1)->get();
        $more = $rows->count() > $per;
        $rows = $rows->take($per);
        if ($backwards) {
            $rows = $rows->reverse();
        }
        $rows = $rows->values();

        $first = $rows->first();
        $last = $rows->last();
        $hasNext = $backwards ? true : $more;
        $hasPrev = $backwards ? $more : $cursor !== null;

        return [
            'rows' => $rows,
            'older' => $hasNext && $last instanceof Invitation ? $last->ulid : null,
            'newer' => $hasPrev && $first instanceof Invitation ? $first->ulid : null,
        ];
    }

    /**
     * How many invitations the site has in each state — one statement.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $now = now();
        $row = DB::table('invitations')
            ->where('site_id', $this->site->id())
            ->selectRaw(
                'count(*) filter (where accepted_at is null and revoked_at is null and expires_at > ?) as pending,
                 count(*) filter (where accepted_at is null and revoked_at is null and expires_at <= ?) as expired,
                 count(*) filter (where revoked_at is not null) as revoked,
                 count(*) filter (where accepted_at is not null) as accepted',
                [$now, $now],
            )
            ->first();

        $counts = [];
        foreach (self::STATES as $state) {
            $counts[$state] = Cast::int($row->{$state} ?? 0);
        }

        return $counts;
    }

    /**
     * Per role name, how many invitations in $state carry it (the role filter's counts) — one statement.
     *
     * @return array<string, int>
     */
    public function roleCounts(string $state): array
    {
        $siteId = $this->site->id();
        $query = DB::table('invitation_roles as ir')
            ->join('roles as r', 'r.id', '=', 'ir.role_id')
            ->join('invitations', function (JoinClause $join) use ($siteId): void {
                $join->on('invitations.id', '=', 'ir.invitation_id')->where('invitations.site_id', '=', $siteId);
            })
            ->where('ir.site_id', $siteId);
        $this->inState($query, $state);

        $counts = [];
        foreach ($query->selectRaw('r.name as role_name, count(distinct ir.invitation_id) as n')->groupBy('r.name')->pluck('n', 'role_name') as $name => $n) {
            $counts[(string) $name] = Cast::int($n);
        }

        return $counts;
    }

    /**
     * How many invitations in $state match the search and the roles (the "N trong M" line).
     *
     * @param  list<string>  $roles
     */
    public function matchCount(string $state, string $search, array $roles): int
    {
        return $this->filtered($state, $search, $roles)->count();
    }

    /**
     * Which of $userIds still hold a role on this site: an inviter who left reads "Người đã rời website".
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function stillMembers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(array_map(Cast::int(...), DB::table('model_has_roles')
            ->where('site_id', $this->site->id())
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds)
            ->distinct()
            ->pluck('model_id')
            ->all()));
    }

    /** The state of one invitation, by the same rule as the tabs. */
    public static function stateOf(Invitation $invitation): string
    {
        return match (true) {
            $invitation->accepted_at !== null => 'accepted',
            $invitation->revoked_at !== null => 'revoked',
            $invitation->expires_at->isFuture() => 'pending',
            default => 'expired',
        };
    }

    /**
     * @param  list<string>  $roles
     * @return Builder<Invitation>
     */
    private function filtered(string $state, string $search, array $roles): Builder
    {
        $siteId = $this->site->id();
        $query = Invitation::query()->where('invitations.site_id', $siteId);
        $this->inState($query->getQuery(), $state);

        if ($roles !== []) {
            $query->whereExists(fn (QueryBuilder $r): QueryBuilder => $r->selectRaw('1')
                ->from('invitation_roles as fir')
                ->join('roles as firr', 'firr.id', '=', 'fir.role_id')
                ->whereColumn('fir.invitation_id', 'invitations.id')
                ->where('fir.site_id', $siteId)
                ->whereIn('firr.name', $roles));
        }

        $term = trim(mb_substr(trim($search), 0, MemberDirectory::SEARCH_MAX));
        if ($term !== '' && mb_check_encoding($term, 'UTF-8') && MemberDirectory::searches($term)) {
            $query->whereRaw('lower(invitations.email::text) like ?', ['%'.addcslashes(mb_strtolower($term), '\\%_').'%']);
        }

        return $query;
    }

    private function inState(QueryBuilder $query, string $state): void
    {
        $now = now();
        match ($state) {
            'accepted' => $query->whereNotNull('invitations.accepted_at'),
            'revoked' => $query->whereNotNull('invitations.revoked_at'),
            'expired' => $query->whereNull('invitations.accepted_at')->whereNull('invitations.revoked_at')->where('invitations.expires_at', '<=', $now),
            default => $query->whereNull('invitations.accepted_at')->whereNull('invitations.revoked_at')->where('invitations.expires_at', '>', $now),
        };
    }

    /**
     * The (sent_at, id) of an invitation of this site addressed by ulid: the page boundary. Null
     * when the ulid is not one of the site's invitations (a stale or forged cursor reads the first page).
     *
     * @return array{sent_at: string, id: int}|null
     */
    private function cursorOf(string $ulid): ?array
    {
        $row = DB::table('invitations')->where('site_id', $this->site->id())->where('ulid', $ulid)->first(['id', 'sent_at']);

        return $row === null ? null : ['sent_at' => Cast::string($row->sent_at), 'id' => Cast::int($row->id)];
    }
}

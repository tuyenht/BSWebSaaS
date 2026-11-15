<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * How many people a website holds, against the `users.max` its plan (or its own override) allows.
 *
 * The count, one definition for the runtime checks below, is perSiteSql() (the migration that seeded
 * the limit into the plans, 2076_01_01_000000_seed_user_limit_into_plans.php, holds a frozen copy):
 *  - members: the distinct people holding at least one role in the website's team
 *    (model_has_roles.site_id), by account email;
 *  - plus pending invitations: neither accepted nor withdrawn, not expired, to an address that is
 *    not a member already. A website has at most one open invitation per address (re-inviting
 *    refreshes it), so nobody is counted twice.
 *
 * The checks:
 *  - inviting (assertRoomToInvite): refused when the count WITHOUT this address's own pending
 *    invitation has reached the limit. Re-sending an invitation that is still pending changes no
 *    count and goes through at the limit; refreshing an expired one makes it pending again, so it is
 *    held to the limit like a new one.
 *  - accepting (assertRoomToJoin): refused when the members alone have reached the limit — the
 *    pending invitations, the one being accepted among them, were counted when they were sent. So a
 *    website at its limit with invitations still waiting lets each of them in, and a website whose
 *    plan was lowered below the people it has keeps all of them, but lets nobody new in. Someone who
 *    is a member already adds no one, and is never refused.
 *
 * Both run under the website's invitation lock (InviteMember::lockKey), so two invites, or an
 * invite and an accept, cannot both pass the same last place. The owner a new website is created
 * with, and an owner restored by the platform, are given their role directly and are never held to
 * the limit. Runs inside the tenant context: one statement reads the counts and the limit together.
 */
final readonly class SiteHeadcount
{
    public const string FEATURE = 'users.max';

    public function __construct(
        private Entitlement $entitlement,
        private CurrentSite $site,
    ) {}

    /**
     * The headcount of one website ($siteId) or of every website (null), one row per website that
     * holds anyone: `site_id`, `members`, `pending` — the ONE definition of the count.
     *
     * @return array{0: string, 1: list<int|string>} the SQL and its bindings
     */
    public static function perSiteSql(?int $siteId, DateTimeInterface $now): array
    {
        $site = $siteId === null ? '> 0' : '= ?';
        $siteBinding = $siteId === null ? [] : [$siteId];

        $sql = <<<SQL
            with members as (
                select distinct m.site_id, lower(u.email::text) as email
                from model_has_roles m
                join users u on u.id = m.model_id
                where m.model_type = ? and m.site_id {$site}
            ), pending as (
                select distinct i.site_id, lower(i.email::text) as email
                from invitations i
                where i.site_id {$site} and i.accepted_at is null and i.revoked_at is null and i.expires_at > ?
                  and not exists (select 1 from members mm where mm.site_id = i.site_id and mm.email = lower(i.email::text))
            )
            select h.site_id, sum(h.members)::int as members, sum(h.pending)::int as pending
            from (select site_id, 1 as members, 0 as pending from members
                  union all
                  select site_id, 0 as members, 1 as pending from pending) h
            group by h.site_id
            SQL;

        return [$sql, [User::class, ...$siteBinding, ...$siteBinding, $now->format('Y-m-d H:i:sP')]];
    }

    /**
     * The current website's members, pending invitations and limit — and whether $userId is a member
     * already — in one statement.
     *
     * @return array{members: int, pending: int, limit: int, is_member: bool}
     */
    public function current(?int $userId = null): array
    {
        $siteId = $this->site->id();
        [$counts, $countBindings] = self::perSiteSql($siteId, now());
        [$limit, $limitBindings] = $this->entitlement->featureValueExpression(self::FEATURE);

        $row = (array) DB::selectOne(
            "select coalesce(h.members, 0) as members, coalesce(h.pending, 0) as pending, {$limit} as allowed,
                exists(select 1 from model_has_roles m where m.site_id = ? and m.model_type = ? and m.model_id = ?) as is_member
            from (select 1) one left join ({$counts}) h on true",
            [...$limitBindings, $siteId, User::class, $userId ?? 0, ...$countBindings],
        );

        return [
            'members' => Cast::int($row['members'] ?? 0),
            'pending' => Cast::int($row['pending'] ?? 0),
            'limit' => (int) $this->entitlement->valueFrom($row['allowed'] ?? null, self::FEATURE),
            'is_member' => ($row['is_member'] ?? false) === true,
        ];
    }

    /**
     * Refuse an invitation the website has no place for. $open: the address's open invitation, when
     * it has one (InviteMember holds it locked) — still pending, it is counted already.
     *
     * @throws InvitationRejectedException user_limit
     */
    public function assertRoomToInvite(?Invitation $open): void
    {
        $now = $this->current();
        $others = $now['members'] + $now['pending'] - ($open?->isPending() === true ? 1 : 0);

        if ($others >= $now['limit']) {
            throw new InvitationRejectedException('user_limit');
        }
    }

    /**
     * Refuse to let $user join a website whose members already fill its limit.
     *
     * @throws InvitationRejectedException user_limit_join
     */
    public function assertRoomToJoin(User $user): void
    {
        $now = $this->current(Cast::int($user->getKey()));

        if (! $now['is_member'] && $now['members'] >= $now['limit']) {
            throw new InvitationRejectedException('user_limit_join');
        }
    }
}

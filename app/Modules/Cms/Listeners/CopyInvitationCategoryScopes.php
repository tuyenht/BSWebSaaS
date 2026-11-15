<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Actions\SetMemberCategoryScopes;
use App\Modules\Cms\Models\InvitationCategoryScope;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Events\InvitationAccepted;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The categories an invitation limits its new member to — post categories, product categories, each
 * an axis of its own (CategoryScopes) — become the member's own limits the
 * moment they join: in the accepting transaction (the event is synchronous), after the roles are
 * granted, so the member never holds those roles without the limits. An invitation without limits
 * changes nothing. The same exemption as everywhere: a member who now holds a role that is never
 * limited (level ≥ CategoryScopes::EXEMPT_LEVEL, or a platform super_admin) gets no rows — they
 * would be ignored anyway, and a later demotion must not bring them back unseen.
 *
 * The invitation's rows are locked FOR SHARE, then read again by a fresh statement: a category
 * delete moving them onto a merge target either waits for this join to commit (and then moves the
 * member's new rows too), or has committed its move, which the second read sees. The member's limits
 * lock (CategoryScopes::lockMember) is held from the role grant on, before those rows — a category
 * delete takes the limited members' locks before it moves invitation rows, the same order. A person who
 * is a member already and has limits on an axis keeps them on that axis: the invitation's set was
 * checked against its own roles only, never against the member they are. Rows are only ever added,
 * axis by axis — the copy of one axis never removes the member's limits on the other. An inviter
 * limited on an axis the invitation's roles work on never lets the member join without limits of
 * it (they may have been limited after sending it): the join is refused as no longer valid.
 *
 * An invitation is accepted on its own site's host; any other context is a bug, refused rather than
 * letting the member join without their limits.
 */
final readonly class CopyInvitationCategoryScopes
{
    public function __construct(
        private CategoryScopes $scopes,
        private CurrentSite $site,
        private SetMemberCategoryScopes $limits,
    ) {}

    public function handle(InvitationAccepted $event): void
    {
        // The member's roles just changed: nothing read earlier in this request still holds.
        $this->scopes->forget();

        $invitation = $event->invitation;
        if (! $this->site->hasId() || $this->site->id() !== $invitation->site_id) {
            throw new LogicException('An invitation is accepted only on its own site.');
        }
        $siteId = $invitation->site_id;

        DB::transaction(function () use ($event, $invitation, $siteId): void {
            $member = $event->member;
            $rows = fn () => InvitationCategoryScope::query()
                ->where('invitation_category_scopes.site_id', $siteId)
                ->where('invitation_category_scopes.invitation_id', $invitation->id)
                ->orderBy('invitation_category_scopes.taxonomy_id');
            // FOR SHARE first: a category delete moving these rows right now is waited for. Its new
            // rows (on the merge target) are not in that statement's snapshot, so the rows are read
            // again by a fresh statement, which sees the move committed.
            // The lock names the invitation's rows alone (no join): the categories are never locked.
            $rows()->sharedLock()->pluck('taxonomy_id');
            /** @var array<string, list<int>> $byAxis the fresh read, by the vocabulary of each row's own category */
            $byAxis = [];
            foreach ($rows()->join('taxonomies as t', fn (JoinClause $join) => $join->on('t.id', '=', 'invitation_category_scopes.taxonomy_id')->on('t.site_id', '=', 'invitation_category_scopes.site_id'))
                ->get(['invitation_category_scopes.taxonomy_id', 't.vocabulary']) as $row) {
                $byAxis[Cast::string($row->getAttribute('vocabulary'))][] = Cast::int($row->getAttribute('taxonomy_id'));
            }
            // The member's limits lock: already held since the roles were granted (ForgetMemberCategoryScopes),
            // taken here again so the copy never depends on that.
            $this->scopes->lockMember($member);
            if ($byAxis !== [] && ! $this->scopes->isExempt($member)) {
                $this->copy($siteId, $invitation, $member, $byAxis);
            }
            $this->assertInviterLimitsKept($invitation, $member);
        });
    }

    /**
     * Each axis's rows of the invitation become the member's, unless they have limits of it already.
     *
     * @param  array<string, list<int>>  $byAxis
     */
    private function copy(int $siteId, Invitation $invitation, User $member, array $byAxis): void
    {
        foreach (CategoryScopes::AXES as $axis) {
            $ids = $byAxis[$axis] ?? [];
            if ($ids === []) {
                continue;
            }
            // Someone who is a member already keeps the limits set on them as a member on this
            // axis: an invitation checked against its own roles only never replaces them.
            if ($this->memberHasLimitsOn($siteId, $member, $axis)) {
                continue;
            }

            foreach ($ids as $id) {
                MemberCategoryScope::query()->create(['user_id' => $member->id, 'taxonomy_id' => $id]);
            }
            $this->scopes->forget();

            Audit::record('cms.category_scopes.changed', [
                'actor_id' => $member->id,
                'actor_label' => $member->email,
                'auditable_type' => User::class,
                'auditable_id' => $member->id,
                'meta' => ['before' => [], 'after' => $ids, 'invitation' => $invitation->ulid, 'vocabulary' => $axis],
            ]);
        }
    }

    /**
     * An inviter limited on an axis — since the invitation was sent, too — never lets its member join
     * working in every category of it: the invitation is no longer valid, and the join is undone.
     *
     * @throws InvitationRejectedException no_longer_valid
     */
    private function assertInviterLimitsKept(Invitation $invitation, User $member): void
    {
        $inviter = $invitation->inviter()->first();
        if (! $inviter instanceof User) {
            throw new InvitationRejectedException('no_longer_valid');
        }
        $roles = array_values($invitation->roles()->get()->all());
        if ($this->limits->axesLeftOpen($inviter, $member, $roles) !== []) {
            throw new InvitationRejectedException('no_longer_valid');
        }
    }

    private function memberHasLimitsOn(int $siteId, User $member, string $axis): bool
    {
        return MemberCategoryScope::query()
            ->where('site_id', $siteId)
            ->where('user_id', $member->id)
            ->whereIn('taxonomy_id', Taxonomy::query()->select('id')->where('vocabulary', $axis))
            ->exists();
    }
}

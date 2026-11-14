<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\InvitationCategoryScope;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Events\InvitationAccepted;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The post categories an invitation limits its new member to become the member's own limits the
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
 * is a member already and has limits keeps them: the invitation's set was checked against its own
 * roles only, never against the member they are.
 *
 * An invitation is accepted on its own site's host; any other context is a bug, refused rather than
 * letting the member join without their limits.
 */
final readonly class CopyInvitationCategoryScopes
{
    public function __construct(
        private CategoryScopes $scopes,
        private CurrentSite $site,
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
                ->where('site_id', $siteId)
                ->where('invitation_id', $invitation->id)
                ->orderBy('taxonomy_id');
            // FOR SHARE first: a category delete moving these rows right now is waited for. Its new
            // rows (on the merge target) are not in that statement's snapshot, so the rows are read
            // again by a fresh statement, which sees the move committed.
            $rows()->sharedLock()->pluck('taxonomy_id');
            $ids = array_map(static fn (mixed $id): int => Cast::int($id), $rows()->pluck('taxonomy_id')->all());
            // The member's limits lock: already held since the roles were granted (ForgetMemberCategoryScopes),
            // taken here again so the copy never depends on that.
            $this->scopes->lockMember($member);
            if ($ids === [] || $this->scopes->isExempt($member)) {
                return;
            }

            // Someone who is a member already keeps the limits set on them as a member: an invitation
            // checked against its own roles only never replaces them.
            if (MemberCategoryScope::query()->where('site_id', $siteId)->where('user_id', $member->id)->exists()) {
                return;
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
                'meta' => ['before' => [], 'after' => $ids, 'invitation' => $invitation->ulid],
            ]);
        });
    }
}

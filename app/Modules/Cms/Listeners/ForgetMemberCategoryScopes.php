<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Events\SiteMembershipChanged;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * A member's category limits on a site go when the member no longer needs them:
 *  - they left the site (their last role there was revoked) — invited back later, they start from
 *    every category like any new member;
 *  - they now hold a role that is never limited (level ≥ CategoryScopes::EXEMPT_LEVEL) — the rows
 *    would be ignored anyway, and a later demotion must not bring old limits back unseen.
 * Changing between roles that are both limited keeps the limits. Runs in the granting or revoking
 * transaction (the event is synchronous), so the limits change only if the role does.
 *
 * It holds the member's limits lock (CategoryScopes::lockMember, the one SetMemberCategoryScopes
 * takes), so limits being set at the same moment are either seen here and cleared, or written
 * after this and refused by the setter's own re-check of the new role.
 *
 * Only a change on the CURRENT site is handled: the rows, the member's roles and RLS are all read
 * there. A role granted on another site (a platform super_admin may do that) is left to that site's
 * own requests — nothing is cleared here rather than the wrong thing.
 */
final readonly class ForgetMemberCategoryScopes
{
    public function __construct(
        private CategoryScopes $scopes,
        private CurrentSite $site,
    ) {}

    public function handle(SiteMembershipChanged $event): void
    {
        // The member's roles just changed: nothing read earlier in this request still holds.
        $this->scopes->forget();

        if (! $this->site->hasId() || $this->site->id() !== $event->siteId) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $member = $event->member;
            $this->scopes->lockMember($member);

            $rows = MemberCategoryScope::query()
                ->where('site_id', $event->siteId)
                ->where('user_id', $member->id)
                ->orderBy('taxonomy_id')
                ->pluck('taxonomy_id')
                ->all();

            if ($rows === []) {
                return;
            }

            $left = $member->roles()->doesntExist();
            if (! $left && ! $this->scopes->isExempt($member)) {
                return;
            }

            MemberCategoryScope::query()
                ->where('site_id', $event->siteId)
                ->where('user_id', $member->id)
                ->delete();
            $this->scopes->forget();

            Audit::record('cms.category_scopes.cleared', [
                'auditable_type' => User::class,
                'auditable_id' => $member->id,
                'meta' => ['taxonomy_ids' => $rows, 'reason' => $left ? 'left_site' : 'exempt_role'],
            ]);
        });
    }
}

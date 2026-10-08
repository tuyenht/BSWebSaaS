<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Events\SiteMembershipChanged;
use Illuminate\Support\Facades\DB;

/**
 * A member's category limits on a site go when the member no longer needs them:
 *  - they left the site (their last role there was revoked) — invited back later, they start from
 *    every category like any new member;
 *  - they now hold a role that is never limited (level ≥ CategoryScopes::EXEMPT_LEVEL) — the rows
 *    would be ignored anyway, and a later demotion must not bring old limits back unseen.
 * Changing between roles that are both limited keeps the limits. Runs in the granting or revoking
 * transaction (the event is synchronous), so the limits change only if the role does.
 */
final readonly class ForgetMemberCategoryScopes
{
    public function __construct(private CategoryScopes $scopes) {}

    public function handle(SiteMembershipChanged $event): void
    {
        // The member's roles just changed: nothing read earlier in this request still holds.
        $this->scopes->forget();

        DB::transaction(function () use ($event): void {
            $member = $event->member;
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

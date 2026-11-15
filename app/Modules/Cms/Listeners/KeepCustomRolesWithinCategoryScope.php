<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Rbac\Events\CustomRolePermissionsAdding;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;

/**
 * An actor limited to some categories on an axis never makes a custom role work on that axis when
 * it did not before: the role's holders, given it by someone with no limits, may have no limits
 * there, and would then work in every category — wider than the actor. Removing permissions, and
 * adding ones the role already works with (or that work on no axis the actor is limited on), stay
 * allowed. A new role has no holders: giving it to a member goes through the Members screen, which
 * refuses to leave the member open (SetMemberCategoryScopes::assertNoneLeftOpen).
 */
final readonly class KeepCustomRolesWithinCategoryScope
{
    public function __construct(private CategoryScopes $scopes) {}

    /**
     * @throws RoleChangeRejectedException outside_category_scope
     */
    public function handle(CustomRolePermissionsAdding $event): void
    {
        foreach (CategoryScopes::AXES as $axis) {
            if (InviteMemberWithCategories::worksOn($axis, $event->after)
                && ! InviteMemberWithCategories::worksOn($axis, $event->before)
                && $this->scopes->isLimited($event->actor, $axis)) {
                throw new RoleChangeRejectedException('outside_category_scope');
            }
        }
    }
}

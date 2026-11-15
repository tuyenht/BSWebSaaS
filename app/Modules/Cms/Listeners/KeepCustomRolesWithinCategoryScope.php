<?php

declare(strict_types=1);

namespace App\Modules\Cms\Listeners;

use App\Modules\Cms\Actions\InviteMemberWithCategories;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Rbac\Events\CustomRolePermissionsAdding;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;

/**
 * An actor limited to some categories on an axis never adds to a custom role a permission that
 * works on that axis: the role's holders, given it by someone with no limits, may have no limits
 * there, and would then do more in every category than before — wider than the actor. This holds
 * even when the role already works on that axis (writing posts, then also editing or publishing
 * every one). Removing permissions, and adding ones that work on no axis the actor is limited on,
 * stay allowed. A new role has no holders: giving it to a member goes through the Members screen,
 * which refuses to leave the member open (SetMemberCategoryScopes::assertNoneLeftOpen).
 */
final readonly class KeepCustomRolesWithinCategoryScope
{
    public function __construct(private CategoryScopes $scopes) {}

    /**
     * @throws RoleChangeRejectedException outside_category_scope
     */
    public function handle(CustomRolePermissionsAdding $event): void
    {
        $added = array_values(array_diff($event->after, $event->before));
        foreach (CategoryScopes::AXES as $axis) {
            if (InviteMemberWithCategories::worksOn($axis, $added)
                && $this->scopes->isLimited($event->actor, $axis)) {
                throw new RoleChangeRejectedException('outside_category_scope');
            }
        }
    }
}

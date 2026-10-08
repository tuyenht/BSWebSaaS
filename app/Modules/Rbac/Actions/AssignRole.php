<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Events\SiteMembershipChanged;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use Illuminate\Support\Facades\DB;

/**
 * The single user-facing path to grant a role (design.md §15.3). Enforces the hierarchy
 * guard (grant ≤ self, within own permissions + team) then records an audit trail. System
 * callers (provisioning, bootstrap) assign roles directly and intentionally bypass this.
 *
 * Announces SiteMembershipChanged in the same transaction, so per-member settings of the site
 * follow the new role (e.g. a member promoted to a level never limited loses their limits).
 */
final readonly class AssignRole
{
    public function __construct(private RoleHierarchyGuard $guard) {}

    public function handle(User $actor, User $target, Role $role): void
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf
        $this->guard->assertCanGrant($actor, $role);

        DB::transaction(function () use ($target, $role): void {
            $target->assignRole($role);

            Audit::record('rbac.role.assigned', [
                'auditable_type' => $target::class,
                'auditable_id' => $target->getKey(),
                'meta' => ['role' => $role->name, 'role_id' => $role->getKey()],
            ]);

            // Platform roles sit at site 0: they make nobody a member of a site.
            if ($role->site_id !== null && $role->site_id > 0) {
                event(new SiteMembershipChanged($target, $role->site_id));
            }
        });
    }
}

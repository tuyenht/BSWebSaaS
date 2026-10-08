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
 * The single user-facing path to revoke a role — the mirror of AssignRole. Enforces the same
 * hierarchy guard (you may only revoke a role you could grant: strictly below your own level,
 * within the permissions you hold, same team), so revocation can't strip a peer/superior or be
 * used to escalate. System callers (provisioning, bootstrap) mutate roles directly and bypass this.
 *
 * Announces SiteMembershipChanged in the same transaction, so per-member settings of the site
 * follow (e.g. the member's last role going takes their category limits with it).
 */
final readonly class RevokeRole
{
    public function __construct(private RoleHierarchyGuard $guard) {}

    public function handle(User $actor, User $target, Role $role): void
    {
        app(SupportSession::class)->forbid();   // membership never changes on someone's behalf
        $this->guard->assertCanGrant($actor, $role);

        DB::transaction(function () use ($target, $role): void {
            $target->removeRole($role);

            Audit::record('rbac.role.revoked', [
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

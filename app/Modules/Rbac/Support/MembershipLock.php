<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may change a website's members and roles at all. Two people never may, whatever their gates
 * say:
 *  - platform staff signed in AS a member (a support session) — membership never changes on
 *    someone's behalf;
 *  - platform staff who enter a customer website's admin directly, without holding a role there.
 *    A super_admin passes every tenant gate (Gate::before), so without this one stolen staff
 *    account could make itself or anyone the owner of every website. Getting an owner back into a
 *    locked-out website is the operator's own audited step (RecoverSiteOwner), never this screen.
 *
 * Staff who hold a real role on the website act as that member: their own level and permissions
 * there decide (RoleHierarchyGuard, permits()), never the platform bypass — a super_admin who is
 * only an editor somewhere manages nobody there.
 *
 * Site creation assigns the first owner directly (ProvisionSite) and never comes through here.
 */
final readonly class MembershipLock
{
    public function __construct(
        private SupportSession $support,
        private PlatformAccess $platform,
        private PermissionRegistrar $registrar,
    ) {}

    /**
     * Refuse a change to the members or roles of $siteId unless $actor may make it AND holds
     * $permission there (permits()). The platform plane is never a website: its roles are decided
     * by the hierarchy guard alone.
     *
     * @throws AuthorizationException
     */
    public function authorize(User $actor, int $siteId, string $permission): void
    {
        $this->forbid($actor, $siteId);

        if ($siteId !== PermissionRegistry::PLATFORM_TEAM_ID && ! $this->permits($actor, $siteId, $permission)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Does $actor hold $permission on $siteId for a member or role change? The gate decides — except
     * for a platform super_admin who holds a role on the website: the gate lets a super_admin through
     * everything, so their own roles there are read instead (a fresh query in the team spatie is
     * using, which must be this website: anything else is refused). A super_admin with no role there
     * passes this check and is stopped by forbid().
     */
    public function permits(User $actor, int $siteId, string $permission): bool
    {
        if ($siteId === PermissionRegistry::PLATFORM_TEAM_ID
            || ! $this->platform->isSuperAdmin($actor)
            || ! self::holdsRoleOn($actor, $siteId)) {
            return Gate::forUser($actor)->allows($permission);
        }

        return $this->registrar->getPermissionsTeamId() === $siteId
            && $actor->roles()
                ->whereHas('permissions', fn (Builder $held): Builder => $held->where('name', $permission))
                ->exists();
    }

    /**
     * Refuse a change to the members or roles of $siteId.
     *
     * @throws AuthorizationException
     */
    public function forbid(User $actor, int $siteId): void
    {
        $this->support->forbid();

        if ($this->outsideStaff($actor, $siteId)) {
            throw new AuthorizationException(__('admin.staff.locked'));
        }
    }

    /** The refusal for $actor on $siteId, in the words the screen shows; null when the change may go ahead. */
    public function reason(User $actor, int $siteId): ?string
    {
        if ($this->support->active()) {
            return Cast::string(__('admin.support.locked'));
        }

        return $this->outsideStaff($actor, $siteId) ? Cast::string(__('admin.staff.locked')) : null;
    }

    /** Platform staff on a website where they hold no role of their own. The platform plane itself is never a website. */
    public function outsideStaff(User $actor, int $siteId): bool
    {
        if ($siteId === PermissionRegistry::PLATFORM_TEAM_ID) {
            return false;
        }

        return $this->platform->isOperator($actor) && ! self::holdsRoleOn($actor, $siteId);
    }

    /** Does $user hold any role on $siteId? A fresh query: a roles relation loaded earlier may belong to another team. */
    public static function holdsRoleOn(User $user, int $siteId): bool
    {
        return DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', Cast::int($user->getKey()))
            ->where('site_id', $siteId)
            ->exists();
    }
}

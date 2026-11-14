<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Events\SiteMembershipChanged;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Give a website whose owners are locked out an owner again — the one way platform staff change a
 * website's members (MembershipLock refuses them on the website's own screens). The operator runs
 * it from the console (site:recover-owner): nobody on the website is there to grant the role and
 * the hierarchy guard would refuse staff, so the role is given directly, like ProvisionSite gives
 * the first one. It is run by a named platform operator, who is recorded as the actor; a written
 * reason is required, and the audit row is written in the same transaction, so the change never
 * happens unaudited. The owners already there stay; who hears of it is the caller's job (the
 * owners returned, and the new one), after the transaction has committed.
 *
 * Refusals (RoleChangeRejectedException): not_operator (whoever runs it must be platform staff) ·
 * reason_required · operator (platform staff never become a website's owner this way) ·
 * no_owner_role (the website has no owner role to give) · already_owner. Runs inside the
 * website's tenant context.
 */
final readonly class RecoverSiteOwner
{
    public const int REASON_MAX = 500;

    public const string ACTION = 'rbac.owner.recovered';

    public function __construct(
        private PlatformAccess $platform,
        private CurrentSite $site,
    ) {}

    /**
     * @return list<User> the owners the website had before
     *
     * @throws RoleChangeRejectedException
     */
    public function handle(User $operator, User $newOwner, string $reason): array
    {
        if (! $this->platform->isOperator($operator)) {
            throw new RoleChangeRejectedException('not_operator');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new RoleChangeRejectedException('reason_required');
        }
        if ($this->platform->isOperator($newOwner)) {
            throw new RoleChangeRejectedException('operator');
        }
        $siteId = $this->site->id();
        $role = $this->ownerRole();

        return DB::transaction(function () use ($operator, $newOwner, $reason, $siteId, $role): array {
            $owners = $this->owners();
            if ($owners->contains(fn (User $owner): bool => $owner->is($newOwner))) {
                throw new RoleChangeRejectedException('already_owner');
            }

            $newOwner->assignRole($role);
            $newOwner->unsetRelation('roles');

            Audit::recordOrFail(self::ACTION, [
                'site_id' => $siteId,
                'actor_id' => $operator->getKey(),
                'actor_label' => $operator->email,
                'auditable_type' => $newOwner::class,
                'auditable_id' => $newOwner->getKey(),
                'meta' => ['email' => $newOwner->email, 'reason' => $reason, 'role' => $role->name, 'owners_before' => $owners->count(), 'via' => 'console'],
            ]);

            event(new SiteMembershipChanged($newOwner, $siteId));

            return array_values($owners->all());
        });
    }

    /**
     * The website's owners now (the people a recovery is told to).
     *
     * @return Collection<int, User>
     */
    public function owners(): Collection
    {
        $siteId = $this->site->id();
        $role = $this->ownerRole();

        return User::query()
            ->select(['id', 'ulid', 'name', 'email'])
            ->whereExists(fn (Builder $membership): Builder => $membership->selectRaw('1')
                ->from('model_has_roles')
                ->whereColumn('model_has_roles.model_id', 'users.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.role_id', $role->id)
                ->where('model_has_roles.site_id', $siteId))
            ->orderBy('id')
            ->limit(100)
            ->get();
    }

    /** @throws RoleChangeRejectedException no_owner_role */
    private function ownerRole(): Role
    {
        $role = Role::query()->where('site_id', $this->site->id())->where('name', 'owner')->first();
        if (! $role instanceof Role) {
            throw new RoleChangeRejectedException('no_owner_role');
        }

        return $role;
    }
}

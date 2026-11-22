<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Rbac\Models\Role;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonInterface;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Delete for good the custom roles that have sat in the trash longer than $days (Role::TRASH_DAYS
 * by default). The daily `roles:purge-trash` runs it; it has no user, so no policy applies.
 *
 * `roles` is platform-wide (no RLS): which sites have something to purge is one read of the trash
 * index, then each site runs in its own tenant context (its audit rows need it) and transaction.
 * Each row is locked and checked again (still in the trash, still past the cutoff, custom, this
 * site) before it goes, so a role restored a moment ago is never touched. A role in the trash has no
 * holder and no pending invitation (DeleteCustomRole refuses otherwise), so the FK cascade only
 * removes its permissions and its place on expired or withdrawn invitations; a role copied from it
 * keeps working with forked_from_role_id set to NULL. Every purged role leaves an audit row (actor
 * "system") in the same transaction as its delete, and every worker drops it from its permission
 * catalog at its next request.
 */
final readonly class PurgeTrashedRoles
{
    public function __construct(
        private Tenancy $tenancy,
        private PermissionRegistrar $registrar,
    ) {}

    /** @return int how many roles were deleted for good */
    public function handle(int $days = Role::TRASH_DAYS): int
    {
        $cutoff = now()->subDays(max(0, $days));
        $purged = 0;

        $siteIds = Role::onlyTrashed()->where('is_system', false)->where('deleted_at', '<=', $cutoff)
            ->distinct()->orderBy('site_id')->pluck('site_id');

        foreach ($siteIds as $rawSiteId) {
            $siteId = Cast::int($rawSiteId);
            if ($siteId > 0) {
                $purged += $this->purgeSite($siteId, $cutoff);   // a failing site is logged; the rest still run
            }
        }

        if ($purged > 0) {
            $this->registrar->forgetCachedPermissions();
        }

        return $purged;
    }

    /** One site, in its own transaction; a site that fails is logged and the next one still runs. */
    private function purgeSite(int $siteId, CarbonInterface $cutoff): int
    {
        try {
            return Cast::int($this->tenancy->enter($siteId, function () use ($siteId, $cutoff): int {
                $doomed = Role::onlyTrashed()->where('site_id', $siteId)->where('is_system', false)
                    ->where('deleted_at', '<=', $cutoff)->orderBy('id')->lockForUpdate()->get();

                foreach ($doomed as $role) {
                    Audit::recordOrFail('rbac.role.purged', [
                        'actor_label' => 'system',
                        'auditable_type' => Role::class,
                        'auditable_id' => $role->getKey(),
                        'meta' => [
                            'name' => $role->getAttribute('name'),
                            'level' => $role->level,
                            'permissions' => $role->permissions->pluck('name')->all(),
                            'trashed_at' => $role->deleted_at?->toIso8601String(),
                        ],
                    ]);
                    $role->forceDelete();
                }

                return $doomed->count();
            }));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}

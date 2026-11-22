<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Actions;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Rbac\Models\Role;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Delete for good the custom roles that have sat in the trash longer than $days (Role::TRASH_DAYS
 * by default). The daily `roles:purge-trash` runs it; it has no user, so no policy applies.
 *
 * `roles` is platform-wide (no RLS): which sites have something to purge is one read of the trash
 * index, then each site runs in its own tenant context (its audit rows need it) and transaction.
 * Each row is locked and checked again (still in the trash, still past the cutoff, custom, this
 * site) before it goes, so a role restored a moment ago is never touched. A role in the trash should
 * have no holder and no pending invitation (DeleteCustomRole refuses otherwise), so the FK cascade
 * only removes its permissions and its place on expired or withdrawn invitations; a role copied from
 * it keeps working with forked_from_role_id set to NULL. A holder can still reach a trashed role (an
 * old worker during a deploy, a code-only rollback, hand-written SQL): such a role is kept in the
 * trash, since the cascade would silently take the role from that member, and it is logged and
 * counted so someone looks at it. Every purged role leaves an audit row (actor
 * "system") in the same transaction as its delete, and every worker drops it from its permission
 * catalog at its next request.
 */
final readonly class PurgeTrashedRoles
{
    public function __construct(
        private Tenancy $tenancy,
        private PermissionRegistrar $registrar,
    ) {}

    /** @return array{purged: int, held: int} how many roles were deleted for good, and how many were kept because someone holds them */
    public function handle(int $days = Role::TRASH_DAYS): array
    {
        $cutoff = now()->subDays(max(0, $days));
        $purged = 0;
        $held = 0;

        $siteIds = Role::onlyTrashed()->where('is_system', false)->where('deleted_at', '<=', $cutoff)
            ->distinct()->orderBy('site_id')->pluck('site_id');

        foreach ($siteIds as $rawSiteId) {
            $siteId = Cast::int($rawSiteId);
            if ($siteId > 0) {
                $site = $this->purgeSite($siteId, $cutoff);   // a failing site is logged; the rest still run
                $purged += $site['purged'];
                $held += $site['held'];
            }
        }

        if ($purged > 0) {
            $this->registrar->forgetCachedPermissions();
        }

        return ['purged' => $purged, 'held' => $held];
    }

    /**
     * One site, in its own transaction; a site that fails is logged and the next one still runs.
     *
     * @return array{purged: int, held: int}
     */
    private function purgeSite(int $siteId, CarbonInterface $cutoff): array
    {
        try {
            $counts = ['purged' => 0, 'held' => 0];
            $this->tenancy->enter($siteId, function () use ($siteId, $cutoff, &$counts): void {
                $doomed = Role::onlyTrashed()->where('site_id', $siteId)->where('is_system', false)
                    ->where('deleted_at', '<=', $cutoff)->orderBy('id')->lockForUpdate()->get();

                // checked under the row locks: a new holder row needs a share lock on its role, so none
                // can appear before this transaction ends
                $heldIds = DB::table('model_has_roles')->whereIn('role_id', $doomed->modelKeys())
                    ->distinct()->pluck('role_id')->map(fn (mixed $id): int => Cast::int($id))->all();

                $purged = 0;
                foreach ($doomed as $role) {
                    if (in_array(Cast::int($role->getKey()), $heldIds, true)) {
                        Log::warning('roles:purge-trash kept a trashed role that someone still holds', [
                            'site_id' => $siteId,
                            'role_id' => $role->getKey(),
                            'name' => $role->getAttribute('name'),
                        ]);

                        continue;
                    }
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
                    $purged++;
                }

                $counts = ['purged' => $purged, 'held' => count($heldIds)];
            });

            return $counts;
        } catch (Throwable $e) {
            report($e);

            return ['purged' => 0, 'held' => 0];
        }
    }
}

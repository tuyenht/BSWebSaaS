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
 * `roles` is platform-wide (no RLS): which sites have something to purge is read from the trash
 * index a page of site ids at a time, then each site runs in its own tenant context (its audit rows
 * need it) and transaction. Within a site the roles go in chunks of $chunk rows in id order, each
 * chunk read after the last id of the one before and run inside a savepoint. The savepoint does not
 * isolate a chunk: an error in any chunk rolls the savepoint back and then leaves the site's
 * transaction, so everything purged on that site in this run is rolled back with it (and reported),
 * while the other sites still run. A savepoint releases no lock either, so every role picked stays
 * locked until that site commits. Memory and each statement's work
 * are bounded by the chunk, whatever the trash holds (CustomRoleRules::MAX_KEPT_CUSTOM_ROLES caps it
 * per site anyway). Each row is locked and checked again (still in the trash, still past the cutoff,
 * custom, this site) before it goes, so a role restored a moment ago is never touched. A role in the
 * trash should have no holder and no pending invitation (DeleteCustomRole refuses otherwise), so the
 * FK cascade only removes its permissions and its place on expired or withdrawn invitations; a role
 * copied from it keeps working with forked_from_role_id set to NULL. A holder can still reach a
 * trashed role (an old worker during a deploy, a code-only rollback, hand-written SQL): such a role is
 * kept in the trash, since the cascade would silently take the role from that member, and it is
 * logged and counted so someone looks at it. Every purged role leaves an audit row (actor "system")
 * in the same transaction as its delete, and every worker drops it from its permission catalog at
 * its next request.
 */
final readonly class PurgeTrashedRoles
{
    /** Roles read, locked and deleted per statement; also the page size of the site list. */
    public const int CHUNK = 200;

    public function __construct(
        private Tenancy $tenancy,
        private PermissionRegistrar $registrar,
    ) {}

    /** @return array{purged: int, held: int} how many roles were deleted for good, and how many were kept because someone holds them */
    public function handle(int $days = Role::TRASH_DAYS, int $chunk = self::CHUNK): array
    {
        $cutoff = now()->subDays(max(0, $days));
        $chunk = max(1, $chunk);
        $purged = 0;
        $held = 0;
        $after = 0;

        do {
            $siteIds = Role::onlyTrashed()->where('is_system', false)->where('deleted_at', '<=', $cutoff)
                ->where('site_id', '>', $after)->distinct()->orderBy('site_id')->limit($chunk)
                ->pluck('site_id')->map(fn (mixed $id): int => Cast::int($id))->all();

            foreach ($siteIds as $siteId) {
                $after = $siteId;
                if ($siteId > 0) {
                    $site = $this->purgeSite($siteId, $cutoff, $chunk);   // a failing site is logged; the rest still run
                    $purged += $site['purged'];
                    $held += $site['held'];
                }
            }
        } while (count($siteIds) === $chunk);

        if ($purged > 0) {
            $this->registrar->forgetCachedPermissions();
        }

        return ['purged' => $purged, 'held' => $held];
    }

    /**
     * One site, in its own transaction, all or nothing: a chunk that throws rolls back every chunk of
     * the site, the error is reported and the next site still runs.
     *
     * @return array{purged: int, held: int}
     */
    private function purgeSite(int $siteId, CarbonInterface $cutoff, int $chunk): array
    {
        try {
            return $this->tenancy->enter($siteId, function () use ($siteId, $cutoff, $chunk): array {
                $counts = ['purged' => 0, 'held' => 0];
                $after = 0;
                do {
                    // a savepoint per chunk: an error here is not caught inside the site, so it undoes the whole site
                    $read = DB::transaction(function () use ($siteId, $cutoff, $chunk, $after, &$counts): array {
                        $doomed = Role::onlyTrashed()->where('site_id', $siteId)->where('is_system', false)
                            ->where('deleted_at', '<=', $cutoff)->where('id', '>', $after)
                            ->orderBy('id')->limit($chunk)->lockForUpdate()->get();
                        if ($doomed->isEmpty()) {
                            return [0, $after];
                        }
                        $doomed->load('permissions:id,name');

                        // checked under the row locks: a new holder row needs a share lock on its role, so none
                        // can appear before this transaction ends
                        $heldIds = DB::table('model_has_roles')->whereIn('role_id', $doomed->modelKeys())
                            ->distinct()->pluck('role_id')->map(fn (mixed $id): int => Cast::int($id))->all();

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
                            $counts['purged']++;
                        }
                        $counts['held'] += count($heldIds);

                        return [$doomed->count(), Cast::int($doomed->last()?->getKey())];
                    });
                    [$done, $after] = $read;
                } while ($done === $chunk);

                return $counts;
            });
        } catch (Throwable $e) {
            report($e);

            return ['purged' => 0, 'held' => 0];
        }
    }
}

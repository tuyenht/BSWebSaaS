<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Listeners;

use App\Modules\Rbac\Support\VersionedPermissionRegistrar;
use Spatie\Permission\PermissionRegistrar;

/**
 * At the start of every Octane request, task and tick, and every queued job: let this worker see a
 * role or permission change another worker made (VersionedPermissionRegistrar). One cache read; the
 * permission catalog is reloaded only when it changed.
 */
final readonly class SyncPermissionCache
{
    public function __construct(private PermissionRegistrar $registrar) {}

    public function handle(): void
    {
        if ($this->registrar instanceof VersionedPermissionRegistrar) {
            $this->registrar->syncWithSharedCache();
        }
    }
}

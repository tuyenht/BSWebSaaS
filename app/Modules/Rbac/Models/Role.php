<?php

namespace App\Modules\Rbac\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant/platform role. Team key = site_id (0 = platform sentinel). Adds a
 * hierarchy level (you may only grant roles below your own level) + a system flag.
 *
 * @property int $level
 * @property bool $is_system
 * @property int|null $site_id
 */
class Role extends SpatieRole
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'level' => 'integer',
            'is_system' => 'boolean',
        ]);
    }
}

<?php

namespace App\Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant/platform role. Team key = site_id (0 = platform sentinel). Adds a
 * hierarchy level (you may only grant roles below your own level) + a system flag.
 *
 * @property int $level
 * @property bool $is_system
 * @property int|null $site_id
 * @property int|null $forked_from_role_id
 */
class Role extends SpatieRole
{
    /**
     * The role-template row this role was copied off (design.md §15.2). NULL = still
     * inherits its site_type template LIVE; set = copied-on-write, no longer tracks it.
     *
     * @return BelongsTo<Role, $this>
     */
    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forked_from_role_id');
    }

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

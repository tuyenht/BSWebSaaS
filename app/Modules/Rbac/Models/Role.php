<?php

namespace App\Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant/platform role. Team key = site_id (0 = platform sentinel). Adds a
 * hierarchy level (you may only grant roles below your own level) + a system flag.
 *
 * A custom role that is deleted goes to the trash (deleted_at) for TRASH_DAYS days, then
 * `roles:purge-trash` deletes it for good. The scope hides a trashed role from every reader, the
 * permission catalog and a member's own roles included, so it grants nothing and can be given to
 * nobody. `roles` is platform-wide (no RLS): a raw query on it adds `deleted_at IS NULL` itself.
 *
 * @property int $level
 * @property bool $is_system
 * @property int|null $site_id
 * @property int|null $forked_from_role_id
 * @property Carbon|null $deleted_at
 */
class Role extends SpatieRole
{
    use SoftDeletes;

    /** How long a deleted custom role waits in the trash before it is deleted for good. */
    public const int TRASH_DAYS = 30;

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
    #[\Override]
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'level' => 'integer',
            'is_system' => 'boolean',
        ]);
    }
}

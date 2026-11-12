<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Plans;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;

/**
 * Who may see what on "Gói & tính năng" (design 30), read from platform permissions, never from a
 * role's name: the plans and the upgrade invitation need `platform.billing.manage`, the read-only
 * website types `platform.site_types.manage`. A super admin sees both. The writes themselves are
 * checked again by PlatformPlans.
 */
final readonly class PlanAccess
{
    public function __construct(private PlatformAccess $platform) {}

    public function plans(mixed $user): bool
    {
        return $this->has($user, 'platform.billing.manage');
    }

    public function types(mixed $user): bool
    {
        return $this->has($user, 'platform.site_types.manage');
    }

    /** The member's platform group, for the "your group cannot open this" card. */
    public function groupName(mixed $user): string
    {
        if (! $user instanceof User) {
            return '';
        }
        $held = Cast::strings($this->platform->onPlatform(fn () => $user->roles()->pluck('name')->all()));
        foreach (['super_admin', 'billing', 'support'] as $role) {
            if (in_array($role, $held, true)) {
                return Cast::string(__('rbac.role.'.$role));
            }
        }

        return $held[0] ?? '';
    }

    private function has(mixed $user, string $permission): bool
    {
        return $user instanceof User
            && ($this->platform->isSuperAdmin($user) || $this->platform->can($user, $permission));
    }
}

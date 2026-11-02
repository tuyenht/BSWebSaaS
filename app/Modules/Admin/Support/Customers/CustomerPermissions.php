<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Customers;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;

/**
 * What a platform member may do on the customer screens (design 03), read from their platform
 * permissions — never from a role's name: seeing customers (`platform.tenants.view`), changing a
 * customer's plan (`platform.billing.manage`), opening a website's page (`platform.sites.view`).
 * A super admin may do all three. The notes the screens show for a limited member follow from the
 * same answers; only the wording names the member's group.
 */
final readonly class CustomerPermissions
{
    public function __construct(private PlatformAccess $platform) {}

    public function canView(mixed $user): bool
    {
        return $user instanceof User && ($this->platform->isSuperAdmin($user) || $this->platform->can($user, 'platform.tenants.view'));
    }

    public function canChangePlan(User $user): bool
    {
        return $this->platform->isSuperAdmin($user) || $this->platform->can($user, 'platform.billing.manage');
    }

    public function canOpenSites(User $user): bool
    {
        return $this->platform->isSuperAdmin($user) || $this->platform->can($user, 'platform.sites.view');
    }

    /** The note under the header for a member who cannot do everything here, or null. */
    public function roleStrip(User $user): ?string
    {
        $plan = $this->canChangePlan($user);
        $sites = $this->canOpenSites($user);

        return match (true) {
            ! $plan && $sites => Cast::string(__('admin.customers_role_strip_support')),
            $plan && ! $sites => Cast::string(__('admin.customers_role_strip_billing')),
            default => null,
        };
    }

    /** Why "Đổi gói" is locked for this member, or null when it is not. */
    public function lockedReason(User $user): ?string
    {
        if ($this->canChangePlan($user)) {
            return null;
        }

        return __('admin.customers_plan_locked', ['role' => $this->groupName($user)]);
    }

    /** The name of the member's platform group, the highest when they hold several. */
    private function groupName(User $user): string
    {
        $levels = array_map(fn (array $r): int => $r['level'], PermissionRegistry::platformRoles());
        $held = Cast::strings($this->platform->onPlatform(fn () => $user->roles()->pluck('name')->all()));
        $held = array_values(array_filter($held, fn (string $name): bool => array_key_exists($name, $levels)));
        usort($held, fn (string $a, string $b): int => $levels[$b] <=> $levels[$a]);
        $name = $held[0] ?? 'support';

        return in_array($name, ['super_admin', 'support', 'billing'], true) ? __('admin.customers_role_'.$name) : $name;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\MembershipLock;
use App\Modules\Rbac\Support\RoleHierarchyGuard;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Routes;
use Throwable;

/**
 * "Nhờ cấp quyền" on the admin's no-permission page (design board 25): a member of this website
 * asks for a screen their roles cannot open, and the member managers above them are told through
 * the bell (`member.access_requested`) — the roles holding `users.manage` at a higher level than
 * the member's own, else the site owner.
 *
 * The screen is named by its route; the permission is read again from that route's own `can:` gate
 * on the server, never taken from the browser. At most PER_DAY requests per member and website in
 * 24 hours, and one per screen (so the page still reads "Đã gửi yêu cầu" after a reload). The
 * counts live in the cache (Valkey): an eviction can allow one more request, which is harmless.
 * Only the member's name and ulid, the screen and the permission are kept; nothing of their device.
 */
final readonly class AccessRequests
{
    public const int PER_DAY = 3;

    private const int WINDOW = 86400;

    public function __construct(
        private CurrentSite $site,
        private MembershipLock $lock,
        private RoleHierarchyGuard $hierarchy,
    ) {}

    /** The permission a route's own `can:` gate asks for, when it names one plainly. */
    public static function permissionOf(?Route $route): ?string
    {
        foreach ($route?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && preg_match('/^can:([a-z0-9_.]+)$/', $middleware, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * The permission $user would ask for on the screen $screen (a route name), or null when there
     * is nothing to ask: not a page of this website's admin, a page with no plain permission or
     * one only the platform grants (no role of the website can hold it), one they can already open,
     * a support sign-in, or platform staff without a role here.
     */
    public function askable(User $user, string $screen): ?string
    {
        if (AdminPlane::isPlatform() || ! $this->site->hasId() || ! str_starts_with($screen, 'admin.')) {
            return null;
        }
        $route = Routes::getRoutes()->getByName($screen);
        $permission = $route !== null && in_array('GET', $route->methods(), true) ? self::permissionOf($route) : null;
        if ($permission === null
            || ! in_array($permission, PermissionRegistry::TENANT_PERMISSIONS, true)
            || $user->can($permission)
            || $this->lock->reason($user, $this->site->id()) !== null) {
            return null;
        }

        return $permission;
    }

    /** 'sent' (this screen asked within 24 hours), 'limited' (PER_DAY asked) or 'ready'. */
    public function state(User $user, string $screen): string
    {
        return match (true) {
            Cache::has($this->screenKey($user, $screen)) => 'sent',
            RateLimiter::tooManyAttempts($this->dayKey($user), self::PER_DAY) => 'limited',
            default => 'ready',
        };
    }

    /**
     * Who will be told, as people read their roles: the roles above $user that hold
     * `users.manage` and have a member other than $user, else the site owner.
     *
     * @return list<string>
     */
    public function recipients(User $user): array
    {
        $roles = $this->managerRoles($user);
        if ($roles === []) {
            $roles = $this->ownerRoles($user);
        }

        return array_map(fn (Role $role): string => RoleLabel::for($role), $roles);
    }

    /**
     * Ask once for $screen, titled $title on the page; returns the page's state afterwards. A
     * second press (or one from another tab) sends nothing more.
     */
    public function ask(User $user, string $screen, string $permission, string $title): string
    {
        // the screen's key first, added atomically: two presses at once send one request
        if (RateLimiter::tooManyAttempts($this->dayKey($user), self::PER_DAY)) {
            return $this->state($user, $screen);
        }
        if (! Cache::add($this->screenKey($user, $screen), 1, self::WINDOW)) {
            return 'sent';
        }
        if (RateLimiter::hit($this->dayKey($user), self::WINDOW) > self::PER_DAY) {
            $this->unask($user, $screen); // lost the race for the last request: its count goes back too

            return 'limited';
        }

        $facts = ['actor' => $user->name, 'requester' => $user->ulid, 'screen' => $title, 'permission' => $permission];
        try {
            $told = $this->tell($user, $this->managerRoles($user), $facts);
            if ($told === 0) {
                $told = $this->tell($user, $this->ownerRoles($user), $facts);
            }
        } catch (Throwable $e) {
            $this->unask($user, $screen);

            throw $e;
        }
        if ($told === 0) { // nobody left to tell since the page was drawn: nothing was sent
            $this->unask($user, $screen);

            return 'ready';
        }

        return 'sent';
    }

    /** Gives back the screen and the day's count when nothing went out (the insert failed, or nobody to tell). */
    private function unask(User $user, string $screen): void
    {
        Cache::forget($this->screenKey($user, $screen));
        RateLimiter::decrement($this->dayKey($user), self::WINDOW);
    }

    /**
     * @param  list<Role>  $roles
     * @param  array<string, string>  $facts
     */
    private function tell(User $user, array $roles, array $facts): int
    {
        return Notify::membersOf(array_map(fn (Role $role): int => Cast::int($role->getKey()), $roles), 'member.access_requested', $facts, $user);
    }

    /** @return list<Role> */
    private function managerRoles(User $user): array
    {
        $level = $this->hierarchy->maxLevel($user);

        return $this->rolesWithOthers($user, fn (Builder $role) => $role->where('level', '>', $level)
            ->whereHas('permissions', fn (Builder $permission) => $permission->where('name', 'users.manage')));
    }

    /** @return list<Role> */
    private function ownerRoles(User $user): array
    {
        return $this->rolesWithOthers($user, fn (Builder $role) => $role->where('name', 'owner'));
    }

    /**
     * This website's roles matching $match that someone other than $user holds, highest first.
     *
     * @param  callable(Builder<Role>): mixed  $match
     * @return list<Role>
     */
    private function rolesWithOthers(User $user, callable $match): array
    {
        $siteId = $this->site->id();
        $query = Role::query()->where('site_id', $siteId)
            ->whereExists(fn (QueryBuilder $held) => $held->from('model_has_roles')
                ->whereColumn('model_has_roles.role_id', 'roles.id')
                ->where('model_has_roles.site_id', $siteId)
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.model_id', '<>', $user->id))
            ->select(['id', 'name', 'is_system', 'level'])->orderByDesc('level')->limit(20);
        $match($query);

        return array_values($query->get()->all());
    }

    private function dayKey(User $user): string
    {
        return 'access-request:'.$this->site->id().':'.$user->id;
    }

    private function screenKey(User $user, string $screen): string
    {
        return $this->dayKey($user).':'.$screen;
    }
}

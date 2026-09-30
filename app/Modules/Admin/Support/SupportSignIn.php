<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The link that takes platform staff into a website's admin as one of its members (design.md
 * v2.34). The console issues it; the website's host redeems it once.
 *
 *  - Single use, 60 seconds, bound to the site and to the browser that asked for it (user-agent
 *    hash). Only the token's hash is kept in the cache, so a cache dump yields no usable link.
 *  - The same rules are checked when the link is issued and again when it is redeemed: the staff
 *    member holds platform.sites.impersonate, the site is active, its owner allows support
 *    sign-in, and the target is a member of the site — never the staff member, never platform staff.
 */
final readonly class SupportSignIn
{
    public const int TOKEN_TTL = 60;

    /** @var list<int> minutes a session may last */
    public const array DURATIONS = [15, 30, 60];

    private const string CACHE_PREFIX = 'support-sign-in:';

    public function __construct(
        private Tenancy $tenancy,
        private PlatformAccess $platform,
        private SupportAccess $access,
        private Entitlement $entitlement,
    ) {}

    /**
     * @throws AuthorizationException the staff member may not sign in as members
     * @throws ValidationException reason · minutes · site · member
     */
    public function issue(User $staff, Site $site, User $member, string $reason, int $minutes, string $userAgent): string
    {
        $this->assertStaff($staff);

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('admin.support.reason_required')]);
        }
        if (! in_array($minutes, self::DURATIONS, true)) {
            throw ValidationException::withMessages(['minutes' => __('admin.support.duration_invalid')]);
        }

        $siteId = Cast::int($site->getKey());
        $this->tenancy->enter($siteId, fn () => $this->assertTarget($staff, $member));

        $token = Str::random(48);
        Cache::put(self::CACHE_PREFIX.hash('sha256', $token), [
            'staff_id' => Cast::int($staff->getKey()),
            'member_id' => Cast::int($member->getKey()),
            'site_id' => $siteId,
            'reason' => $reason,
            'minutes' => $minutes,
            'ua' => hash('sha256', $userAgent),
        ], self::TOKEN_TTL);

        Audit::record('support.session.issued', [
            'site_id' => $siteId,
            'meta' => ['member' => $member->getAttribute('email'), 'reason' => $reason, 'minutes' => $minutes],
        ]);

        return $token;
    }

    /**
     * The link's facts — once — when it was made for this site and this browser.
     *
     * @return array{staff_id: int, member_id: int, reason: string, minutes: int}|null
     */
    public function redeem(string $token, int $siteId, string $userAgent): ?array
    {
        $key = self::CACHE_PREFIX.hash('sha256', $token);
        // One winner even for two requests in the same millisecond: add() is an atomic set-if-absent.
        if (! Cache::add($key.':used', true, self::TOKEN_TTL * 2)) {
            return null;
        }
        $data = Cache::pull($key);
        if (! is_array($data)
            || Cast::int($data['site_id'] ?? null) !== $siteId
            || ! hash_equals(Cast::string($data['ua'] ?? null), hash('sha256', $userAgent))) {
            return null;
        }

        return [
            'staff_id' => Cast::int($data['staff_id'] ?? null),
            'member_id' => Cast::int($data['member_id'] ?? null),
            'reason' => Cast::string($data['reason'] ?? null),
            'minutes' => Cast::int($data['minutes'] ?? null),
        ];
    }

    /** @throws AuthorizationException */
    public function assertStaff(User $staff): void
    {
        if (! $this->platform->can($staff, 'platform.sites.impersonate')) {
            throw new AuthorizationException(__('admin.support.not_allowed'));
        }
    }

    /**
     * Inside the site's context.
     *
     * @throws ValidationException
     */
    public function assertTarget(User $staff, User $member): void
    {
        if (! $this->entitlement->siteIsActive()) {
            throw ValidationException::withMessages(['site' => __('admin.support.site_inactive')]);
        }
        if (! $this->access->allowed()) {
            throw ValidationException::withMessages(['site' => __('admin.support.switched_off')]);
        }
        if ($member->is($staff) || $this->platform->isOperator($member) || ! $member->roles()->exists()) {
            throw ValidationException::withMessages(['member' => __('admin.support.member_invalid')]);
        }
    }
}

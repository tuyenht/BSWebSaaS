<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a support session at the first request after it stops being allowed: its time is up, it is
 * on another site's host, the staff member lost the right, the owner switched support sign-in off,
 * or the site is no longer active. Runs on the admin pages AND on /livewire/update, which skips
 * the admin route middleware.
 */
final readonly class EnforceSupportSession
{
    public function __construct(
        private SupportSession $support,
        private CurrentSite $current,
        private PlatformAccess $platform,
        private SupportAccess $access,
        private Entitlement $entitlement,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->support->current();
        $why = $session !== null ? $this->whyEnd($session) : null;
        if ($why === null) {
            return $next($request);
        }

        Audit::record('support.session.ended', ['meta' => ['why' => $why]]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->hasHeader('X-Livewire') || $request->expectsJson(), 403, __('admin.support.ended'));

        return redirect()->route('admin.login')->with('status', __('admin.support.ended'));
    }

    /**
     * @param  array{staff_id: int, staff_label: string, reason: string, site_id: int, expires_at: int, session_ulid: string|null}  $session
     */
    private function whyEnd(array $session): ?string
    {
        if ($session['expires_at'] <= now()->getTimestamp()) {
            return 'expired';
        }
        if (! $this->current->hasId() || $this->current->id() !== $session['site_id']) {
            return 'another_site';
        }

        $staff = User::query()->find($session['staff_id']);
        if (! $staff instanceof User || ! $this->platform->can($staff, 'platform.sites.impersonate')) {
            return 'staff_lost_right';
        }
        if (! $this->access->allowed()) {
            return 'switched_off';
        }

        return $this->entitlement->siteIsActive() ? null : 'site_inactive';
    }
}

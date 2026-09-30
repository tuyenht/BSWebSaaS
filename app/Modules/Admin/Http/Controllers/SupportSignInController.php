<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Notifications\SupportSignInNotification;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

/**
 * The website side of support sign-in (design.md v2.34): redeem the console's link, and leave.
 *
 * enter() is a GET because the console sends the browser across hosts: CSP form-action 'self'
 * forbids a cross-host form post. The token leaves the address bar at once (303 to the dashboard)
 * and the Referrer-Policy keeps query strings from other hosts.
 */
final readonly class SupportSignInController
{
    public function __construct(
        private SupportSignIn $signIn,
        private SupportSession $support,
        private CurrentSite $current,
    ) {}

    public function enter(Request $request): RedirectResponse
    {
        $token = $request->query('token');
        $link = is_string($token) && $token !== ''
            ? $this->signIn->redeem($token, $this->current->id(), (string) $request->userAgent())
            : null;
        $staff = $link !== null ? User::query()->find($link['staff_id']) : null;
        $member = $link !== null ? User::query()->find($link['member_id']) : null;

        if ($link === null || ! $staff instanceof User || ! $member instanceof User) {
            return $this->refuse();
        }

        try {
            $this->signIn->assertStaff($staff);
            $this->signIn->assertTarget($staff, $member);
        } catch (AuthorizationException|ValidationException) {
            return $this->refuse();
        }

        // Whatever this browser had on this host gives way; no "remember me", no intended URL.
        if ($this->support->active()) {
            Audit::record('support.session.ended', ['meta' => ['why' => 'replaced']]);
        }
        Auth::logout();
        $request->session()->invalidate();
        Auth::login($member);
        $request->session()->regenerate();

        $this->support->start($staff, $member, $this->current->id(), $link['reason'], now()->addMinutes($link['minutes']));
        Audit::record('support.session.entered', ['meta' => ['reason' => $link['reason'], 'minutes' => $link['minutes']]]);
        $this->tellOwners($staff, $member, $link['reason']);

        return redirect()->route('admin.dashboard', [], 303);
    }

    public function leave(Request $request): RedirectResponse
    {
        $wasSupport = $this->support->active();
        if ($wasSupport) {
            Audit::record('support.session.ended', ['meta' => ['why' => 'left']]);
        }

        $site = Site::query()->find($this->current->id());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $wasSupport && $site instanceof Site
            ? redirect()->away(route('platform.sites.show', ['ulid' => $site->getAttribute('ulid')]))
            : redirect()->route('admin.login');
    }

    private function refuse(): RedirectResponse
    {
        return redirect()->route('admin.login')->withErrors(['email' => __('admin.support.link_invalid')]);
    }

    /** Every owner of the website hears of it — in the bell and by mail (a locked notice). */
    private function tellOwners(User $staff, User $member, string $reason): void
    {
        try {
            $owners = User::role('owner')->get();
        } catch (RoleDoesNotExist) {
            return;
        }

        $at = app(SiteTime::class)->now()->format('d/m/Y H:i');
        foreach ($owners as $owner) {
            $owner->notify((new SupportSignInNotification(
                SupportSession::staffLabel($staff),
                SupportSession::labelOf($member),
                $reason,
                $at,
            ))->forCurrentSite());
        }
    }
}

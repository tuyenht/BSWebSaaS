<?php

use App\Modules\Admin\Livewire\ComponentGallery;
use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Http\Controllers\OAuthController;
use App\Modules\Admin\Http\Middleware\SetLocale;
use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\ForgotPassword;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Support\Locales;
use App\Modules\Identity\Models\User;
use App\Modules\Storefront\Http\Controllers\StorefrontController;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Two-plane routing (P0.8) + dynamic storefront render (P1.3). Order matters: the
 * storefront catch-all is LAST so the platform/admin/login routes win first.
 */

// ── Platform plane (Bảo Sơn operator) — dedicated host (PLATFORM_HOST), NO tenant context ──
// Guest: login + 2-step challenges (reused tenant components, plane-aware via AdminPlane).
Route::domain((string) config('tenancy.platform_host'))
    ->middleware(['web', SetLocale::class])
    ->group(function (): void {
        Route::get('/login', Login::class)->name('platform.login');
        Route::get('/otp/challenge', EmailOtpChallenge::class)->name('platform.otp.challenge');
        Route::get('/two-factor/challenge', TwoFactorChallenge::class)->name('platform.two-factor.challenge');
    });

// Protected: platform operators only (EnsurePlatformAccess). 2FA is enforced at login
// (TOTP or email-OTP), so the TOTP-enrollment ' 2fa' gate is not layered here.
Route::domain((string) config('tenancy.platform_host'))
    ->middleware(['web', 'auth', 'platform', SetLocale::class])
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('platform.settings.email'))->name('platform.dashboard');
        Route::get('/settings/email', EmailSettings::class)->defaults('scope', 'platform')->name('platform.settings.email');

        Route::post('/logout', function () {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('platform.login');
        })->name('platform.logout');
    });

// ── Tenant admin — login (guest, tenant context) ──────────────────────────────
Route::prefix(config('admin.prefix', 'admin'))
    ->middleware(['web', SetCurrentSite::class, 'site.active', SetLocale::class])
    ->group(function (): void {
        Route::get('/login', Login::class)->name('admin.login');
        Route::get('/forgot-password', ForgotPassword::class)->name('admin.password.request');
        Route::get('/reset-password', ResetPassword::class)->name('admin.password.reset');
        Route::get('/two-factor/challenge', TwoFactorChallenge::class)->name('admin.two-factor.challenge');
        Route::get('/otp/challenge', EmailOtpChallenge::class)->name('admin.otp.challenge');

        // OAuth2 redirect endpoints (social login + email-sender connect). Guest-reachable
        // (login needs them pre-auth); the 'email' purpose enforces auth in OAuthController.
        Route::get('/oauth/{provider}/start', [OAuthController::class, 'start'])->name('admin.oauth.start');
        // Social-login session completes here (origin host) via a single-use token from the
        // centralized callback — see the global oauth.callback route below.
        Route::get('/oauth/finish', [OAuthController::class, 'finish'])->name('admin.oauth.finish');

        // Email verification link target (signed, no auth — verification happens BEFORE the
        // email+password login is allowed to complete).
        Route::get('/email/verify/{id}/{hash}', function (\Illuminate\Http\Request $request, string $id, string $hash) {
            $user = \App\Modules\Identity\Models\User::find($id);

            if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                abort(403);
            }

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new \Illuminate\Auth\Events\Verified($user));
            }

            return redirect()->route('admin.login')->with('status', __('auth.email_verified'));
        })->name('admin.verification.verify');

        // Switch UI locale (the language switcher links here). Session-scoped, applied
        // by SetLocale; only accepts codes that have a lang/{code}.json file on disk.
        Route::get('/locale', function () {
            $set = (string) request('set');
            if (in_array($set, Locales::codes(), true)) {
                session(['locale' => $set]);
            }

            return back(fallback: route('admin.login'));
        })->name('admin.locale');
    });

// ── Tenant admin plane — protected (auth + tenant context + site membership) ───
Route::prefix(config('admin.prefix', 'admin'))
    ->middleware(['web', SetCurrentSite::class, 'site.active', 'auth', 'tenant.member', SetLocale::class])
    ->group(function (): void {
        Route::get('/', Dashboard::class)->name('admin.dashboard');
        Route::get('/settings', fn () => redirect()->route('admin.settings.security'))->name('admin.settings');
        Route::get('/settings/security', Settings::class)->name('admin.settings.security');
        // Site-wide settings — only roles with `settings.manage` (owner/admin/super_admin).
        // The Livewire components ALSO guard in boot() because the livewire/update endpoint
        // does not pass through this route middleware.
        Route::get('/settings/email', EmailSettings::class)->middleware('can:settings.manage')->name('admin.settings.email');
        Route::get('/settings/social', SocialSettings::class)->middleware('can:settings.manage')->name('admin.settings.social');

        // Dev-only design-system showcase (component mount() aborts in production).
        Route::get('/_gallery', ComponentGallery::class)->name('admin.gallery');

        // Content CRUD (W4 + post collections). One section-driven set of routes for
        // every entry type (?section=… picks the type); addressed by ulid (D2).
        Route::get('/content', EntriesIndex::class)->name('admin.entries.index');
        Route::get('/content/create', Editor::class)->name('admin.entries.create');
        Route::get('/content/{ulid}/edit', Editor::class)->name('admin.entries.edit');

        Route::post('/logout', function () {
            Auth::logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('admin.login');
        })->name('admin.logout');
    });

// Centralized OAuth2 callback — the ONE canonical redirect URI (project main domain) shared
// by all tenants + the platform. No tenant context: the controller resolves the origin from
// single-use state, enters that tenant, then stores (email) or bounces back to log in (social).
Route::get('/oauth/callback', [OAuthController::class, 'callback'])
    ->middleware('web')
    ->name('oauth.callback');

// ── Auth + 2FA-setup redirect targets (stubs until P1 UIs; precede the catch-all) ─
Route::get('/login', fn () => response()->json(['message' => 'Authentication required.'], 401))
    ->name('login');

Route::get('/two-factor/setup', fn () => response()->json(['message' => 'Two-factor authentication setup required.'], 423))
    ->name('two-factor.setup');

// ── Storefront plane — public, tenant context. A fallback so it never shadows a
//    defined route; resolves dynamic content for any other path (404 if none). ─────
Route::fallback(StorefrontController::class)
    ->middleware([SetCurrentSite::class, 'site.active']);

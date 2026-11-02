<?php

use App\Modules\Admin\Http\Controllers\OAuthController;
use App\Modules\Admin\Http\Controllers\SupportSignInController;
use App\Modules\Admin\Http\Controllers\ThemePreferenceController;
use App\Modules\Admin\Http\Middleware\EnforceSupportSession;
use App\Modules\Admin\Http\Middleware\SetLocale;
use App\Modules\Admin\Livewire\AcceptInvitation;
use App\Modules\Admin\Livewire\AppearanceSettings;
use App\Modules\Admin\Livewire\Catalog\Editor as CatalogEditor;
use App\Modules\Admin\Livewire\Catalog\Index as CatalogIndex;
use App\Modules\Admin\Livewire\CatalogSettings;
use App\Modules\Admin\Livewire\ComponentGallery;
use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Livewire\Entries\ReviewInbox;
use App\Modules\Admin\Livewire\ForgotPassword;
use App\Modules\Admin\Livewire\Forms\Detail as FormsDetail;
use App\Modules\Admin\Livewire\Forms\Inbox as FormsInbox;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\LoginSecuritySettings;
use App\Modules\Admin\Livewire\Media\Library as MediaLibrary;
use App\Modules\Admin\Livewire\Placeholder;
use App\Modules\Admin\Livewire\Platform\Audit\Index as AuditIndex;
use App\Modules\Admin\Livewire\Platform\Sites\Detail as SiteDetail;
use App\Modules\Admin\Livewire\Platform\Sites\Index as SiteIndex;
use App\Modules\Admin\Livewire\Platform\Tenants\Detail;
use App\Modules\Admin\Livewire\Platform\Tenants\Index;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Livewire\Settings\Domains as DomainSettings;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Livewire\Users\Index as UsersIndex;
use App\Modules\Admin\Livewire\WorkflowSettings;
use App\Modules\Admin\Support\Locales;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Media\Http\Controllers\MediaUploadController;
use App\Modules\Storefront\Http\Controllers\FormSubmissionController;
use App\Modules\Storefront\Http\Controllers\SitemapController;
use App\Modules\Storefront\Http\Controllers\StorefrontController;
use App\Modules\Tenancy\Http\Controllers\CaddyAskController;
use App\Modules\Tenancy\Http\Middleware\ServeOnPrimaryDomain;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
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
        Route::get('/forgot-password', ForgotPassword::class)->name('platform.password.request');
        Route::get('/reset-password', ResetPassword::class)->name('platform.password.reset');
        Route::get('/otp/challenge', EmailOtpChallenge::class)->name('platform.otp.challenge');
        Route::get('/two-factor/challenge', TwoFactorChallenge::class)->name('platform.two-factor.challenge');

        // Email verification target (signed, no auth) — mirrors the tenant route with a
        // platform-plane redirect, so an operator's verify link lands on the operator login.
        Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
            $user = User::find($id);

            if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                abort(403);
            }

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
            }

            return redirect()->route('platform.login')->with('status', __('auth.email_verified'));
        })->name('platform.verification.verify');

        // Switch UI locale on the platform console — mirrors admin.locale so the shared auth
        // layout's language switcher (AdminPlane::route('locale')) resolves on this plane too.
        Route::get('/locale', function () {
            $set = (string) request('set');
            if (in_array($set, Locales::codes(), true)) {
                session(['locale' => $set]);
            }

            return back(fallback: route('platform.login'));
        })->name('platform.locale');
    });

// Protected: platform operators only (EnsurePlatformAccess). 2FA is enforced at login
// (TOTP or email-OTP), so the TOTP-enrollment ' 2fa' gate is not layered here.
Route::domain((string) config('tenancy.platform_host'))
    ->middleware(['web', 'auth', 'auth.session', 'platform', SetLocale::class])
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('platform.tenants.index'))->name('platform.dashboard');

        // Operator tenant management (read-only). `tenants` is GLOBAL (D6) — queried with no
        // tenant context; addressed by ulid (D2, never the raw id).
        Route::get('/tenants', Index::class)->name('platform.tenants.index');
        Route::get('/tenants/{ulid}', Detail::class)->name('platform.tenants.show');

        // Operator site directory (read-only) + global audit log (cross-site).
        Route::get('/sites', SiteIndex::class)->name('platform.sites.index');
        Route::get('/sites/{ulid}', SiteDetail::class)->name('platform.sites.show');
        // One site's domain names; reading needs platform.sites.view, changing platform.sites.manage
        // (checked by the component on every request, including Livewire updates).
        Route::get('/sites/{ulid}/domains', DomainSettings::class)->name('platform.sites.domains');
        Route::get('/audit', AuditIndex::class)->name('platform.audit.index');

        Route::get('/settings/email', EmailSettings::class)->defaults('scope', 'platform')->name('platform.settings.email');
        Route::get('/settings/security', LoginSecuritySettings::class)->name('platform.settings.security');

        // Per-user Customizer persistence (fetch/JSON). Mirrored per plane because the
        // consoles live on different hosts and fetch() stays same-origin.
        Route::post('/theme-preference', [ThemePreferenceController::class, 'store'])->name('platform.theme.save');
        Route::delete('/theme-preference', [ThemePreferenceController::class, 'destroy'])->name('platform.theme.reset');

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

        // Member invitation link (mailed). Open to guests AND signed-in users: the page itself
        // decides the road (join · sign in · create the account). The token is 64 random chars.
        Route::get('/invitations/{token}', AcceptInvitation::class)
            ->where('token', '[A-Za-z0-9]{64}')
            ->middleware('throttle:30,1')
            ->name('admin.invitations.accept');

        // OAuth2 redirect endpoints (social login + email-sender connect). Guest-reachable
        // (login needs them pre-auth); the 'email' purpose enforces auth in OAuthController.
        Route::get('/oauth/{provider}/start', [OAuthController::class, 'start'])->name('admin.oauth.start');
        // Social-login session completes here (origin host) via a single-use token from the
        // centralized callback — see the global oauth.callback route below.
        Route::get('/oauth/finish', [OAuthController::class, 'finish'])->name('admin.oauth.finish');

        // Support sign-in: the console's single-use link lands here (a GET — CSP form-action 'self'
        // forbids a cross-host form post) and signs the staff member in as the chosen member.
        Route::get('/support/enter', [SupportSignInController::class, 'enter'])
            ->middleware('throttle:30,1')
            ->name('admin.support.enter');

        // Email verification link target (signed, no auth — verification happens BEFORE the
        // email+password login is allowed to complete).
        Route::get('/email/verify/{id}/{hash}', function (Request $request, string $id, string $hash) {
            $user = User::find($id);

            if (! $request->hasValidSignature() || ! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
                abort(403);
            }

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
                event(new Verified($user));
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
    ->middleware(['web', SetCurrentSite::class, EnforceSupportSession::class, 'site.active', 'auth', 'auth.session', 'tenant.member', SetLocale::class])
    ->group(function (): void {
        Route::get('/', Dashboard::class)->name('admin.dashboard');
        Route::get('/settings', fn () => redirect()->route('admin.settings.security'))->name('admin.settings');
        Route::get('/settings/security', Settings::class)->name('admin.settings.security');
        // Site-wide settings — only roles with `settings.manage` (owner/admin/super_admin).
        // The Livewire components ALSO guard in boot() because the livewire/update endpoint
        // does not pass through this route middleware.
        Route::get('/settings/email', EmailSettings::class)->middleware('can:settings.manage')->name('admin.settings.email');
        Route::get('/settings/social', SocialSettings::class)->middleware('can:settings.manage')->name('admin.settings.social');
        Route::get('/settings/appearance', AppearanceSettings::class)->middleware('can:settings.manage')->name('admin.settings.appearance');
        Route::get('/settings/catalog', CatalogSettings::class)->middleware('can:settings.manage')->name('admin.settings.catalog');
        // The site's domain names: whoever holds domains.view may look, domains.manage may change.
        // The component checks both itself (no route gate: a viewer gets the no-permission card).
        Route::get('/settings/domains', DomainSettings::class)->name('admin.settings.domains');
        // Approval-workflow switchboard — platform operators only (no tenant role template
        // grants this permission; super_admin passes via Gate::before).
        Route::get('/settings/workflows', WorkflowSettings::class)->middleware('can:platform.workflows.manage')->name('admin.settings.workflows');

        // Per-user Customizer persistence (fetch/JSON) — any authenticated member, own prefs only.
        Route::post('/theme-preference', [ThemePreferenceController::class, 'store'])->name('admin.theme.save');
        Route::delete('/theme-preference', [ThemePreferenceController::class, 'destroy'])->name('admin.theme.reset');

        // Site members + their roles (owner/admin). Add/change/remove routes through the guarded
        // AssignRole/RevokeRole actions, so the role hierarchy is enforced server-side.
        Route::get('/users', UsersIndex::class)->middleware('can:users.manage')->name('admin.users.index');

        // Dev-only design-system showcase (component mount() aborts in production).
        Route::get('/_gallery', ComponentGallery::class)->name('admin.gallery');

        // Content CRUD (W4 + post collections). One section-driven set of routes for
        // every entry type (?section=… picks the type); addressed by ulid (D2).
        Route::get('/content', EntriesIndex::class)->middleware('can:entries.view')->name('admin.entries.index');
        Route::get('/catalog', CatalogIndex::class)->middleware('can:catalog.view')->name('admin.catalog.index');
        Route::get('/catalog/create', CatalogEditor::class)->middleware('can:catalog.manage')->name('admin.catalog.create');
        Route::get('/catalog/{ulid}/edit', CatalogEditor::class)->middleware('can:catalog.manage')->name('admin.catalog.edit');
        // Review inbox — for people who APPROVE: gated on the review ability, never on edit
        // rights, so an approve-only role has a place to work (the component re-checks).
        Route::get('/content/review', ReviewInbox::class)->middleware('can:review,App\\Modules\\Cms\\Models\\Entry')->name('admin.entries.review');
        Route::get('/content/create', Editor::class)->middleware('can:entries.create')->name('admin.entries.create');
        Route::get('/content/{ulid}/edit', Editor::class)->name('admin.entries.edit');

        // Placeholder pages — the nav link is live but the real UI isn't built yet (taxonomies,
        // menu builder). Still gated at the route by the module's own ability, so only permitted
        // roles reach the stub — no dead-end, defence-in-depth intact.
        Route::get('/taxonomies', Placeholder::class)->middleware('can:taxonomies.manage')->name('admin.taxonomies.index');
        Route::get('/menus', Placeholder::class)->middleware('can:menus.manage')->name('admin.menus.index');

        // Lead inbox (contacts module). Read gated by forms.view; status changes inside the
        // Detail component re-gate on forms.manage (the livewire/update endpoint bypasses this
        // route middleware). Addressed by ulid (D2).
        Route::get('/forms', FormsInbox::class)->middleware('can:forms.view')->name('admin.forms.index');
        Route::get('/forms/{ulid}', FormsDetail::class)->middleware('can:forms.view')->name('admin.forms.show');

        // Media library (DAM). Read gated by media.view; upload/delete re-gate media.upload/.delete
        // inside the component (the livewire/update endpoint bypasses this route middleware).
        Route::get('/media', MediaLibrary::class)->middleware('can:media.view')->name('admin.media.index');

        // Upload endpoints (DAM): presign issues an R2 PUT URL, register finalizes (browser PUTs
        // straight to R2 — no PHP upload limit / Livewire-temp / proxy); upload is the local-disk
        // fallback. All re-gate media.upload.
        Route::post('/media/presign', [MediaUploadController::class, 'presign'])->middleware(['can:media.upload', 'throttle:300,1'])->name('admin.media.presign');
        Route::post('/media/register', [MediaUploadController::class, 'register'])->middleware('can:media.upload')->name('admin.media.register');
        Route::post('/media/upload', [MediaUploadController::class, 'upload'])->middleware('can:media.upload')->name('admin.media.upload');

        Route::post('/support/leave', [SupportSignInController::class, 'leave'])->name('admin.support.leave');

        Route::post('/logout', function () {
            if (app(SupportSession::class)->active()) {
                Audit::record('support.session.ended', ['meta' => ['why' => 'logged_out']]);
            }
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

// ── Caddy on_demand_tls ask-endpoint (infra). KHÔNG auth, KHÔNG tenant context:
//    Caddy nội bộ gọi để hỏi "có nên xin cert cho host này?". Fail-closed, chỉ kiểm
//    domains table. Để trong nhóm web mặc định — các middleware web (ShareErrors…)
//    phụ thuộc session nên không strip; tần suất gọi cực thấp (chỉ lúc cấp cert). ─
Route::get('/caddy/ask', CaddyAskController::class)->name('caddy.ask');

// ── Storefront lead capture (public) — POST /forms/{key}. Registered BEFORE the fallback;
//    CSRF-exempt (bootstrap/app.php): a session-less public endpoint guarded by Turnstile +
//    honeypot + rate-limit, and storefront HTML is edge/full-page cacheable so a per-session
//    CSRF token can't ride along. Resolves the form by key in the host's site context (RLS). ──
Route::post('/forms/{key}', FormSubmissionController::class)
    ->middleware([SetCurrentSite::class, 'site.active'])
    ->name('storefront.forms.submit');

// ── Per-tenant sitemap + robots (same middleware as storefront; registered BEFORE the
//    fallback so these explicit routes win). public/robots.txt is deleted; this route
//    emits the dynamic per-host Sitemap: line instead. ─────────────────────────────
Route::get('/sitemap.xml', SitemapController::class)
    ->middleware([ServeOnPrimaryDomain::class, SetCurrentSite::class, 'site.active']);

// Numbered pages of a site over the 50,000-URL limit, listed by /sitemap.xml's <sitemapindex>.
// {page} is a position (1, 2, …), never a route id; a leading zero falls through to a 404.
Route::get('/sitemap-{page}.xml', [SitemapController::class, 'page'])
    ->where('page', '[1-9][0-9]{0,5}')
    ->middleware([ServeOnPrimaryDomain::class, SetCurrentSite::class, 'site.active']);

Route::get('/robots.txt', function (Request $request) {
    $sitemap = $request->getScheme().'://'.$request->getHost().'/sitemap.xml';

    return response("User-agent: *\nDisallow:\nSitemap: {$sitemap}", 200, ['Content-Type' => 'text/plain']);
})->middleware([ServeOnPrimaryDomain::class, SetCurrentSite::class, 'site.active']);

// ── Storefront plane — public, tenant context. A fallback so it never shadows a
//    defined route; resolves dynamic content for any other path (404 if none). ─────
Route::fallback(StorefrontController::class)
    ->middleware([ServeOnPrimaryDomain::class, SetCurrentSite::class, 'site.active']);

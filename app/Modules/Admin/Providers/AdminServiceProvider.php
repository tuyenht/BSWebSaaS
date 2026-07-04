<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Http\Middleware\ResolveLivewireContext;
use App\Modules\Admin\Listeners\SetTenantReplyTo;
use App\Modules\Admin\Livewire\AppearanceSettings;
use App\Modules\Admin\Livewire\ComponentGallery;
use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Livewire\ForgotPassword;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\Placeholder;
use App\Modules\Admin\Livewire\Platform\Audit\Index as PlatformAuditIndex;
use App\Modules\Admin\Livewire\Platform\Sites\Detail as PlatformSitesDetail;
use App\Modules\Admin\Livewire\Platform\Sites\Index as PlatformSitesIndex;
use App\Modules\Admin\Livewire\Platform\Tenants\Detail as PlatformTenantsDetail;
use App\Modules\Admin\Livewire\Platform\Tenants\Index as PlatformTenantsIndex;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Livewire\Users\Index as UsersIndex;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\PlatformNav;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Cast;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Tenant admin shell (Livewire). Registers the admin view namespace + Livewire
 * components, supplies the dynamic nav to the layout, and re-homes Livewire's AJAX
 * update endpoint under the tenant middleware. The admin runs on the tenant's own
 * domain under /admin (P0.8 plane).
 */
final class AdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::addNamespace('admin', $this->app->basePath('app/Modules/Admin/resources/views'));

        // Admin UI primitives as anonymous Blade components: <x-admin::icon />, etc.
        Blade::anonymousComponentPath($this->app->basePath('app/Modules/Admin/resources/views/components'), 'admin');

        Livewire::component('admin.login', Login::class);
        Livewire::component('admin.forgot-password', ForgotPassword::class);
        Livewire::component('admin.reset-password', ResetPassword::class);
        Livewire::component('admin.two-factor-challenge', TwoFactorChallenge::class);
        Livewire::component('admin.otp-challenge', EmailOtpChallenge::class);
        Livewire::component('admin.settings', Settings::class);
        Livewire::component('admin.email-settings', EmailSettings::class);
        Livewire::component('admin.social-settings', SocialSettings::class);
        Livewire::component('admin.appearance-settings', AppearanceSettings::class);
        Livewire::component('admin.dashboard', Dashboard::class);
        Livewire::component('admin.component-gallery', ComponentGallery::class);
        Livewire::component('admin.placeholder', Placeholder::class);
        Livewire::component('admin.entries.index', EntriesIndex::class);
        Livewire::component('admin.entries.editor', Editor::class);
        Livewire::component('admin.users.index', UsersIndex::class);
        Livewire::component('admin.platform.tenants.index', PlatformTenantsIndex::class);
        Livewire::component('admin.platform.tenants.detail', PlatformTenantsDetail::class);
        Livewire::component('admin.platform.sites.index', PlatformSitesIndex::class);
        Livewire::component('admin.platform.sites.detail', PlatformSitesDetail::class);
        Livewire::component('admin.platform.audit.index', PlatformAuditIndex::class);

        // Branded, i18n password-reset email pointing at the tenant admin reset page
        // (resolved under the current tenant host + admin prefix at send time).
        ResetPasswordNotification::toMailUsing(function (mixed $notifiable, string $token): MailMessage {
            $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';

            return (new MailMessage)
                ->subject(__('auth.reset_email_subject'))
                ->greeting(Cast::string(config('admin.brand.name')))
                ->line(__('auth.reset_email_intro'))
                ->action(__('auth.reset_password'), route(AdminPlane::prefix().'.password.reset', [
                    'token' => $token,
                    'email' => $email,
                ]))
                ->line(__('auth.reset_email_outro'));
        });

        // Branded, i18n email-verification link (signed; verified BEFORE login completes).
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => URL::temporarySignedRoute(
            AdminPlane::prefix().'.verification.verify',
            now()->addMinutes(Cast::int(config('auth.verification.expire'), 60)),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        ));
        VerifyEmail::toMailUsing(fn (mixed $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject(__('auth.verify_email_subject'))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('auth.verify_email_intro'))
            ->action(__('auth.verify_email_action'), $url)
            ->line(__('auth.verify_email_outro')));

        // XOAUTH2 SMTP transport for OAuth2 email senders (Gmail/MS/Yahoo). TenantMailer
        // stashes host/port/username + a freshly-minted access token into this mailer's
        // config at apply time; under XOAUTH2 the access token IS the SMTP password.
        Mail::extend('admin_xoauth2', function (array $config): EsmtpTransport {
            $transport = new EsmtpTransport(
                Cast::string($config['host'] ?? null),
                Cast::int($config['port'] ?? null, 587),
                ($config['encryption'] ?? null) === 'ssl',
            );
            $transport->setAuthenticators([new XOAuth2Authenticator]);
            $transport->setUsername(Cast::string($config['username'] ?? null));
            $transport->setPassword(Cast::string($config['access_token'] ?? null));

            return $transport;
        });

        // Stamp the tenant's reply-to onto outgoing mail so replies reach the customer, not the
        // shared platform inbox (stateless per-message — no Octane reply-to leak).
        Event::listen(MessageSending::class, SetTenantReplyTo::class);

        // The dynamic menu (content_sections ∩ permissions) for every admin layout render.
        View::composer('admin::layouts.app', function (ViewContract $view): void {
            $view->with('navItems', app(AdminNav::class)->items(Auth::user()));
        });

        // Operator console nav (platform plane) — data-driven by platform permissions.
        View::composer('admin::layouts.platform', function (ViewContract $view): void {
            $view->with('platformNav', app(PlatformNav::class)->items(Auth::user()));
        });

        // Resolved admin-chrome theme (app → site → user) as <html data-*> attributes,
        // once per request per layout. Stateless resolver — Octane-safe by construction.
        View::composer(['admin::layouts.app', 'admin::layouts.platform'], function (ViewContract $view): void {
            $user = Auth::user();
            $user = $user instanceof User ? $user : null;
            $view->with('chrome', app(AdminChromeResolver::class)->attributes($user));

            // Primary-role label (highest level) for the topbar user area. Role slugs have no
            // display map, so headline() renders "super_admin" → "Super Admin". Roles are
            // team-scoped (current site, or platform team 0) and already warmed by the nav build.
            $primaryRole = $user ? $user->roles->sortByDesc('level')->first() : null;
            $roleName = $primaryRole?->getAttribute('name');
            $view->with('roleLabel', is_string($roleName) && $roleName !== '' ? Str::headline($roleName) : null);
        });

        // Livewire's AJAX update endpoint must run inside the tenant context so component
        // interactions stay RLS-isolated (SetCurrentSite resolves the site by host —
        // the admin is on the tenant's own domain). Auth stays at the component/page
        // level: the guest login and the authed admin share this single route.
        Livewire::setUpdateRoute(fn (array $handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', ResolveLivewireContext::class]));
    }
}

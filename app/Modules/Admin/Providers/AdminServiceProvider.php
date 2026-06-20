<?php

namespace App\Modules\Admin\Providers;

use App\Modules\Admin\Http\Middleware\ResolveLivewireContext;
use App\Modules\Admin\Livewire\ComponentGallery;
use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Livewire\ForgotPassword;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Livewire\SocialSettings;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Support\Cast;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
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
        Livewire::component('admin.dashboard', Dashboard::class);
        Livewire::component('admin.component-gallery', ComponentGallery::class);
        Livewire::component('admin.entries.index', EntriesIndex::class);
        Livewire::component('admin.entries.editor', Editor::class);

        // Branded, i18n password-reset email pointing at the tenant admin reset page
        // (resolved under the current tenant host + admin prefix at send time).
        ResetPasswordNotification::toMailUsing(function (mixed $notifiable, string $token): MailMessage {
            $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';

            return (new MailMessage)
                ->subject(__('auth.reset_email_subject'))
                ->greeting(Cast::string(config('admin.brand.name')))
                ->line(__('auth.reset_email_intro'))
                ->action(__('auth.reset_password'), route('admin.password.reset', [
                    'token' => $token,
                    'email' => $email,
                ]))
                ->line(__('auth.reset_email_outro'));
        });

        // Branded, i18n email-verification link (signed; verified BEFORE login completes).
        VerifyEmail::createUrlUsing(fn (User $notifiable): string => URL::temporarySignedRoute(
            'admin.verification.verify',
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
        Mail::extend('admin_xoauth2', function (array $config) {
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

        // The dynamic menu (content_sections ∩ permissions) for every admin layout render.
        View::composer('admin::layouts.app', function (ViewContract $view): void {
            $view->with('navItems', app(AdminNav::class)->items(Auth::user()));
        });

        // Livewire's AJAX update endpoint must run inside the tenant context so component
        // interactions stay RLS-isolated (SetCurrentSite resolves the site by host —
        // the admin is on the tenant's own domain). Auth stays at the component/page
        // level: the guest login and the authed admin share this single route.
        Livewire::setUpdateRoute(fn (array $handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', ResolveLivewireContext::class]));
    }
}

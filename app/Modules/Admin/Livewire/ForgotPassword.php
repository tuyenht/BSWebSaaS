<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin "forgot password" request. Validates the email and always replies with
 * the same generic confirmation to avoid account enumeration. Actual reset-link delivery
 * (mailer + token broker) is wired separately — this screen is the request entry point.
 *
 * Abuse-throttled like Login: reset mail goes out over the TENANT's own SMTP, so an
 * un-throttled spray would weaponise the tenant's sender identity / mail quota. Two hourly
 * ceilings (per email+IP, per IP) bound it; a throttled request returns the SAME generic
 * message so the cap itself leaks nothing.
 */
#[Layout('admin::layouts.auth')]
final class ForgotPassword extends Component
{
    use SwitchesLocale;

    private const int MAX_PER_EMAIL = 5;   // reset requests per email+IP per hour

    private const int MAX_PER_IP = 20;     // reset requests per source IP per hour (spray guard)

    public string $email = '';

    public string $status = '';

    public function submit(): void
    {
        // The field is labelled "Account", not "Email": name it the same way in its error message.
        $this->validate(['email' => 'required|email'], [], ['email' => Str::lower(__('auth.email_label'))]);

        $ip = (string) request()->ip();
        $key = 'admin-forgot:'.sha1(Str::lower($this->email).'|'.$ip);
        $ipKey = 'admin-forgot-ip:'.sha1($ip);

        // Over the cap → same generic reply (no enumeration, no throttle signal), just no send.
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_EMAIL) || RateLimiter::tooManyAttempts($ipKey, self::MAX_PER_IP)) {
            Audit::record('auth.password.forgot_throttled', ['actor_label' => $this->email]);
            $this->sentResponse();

            return;
        }

        RateLimiter::hit($key, 3600);
        RateLimiter::hit($ipKey, 3600);

        // The link is composed HERE, in the request — the queued notification runs in a worker
        // with no request host, which could pick neither the right plane (admin ↔ platform route
        // names) nor the right absolute host. Queuing also makes the reply constant-time: a
        // synchronous SMTP send is slower for an existing address than the broker's fast miss
        // for an unknown one — a timing oracle the uniform reply body alone cannot close.
        Password::sendResetLink(['email' => $this->email], function (User $user, string $token): void {
            $notification = (new ResetPasswordLinkNotification(AdminPlane::route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ])))->forCurrentSite();
            $notification->actorId = $user->id;

            $user->notify($notification);
        });

        $this->sentResponse();
    }

    /** Uniform "sent" reply — identical whether the address exists or the request was capped. */
    private function sentResponse(): void
    {
        $this->status = __('auth.reset_link_sent');
        $this->reset('email');
    }

    public function render(): View
    {
        $view = view('admin::livewire.forgot-password');
        $view->title(__('auth.forgot_title'));

        return $view;
    }
}

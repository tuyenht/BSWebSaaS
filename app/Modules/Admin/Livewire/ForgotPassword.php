<?php

namespace App\Modules\Admin\Livewire;

use App\Modules\Admin\Livewire\Concerns\FocusesAuthField;
use App\Modules\Admin\Livewire\Concerns\SwitchesLocale;
use App\Modules\Admin\Notifications\ResetPasswordLinkNotification;
use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Http\Middleware\EnsureTenantMember;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tenant admin "forgot password" request. Validates the email and always replies with
 * the same generic confirmation to avoid account enumeration. Actual reset-link delivery
 * (mailer + token broker) is wired separately — this screen is the request entry point.
 *
 * Abuse-throttled like Login: reset mail carries the website's sender name and counts on its
 * mail log, so an un-throttled spray would weaponise its identity. Two hourly ceilings (per
 * email+IP, per IP) bound it; a throttled request returns the SAME generic message so the cap
 * itself leaks nothing.
 *
 * On a website's host a link goes only to someone who may enter that website's admin (the same
 * rule as EnsureTenantMember); anyone else gets the same reply and no mail. The account is shared
 * by every website, so a website must never be able to start a reset of an account it does not
 * hold. The link itself always leaves through the platform's outbox (TenantMailer::applyPlatform()).
 * The platform console (no website) serves every account, as before.
 */
#[Layout('admin::layouts.auth')]
final class ForgotPassword extends Component
{
    use FocusesAuthField;
    use SwitchesLocale;

    private const int MAX_PER_EMAIL = 5;   // reset requests per email+IP per hour

    /** Every reply waits at least this long (microseconds); see submit(). */
    public const int REPLY_FLOOR_US = 1_000_000;

    private const int MAX_PER_IP = 20;     // reset requests per source IP per hour (spray guard)

    public string $email = '';

    /** The uniform "link sent" line shows; the view words it, so it follows a language switch. */
    public bool $sent = false;

    public function submit(): void
    {
        // The field is labelled "Account", not "Email": name it the same way in its error message.
        // A new request: the last "link sent" line no longer applies.
        $this->sent = false;
        $this->validateOrFocus(['email' => 'required|email'], ['email' => Str::lower(__('auth.email_label'))]);

        // Every way out below (capped, unknown address, not a member here, a repeated ask inside the
        // broker's throttle, a link made and queued) answers after the same floor, so the reply time
        // tells nobody whether an address has an account or belongs to this website. The floor sits
        // well above the slowest way (one Argon2id hash of the token, its row, the queue push, and
        // the broker's own 200 ms box).
        app(Timebox::class)->call(fn () => $this->answer(), self::REPLY_FLOOR_US);
    }

    private function answer(): void
    {
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

        // An unknown address, or not a member here → the same reply, no token made (an existing link
        // of theirs stays valid).
        $user = Password::broker()->getUser(['email' => $this->email]);
        if (! $user instanceof User || ! $this->mayResetHere($user)) {
            $this->sentResponse();

            return;
        }

        // The link is composed HERE, in the request — the queued notification runs in a worker
        // with no request host, which could pick neither the right plane (admin ↔ platform route
        // names) nor the right absolute host. Queuing also keeps the SMTP send out of the reply
        // time, under the floor above.
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

    /** No website (the platform console) serves every account; a website only its members. */
    private function mayResetHere(User $user): bool
    {
        return ! app(CurrentSite::class)->hasId() || app(EnsureTenantMember::class)->admits($user);
    }

    /** Uniform "sent" reply — identical whether the address exists or the request was capped. */
    private function sentResponse(): void
    {
        $this->sent = true;
        $this->reset('email');
    }

    public function render(): View
    {
        $view = view('admin::livewire.forgot-password');
        $view->title(__('auth.forgot_title'));

        return $view;
    }
}

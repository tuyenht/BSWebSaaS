<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Email one-time-passcode — the DEFAULT second factor for accounts that have not enrolled
 * an authenticator app (TOTP). A 6-digit CSPRNG code is stored hashed (sha256) in the
 * cache with a short TTL and single use; the plaintext code only ever lives in the email.
 */
final class EmailOtp
{
    private const int TTL_SECONDS = 600;     // code valid for 10 minutes

    private const int RESEND_COOLDOWN = 60;  // seconds the user must wait before re-sending

    private const int MAX_SENDS_PER_HOUR = 6;         // per account — bounds per-address email-bombing

    private const int MAX_SENDS_PER_HOUR_PER_IP = 20; // per source IP — one IP hitting many accounts

    /**
     * Emails a fresh code. Returns false WITHOUT sending when an hourly abuse cap is hit (per account
     * or per source IP) — a legit login + a few resends stays well under; a flood is dropped. The
     * cooldown (canResend) is the caller's first gate; these caps are the absolute ceiling.
     */
    public function send(User $user): bool
    {
        $idKey = 'email-otp-send:'.Cast::string($user->getAuthIdentifier());
        $ipKey = 'email-otp-send-ip:'.sha1((string) request()->ip());

        $accountCapped = RateLimiter::tooManyAttempts($idKey, self::MAX_SENDS_PER_HOUR);
        if ($accountCapped || RateLimiter::tooManyAttempts($ipKey, self::MAX_SENDS_PER_HOUR_PER_IP)) {
            Audit::record('auth.otp.throttled', [
                'actor_id' => $user->getAuthIdentifier(),
                'actor_label' => $user->email,
                'meta' => ['cap' => $accountCapped ? 'account' : 'ip'],
            ]);

            return false;
        }

        RateLimiter::hit($idKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($user), [
            'hash' => hash('sha256', $code),
            'sent_at' => time(),
        ], self::TTL_SECONDS);

        // Queued (ShouldQueue) so login never blocks on SMTP. A worker has no request state, so capture
        // it now: the site (isolation + mailer), the locale (else the code mails in app-default
        // language), and the actor (so a failed send is attributable in the security log).
        $note = (new EmailOtpNotification($code, (int) ceil(self::TTL_SECONDS / 60)))->forCurrentSite();
        $note->actorId = Cast::int($user->getAuthIdentifier());
        $note->locale(app()->getLocale());

        $user->notify($note);

        return true;
    }

    public function verify(User $user, string $code): bool
    {
        $record = Cache::get($this->key($user));

        if (! is_array($record) || ! isset($record['hash'])) {
            return false;
        }

        if (! hash_equals(Cast::string($record['hash']), hash('sha256', trim($code)))) {
            return false;
        }

        Cache::forget($this->key($user)); // single use

        return true;
    }

    /**
     * Burn the active code (e.g. after too many wrong attempts) so it can't be brute-forced;
     * the user must request a fresh one.
     */
    public function invalidate(User $user): void
    {
        Cache::forget($this->key($user));
    }

    public function canResend(User $user): bool
    {
        $record = Cache::get($this->key($user));

        return ! is_array($record) || (time() - Cast::int($record['sent_at'] ?? null, 0)) >= self::RESEND_COOLDOWN;
    }

    private function key(User $user): string
    {
        return 'email-otp:'.Cast::string($user->getAuthIdentifier());
    }
}

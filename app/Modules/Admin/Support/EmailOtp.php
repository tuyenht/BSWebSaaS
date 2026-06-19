<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Notifications\EmailOtpNotification;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Email one-time-passcode — the DEFAULT second factor for accounts that have not enrolled
 * an authenticator app (TOTP). A 6-digit CSPRNG code is stored hashed (sha256) in the
 * cache with a short TTL and single use; the plaintext code only ever lives in the email.
 */
final class EmailOtp
{
    private const TTL_SECONDS = 600;     // code valid for 10 minutes

    private const RESEND_COOLDOWN = 45;  // seconds the user must wait before re-sending

    public function send(User $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->key($user), [
            'hash' => hash('sha256', $code),
            'sent_at' => time(),
        ], self::TTL_SECONDS);

        app(TenantMailer::class)->apply();
        $user->notify(new EmailOtpNotification($code, (int) ceil(self::TTL_SECONDS / 60)));
    }

    public function verify(User $user, string $code): bool
    {
        $record = Cache::get($this->key($user));

        if (! is_array($record) || ! isset($record['hash'])) {
            return false;
        }

        if (! hash_equals((string) $record['hash'], hash('sha256', trim($code)))) {
            return false;
        }

        Cache::forget($this->key($user)); // single use

        return true;
    }

    public function canResend(User $user): bool
    {
        $record = Cache::get($this->key($user));

        return ! is_array($record) || (time() - (int) ($record['sent_at'] ?? 0)) >= self::RESEND_COOLDOWN;
    }

    private function key(User $user): string
    {
        return 'email-otp:'.$user->getAuthIdentifier();
    }
}

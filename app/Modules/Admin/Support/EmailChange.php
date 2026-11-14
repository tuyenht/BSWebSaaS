<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Notifications\EmailChangeCodeNotification;
use App\Modules\Admin\Notifications\EmailChangedNotification;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A member changing their own sign-in email, held in the cache while it is pending (nothing is
 * stored until it is confirmed). After the password (checked by the caller) the change passes the
 * second step the account signs in with: the authenticator app or a recovery code when it is on,
 * else a code mailed to the current address. Then a code goes to the new address; typing it moves
 * the account there and the old address is told.
 *
 * The codes work like the sign-in code (EmailOtp) — six CSPRNG digits, stored hashed, single
 * use, ten minutes — but under their own key and counters: a change code never passes as a
 * sign-in code and never uses up the sign-in code sends. Every step re-reads the pending change,
 * which belongs to the browser session that began it and to the address the account has now.
 */
final class EmailChange
{
    public const int TTL_SECONDS = 600;

    public const int RESEND_COOLDOWN = 60;

    /** Code mails per account, per source IP and per address in an hour. */
    public const int MAX_SENDS_PER_HOUR = 6;

    public const int MAX_SENDS_PER_HOUR_PER_IP = 20;

    public const int MAX_SENDS_PER_HOUR_PER_ADDRESS = 6;

    /** Wrong codes allowed before the code is burned (a mailed one) or the step pauses (the app). */
    public const int MAX_TRIES = 5;

    private const int TRIES_DECAY = 600;

    /** A change not finished this long after it began is dropped, however often its code was sent again. */
    public const int MAX_LIFETIME = 1800;

    public const string FACTOR_APP = 'app';

    public const string FACTOR_MAIL = 'mail';

    public const string SENT = 'sent';

    public const string WAIT = 'wait';

    public const string GONE = 'gone';

    public const string PASSED = 'passed';

    public const string WRONG = 'wrong';

    public const string EXPIRED = 'expired';

    public const string LOCKED = 'locked';

    public const string TAKEN = 'taken';

    public const string CHANGED = 'changed';

    /**
     * Begin a change to $newEmail (already checked by the caller, lower case) once the password
     * passed. Replaces any change pending for the account. Returns the second step it asks for.
     */
    public function begin(User $user, string $sessionId, string $newEmail): string
    {
        $factor = $user->two_factor_confirmed_at !== null ? self::FACTOR_APP : self::FACTOR_MAIL;
        $this->store($user, [
            'sid' => hash('sha256', $sessionId),
            'old' => $user->email,
            'new' => $newEmail,
            'factor' => $factor,
            'passed' => false,
            'hash' => null,
            'sent_at' => 0,
            'began_at' => now()->getTimestamp(),
        ]);

        return $factor;
    }

    /**
     * Mail a fresh code: to the current address for the second step ($toNew false, only when the
     * app is off), or to the new address once the second step passed. WAIT within the cooldown or
     * past an hourly cap (the member sees the same answer either way), GONE when nothing fits.
     */
    public function sendCode(User $user, string $sessionId, bool $toNew): string
    {
        $record = $this->pending($user, $sessionId);
        if ($record === null || $record['passed'] !== $toNew || (! $toNew && $record['factor'] !== self::FACTOR_MAIL)) {
            return self::GONE;
        }

        if ($record['hash'] !== null && now()->getTimestamp() - $record['sent_at'] < self::RESEND_COOLDOWN) {
            return self::WAIT;
        }

        $to = $toNew ? $record['new'] : $record['old'];
        $id = Cast::int($user->getKey());
        $caps = [
            'account' => ['email-change-send:'.$id, self::MAX_SENDS_PER_HOUR],
            'ip' => ['email-change-send-ip:'.sha1((string) request()->ip()), self::MAX_SENDS_PER_HOUR_PER_IP],
            // one cap per direction: another account naming this address as its new one must not
            // stop the codes this address's own member needs for a second step
            'address' => ['email-change-send-to:'.($toNew ? 'new:' : 'current:').sha1(Str::lower($to)), self::MAX_SENDS_PER_HOUR_PER_ADDRESS],
        ];
        foreach ($caps as $cap => [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                // one row per cap until it lifts, never one per refused click
                if (Cache::add($key.':noted', true, max(1, RateLimiter::availableIn($key)))) {
                    Audit::record('identity.email.code_throttled', [
                        'actor_id' => $id,
                        'actor_label' => $user->email,
                        'meta' => ['cap' => $cap],
                    ]);
                }

                return self::WAIT;
            }
        }
        foreach ($caps as [$key]) {
            RateLimiter::hit($key, 3600);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->store($user, [...$record, 'hash' => hash('sha256', $code), 'sent_at' => now()->getTimestamp()]);
        // A fresh code gets its own tries: at most MAX_TRIES per code, MAX_SENDS_PER_HOUR codes an hour.
        RateLimiter::clear($this->triesKey($user));

        $note = (new EmailChangeCodeNotification($code, intdiv(self::TTL_SECONDS, 60), $toNew))->forCurrentSite();
        $note->actorId = $id;
        $note->locale(app()->getLocale());
        // On demand to the captured address, never through the account: a queued mail must not
        // follow the account if its address changes before the worker sends it.
        Notification::route('mail', $to)->notify($note);

        return self::SENT;
    }

    /** The second step: the app's code or a recovery code, else the code mailed to the current address. */
    public function passFactor(User $user, string $sessionId, string $code, bool $recovery): string
    {
        $record = $this->pending($user, $sessionId);
        if ($record === null || $record['passed']) {
            return self::GONE;
        }

        if ($record['factor'] === self::FACTOR_APP) {
            if (! $this->countTry($user, $record)) {
                return self::LOCKED;
            }
            $service = app(TwoFactorService::class);
            $ok = $recovery
                ? $service->consumeRecoveryCode($user, Str::lower(trim($code)))
                : $service->verify(Cast::string($user->two_factor_secret), $code);
            if (! $ok) {
                return self::WRONG;
            }
        } else {
            $checked = $this->checkCode($user, $record, $code);
            if ($checked !== self::PASSED) {
                return $checked;
            }
        }

        RateLimiter::clear($this->triesKey($user));
        $this->store($user, [...$record, 'passed' => true, 'hash' => null, 'sent_at' => 0]);

        return self::PASSED;
    }

    /**
     * The last step: the code mailed to the new address. On a match the account moves there in
     * one transaction (a savepoint inside the request's), a reset link of either address is
     * dropped, the old address is told once the change is committed and it goes on record (field
     * names only). Another account holding the address — before or meanwhile — is caught by the
     * unique citext users.email itself: TAKEN, told only to whoever read that mailbox.
     */
    public function confirm(User $user, string $sessionId, string $code): string
    {
        $record = $this->pending($user, $sessionId);
        if ($record === null || ! $record['passed']) {
            return self::GONE;
        }

        $checked = $this->checkCode($user, $record, $code);
        if ($checked !== self::PASSED) {
            return $checked;
        }
        // Single use from here on, whatever the outcome below.
        Cache::forget($this->key($user));
        RateLimiter::clear($this->triesKey($user));

        $old = $record['old'];
        $new = $record['new'];
        try {
            $outcome = DB::transaction(function () use ($user, $old, $new): string {
                $now = User::query()->whereKey($user->getKey())->lockForUpdate()->value('email');
                // changed some other way meanwhile, or by a second request with the same code
                if (Str::lower(Cast::string($now)) !== Str::lower($old)) {
                    return self::GONE;
                }
                $user->forceFill(['email' => $new, 'email_verified_at' => now()])->save();
                DB::table(Cast::string(config('auth.passwords.users.table'), 'password_reset_tokens'))
                    ->whereIn('email', [$old, $new])
                    ->delete();

                return self::CHANGED;
            });
        } catch (UniqueConstraintViolationException) {
            $outcome = self::TAKEN;
        }

        if ($outcome !== self::CHANGED) {
            $user->refresh();

            return $outcome;
        }
        // A sign-in code already mailed to the old address must not sign anyone in after the move.
        app(EmailOtp::class)->invalidate($user);

        Audit::record('identity.email.changed', [
            'actor_id' => $user->getKey(),
            'actor_label' => $old,
            'meta' => ['fields' => ['email']],
        ]);

        $notice = (new EmailChangedNotification(app(SiteTime::class)->now()->format('d/m/Y H:i'), self::mask($new)))->forCurrentSite();
        $notice->actorId = Cast::int($user->getKey());
        $notice->locale(app()->getLocale());
        // after the commit: the old address never hears of a change that was rolled back
        $notice->afterCommit();
        Notification::route('mail', $old)->notify($notice);

        return self::CHANGED;
    }

    /** The new address of the change pending in this session, or null. */
    public function newAddress(User $user, string $sessionId): ?string
    {
        return $this->pending($user, $sessionId)['new'] ?? null;
    }

    /** Seconds until a paused second step takes codes again. */
    public function retryIn(User $user): int
    {
        return RateLimiter::availableIn($this->triesKey($user));
    }

    /** Drop the change pending in this browser session (one begun in another session stays). */
    public function cancel(User $user, string $sessionId): void
    {
        $value = Cache::get($this->key($user));
        if (is_array($value) && hash_equals(Cast::string($value['sid'] ?? null), hash('sha256', $sessionId))) {
            Cache::forget($this->key($user));
        }
    }

    /** "nguyen.van@congty.vn" → "n***@congty.vn": enough for the old address to know it, no more. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr(substr($email, 0, $at), 0, 1).'***'.substr($email, $at);
    }

    /**
     * @param  array{sid: string, old: string, new: string, factor: string, passed: bool, hash: string|null, sent_at: int, began_at: int}  $record
     */
    private function checkCode(User $user, array $record, string $code): string
    {
        if (! $this->countTry($user, $record)) {
            return self::LOCKED;
        }
        if ($record['hash'] === null || now()->getTimestamp() - $record['sent_at'] >= self::TTL_SECONDS) {
            return self::EXPIRED;
        }
        if (! hash_equals($record['hash'], hash('sha256', $code))) {
            return self::WRONG;
        }

        return self::PASSED;
    }

    /**
     * Count this try BEFORE checking it, so parallel requests cannot share one count. Past
     * MAX_TRIES the try is refused; the first refusal burns a mailed code (a fresh one is needed)
     * and goes on record once. A passing try clears the count (the caller does).
     *
     * @param  array{sid: string, old: string, new: string, factor: string, passed: bool, hash: string|null, sent_at: int, began_at: int}  $record
     */
    private function countTry(User $user, array $record): bool
    {
        $tries = RateLimiter::hit($this->triesKey($user), self::TRIES_DECAY);
        if ($tries <= self::MAX_TRIES) {
            return true;
        }

        if ($tries === self::MAX_TRIES + 1) {
            if ($record['hash'] !== null) {
                $this->store($user, [...$record, 'hash' => null]);
            }
            Audit::record('identity.email.change_locked', [
                'actor_id' => $user->getKey(),
                'actor_label' => $user->email,
                'meta' => ['step' => $record['passed'] ? 'new_address' : $record['factor']],
            ]);
        }

        return false;
    }

    /**
     * The change pending for the account, if it began in this browser session at most
     * MAX_LIFETIME ago, the account still has the address it began from and still signs in the
     * same way; else there is none.
     *
     * @return array{sid: string, old: string, new: string, factor: string, passed: bool, hash: string|null, sent_at: int, began_at: int}|null
     */
    private function pending(User $user, string $sessionId): ?array
    {
        $value = Cache::get($this->key($user));
        if (! is_array($value)) {
            return null;
        }

        $record = [
            'sid' => Cast::string($value['sid'] ?? null),
            'old' => Cast::string($value['old'] ?? null),
            'new' => Cast::string($value['new'] ?? null),
            'factor' => Cast::string($value['factor'] ?? null),
            'passed' => ($value['passed'] ?? null) === true,
            'hash' => is_string($value['hash'] ?? null) ? $value['hash'] : null,
            'sent_at' => Cast::int($value['sent_at'] ?? null),
            'began_at' => Cast::int($value['began_at'] ?? null),
        ];
        $factor = $user->two_factor_confirmed_at !== null ? self::FACTOR_APP : self::FACTOR_MAIL;
        if (! hash_equals($record['sid'], hash('sha256', $sessionId))
            || Str::lower($record['old']) !== Str::lower($user->email)
            || $record['factor'] !== $factor
            || $record['new'] === ''
            || now()->getTimestamp() - $record['began_at'] > self::MAX_LIFETIME) {
            return null;
        }

        return $record;
    }

    /**
     * @param  array{sid: string, old: string, new: string, factor: string, passed: bool, hash: string|null, sent_at: int, began_at: int}  $record
     */
    private function store(User $user, array $record): void
    {
        Cache::put($this->key($user), $record, self::TTL_SECONDS);
    }

    private function key(User $user): string
    {
        return 'email-change:'.Cast::int($user->getKey());
    }

    private function triesKey(User $user): string
    {
        return 'email-change-verify:'.Cast::int($user->getKey());
    }
}

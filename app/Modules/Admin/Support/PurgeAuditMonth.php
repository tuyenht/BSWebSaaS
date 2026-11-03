<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Exceptions\AuditMonthRefused;
use App\Modules\Audit\Support\AuditMonths;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Deleting one whole month of the activity log by hand: only the platform super-admin, after
 * entering their password again, and never the running month or the 12 before it (the database
 * refuses those whatever this class says; the check here gives the plain message). The month is
 * dropped and `audit.month_deleted` (month, rows, who) is written in one transaction, so the
 * deletion is never left unaudited.
 *
 * The password is checked against the acting user's own hash, as the `current_password` rule
 * does for the signed-in user. Wrong passwords are limited to ATTEMPTS per minute per person.
 */
final class PurgeAuditMonth
{
    public const string ACTION = 'audit.month_deleted';

    public const int ATTEMPTS = 5;

    public function __construct(
        private readonly PlatformAccess $platform,
        private readonly AuditMonths $months,
    ) {}

    /**
     * @return int the rows deleted
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, string $month, string $password): int
    {
        if (! $this->platform->isSuperAdmin($actor)) {
            throw new AuthorizationException(__('admin.audit.purge.denied'));
        }

        Validator::make(
            ['month' => $month, 'password' => $password],
            ['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'password' => ['required', 'string']],
            ['month.regex' => __('admin.audit.purge.invalid_month')],
        )->validate();

        $this->confirmPassword($actor, $password);

        if (! $this->months->isRemovable($month)) {
            throw ValidationException::withMessages(['month' => __('admin.audit.purge.too_recent', ['months' => AuditMonths::MANUAL_FLOOR_MONTHS])]);
        }

        try {
            return $this->months->drop([$month], self::ACTION, [
                'actor_id' => Cast::int($actor->getKey()),
                'actor_label' => $actor->email,
                'meta' => ['month' => $month],
            ]);
        } catch (AuditMonthRefused $e) {
            throw ValidationException::withMessages(['month' => match ($e->reason) {
                'kept' => __('admin.audit.purge.too_recent', ['months' => AuditMonths::MANUAL_FLOOR_MONTHS]),
                'busy' => __('admin.audit.purge.busy'),
                default => __('admin.audit.purge.invalid_month'),
            }]);
        }
    }

    /** @throws ValidationException */
    private function confirmPassword(User $actor, string $password): void
    {
        $hash = Cast::string($actor->getAuthPassword());
        if ($hash === '') {
            throw ValidationException::withMessages(['password' => __('admin.audit.purge.no_password')]);
        }

        $key = 'audit-month-purge:'.Cast::int($actor->getKey());
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['password' => __('admin.audit.purge.throttled', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        if (! Hash::check($password, $hash)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['password' => __('admin.audit.purge.wrong_password')]);
        }

        RateLimiter::clear($key);
    }
}

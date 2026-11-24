<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A recovery code is taken under a row lock on the member: the list is encrypted, so it is read
 * again under the lock, checked and written back in one transaction. Two sign-ins at once with
 * recovery codes therefore run one after the other, each on what the other wrote.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first take
 * runs on pgsql_admin inside a transaction left open, the second on the app connection with a short
 * lock_timeout: it waits on the member's row and times out (55P03). Once the first commits, a
 * request that loaded the member before it is refused the same code, and another such request's
 * code still works without bringing the first code back. Without the lock both would check the list
 * as they loaded it: the same code would pass again and the other write would restore it.
 */

/** Run a take on the app connection, giving up on a lock quickly: 'passed', 'refused' or the SQLSTATE. */
function recoveryTakeOnApp(User $user, string $code): string
{
    return DB::connection('pgsql')->transaction(function () use ($user, $code): string {
        DB::connection('pgsql')->statement("set local lock_timeout = '300ms'");
        try {
            return app(TwoFactorService::class)->consumeRecoveryCode($user, $code) ? 'passed' : 'refused';
        } catch (QueryException $e) {
            return (string) $e->getCode();
        }
    });
}

it('makes a second take wait on the member while the first is still open', function (): void {
    $service = app(TwoFactorService::class);
    $member = User::factory()->create();
    $secret = $service->generateSecret();
    $service->enable($member, $secret, totpCode($secret, -1));
    $codes = $member->fresh()->two_factor_recovery_codes;

    // Three requests on the app connection, each holding the member as loaded before any take.
    [$waits, $sameCode, $otherCode] = array_map(fn (): User => User::query()->findOrFail($member->id), [1, 2, 3]);
    $onAdmin = User::on('pgsql_admin')->findOrFail($member->id);

    $admin = DB::connection('pgsql_admin');
    $admin->beginTransaction();
    try {
        $first = $service->consumeRecoveryCode($onAdmin, $codes[0]);
        $whileOpen = recoveryTakeOnApp($waits, $codes[0]);
        $admin->commit();
    } catch (Throwable $e) {
        $admin->rollBack();
        throw $e;
    }

    expect($first)->toBeTrue()
        ->and($whileOpen)->toBe('55P03')
        ->and(recoveryTakeOnApp($sameCode, $codes[0]))->toBe('refused')
        ->and(recoveryTakeOnApp($otherCode, $codes[1]))->toBe('passed')
        ->and(User::query()->findOrFail($member->id)->two_factor_recovery_codes)->toBe(array_slice($codes, 2));
});

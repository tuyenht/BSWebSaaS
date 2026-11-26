<?php

use App\Modules\Admin\Support\Mail\MailHeld;
use App\Modules\Admin\Support\Mail\MailQuotaGate;
use App\Modules\Admin\Support\Mail\MailSendContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Two queued mails of one website racing for its last place of the day. Each takes its place in
 * one statement (an upsert that refuses to go past the ceiling) inside its job's transaction, so
 * the second waits on the website's day row until the first commits, then sees the first's count and
 * is held back: the ceiling is never passed and nothing is counted twice. Another website's mails
 * do not wait.
 *
 * PHP runs one statement at a time, so the race is staged on two real connections: the first job's
 * reservation runs on pgsql_admin and stays uncommitted while the second runs on the app connection
 * with a short lock_timeout. A wait that reaches the timeout fails the attempt (the queue tries it
 * again) instead of letting the mail leave uncounted.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 / 2; the cascade empties mail_daily and site_features
    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->create(['feature_key' => MailQuotaGate::FEATURE, 'value' => 1]));
    }
});

function mqlToday(int $site): string
{
    return app(Tenancy::class)->enter($site, fn (): string => app(SiteTime::class)->now()->toDateString());
}

/** The second job: site $site's reservation on the app connection, waiting at most 300 ms. */
function mqlReserve(int $site): MailSendContext
{
    return app(Tenancy::class)->enter($site, function (): MailSendContext {
        DB::statement("set local lock_timeout = '300ms'");
        $context = new MailSendContext;
        app(MailQuotaGate::class)->reserve($context);

        return $context;
    });
}

it('makes the second mail of one website wait for the first, then holds it at the ceiling', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->table('mail_daily')->insert(['site_id' => 1, 'day' => mqlToday(1), 'via' => 'platform', 'sent' => 9]); // one place left of 10
    $admin->beginTransaction();

    try {
        // The first job takes the last place and has not committed yet (its mail is leaving).
        $admin->update("update mail_daily set sent = sent + 1 where site_id = 1 and via = 'platform' and sent < 10");

        $waited = null;
        try {
            mqlReserve(1);
        } catch (QueryException $e) {
            $waited = $e->errorInfo[0] ?? null;
        }
        expect($waited)->toBe('55P03');

        // Another website is not held up.
        expect(mqlReserve(2)->reserved)->toBeTrue();
    } finally {
        $admin->commit();
    }

    // The retry sees the first job's count and is held, without counting itself.
    expect(fn () => mqlReserve(1))->toThrow(MailHeld::class);
    expect((int) $admin->table('mail_daily')->where('site_id', 1)->value('sent'))->toBe(10)
        ->and((int) $admin->table('mail_daily')->where('site_id', 2)->value('sent'))->toBe(1);
});

it('counts two mails of one website one after the other, each once', function (): void {
    $first = mqlReserve(1);
    $second = mqlReserve(1);

    expect($first->reserved)->toBeTrue()
        ->and($second->reserved)->toBeTrue()
        ->and((int) DB::connection('pgsql_admin')->table('mail_daily')->where('site_id', 1)->value('sent'))->toBe(2);
});

<?php

declare(strict_types=1);

use App\Modules\Shared\Events\MailQuotaReached;
use Illuminate\Support\Facades\Mail;

/*
 * The operator hears, on the monitoring mailer, when a website reaches its abuse ceiling of the
 * shared outbox (its ordinary mails wait for the next day) and when the platform nears its own
 * daily ceiling. A website's 80% / 100% thresholds are not for the operator. The counting side
 * sends each threshold once a day; a mail that cannot be sent never throws.
 */

beforeEach(function (): void {
    $this->mqnPrefix = enableMonitoring();
    app()->setLocale('en');
});

afterEach(function (): void {
    forgetMonitoringKeys($this->mqnPrefix);
});

it('mails the operator when a website reaches its abuse ceiling', function (): void {
    $before = count(monitoringMails());

    event(new MailQuotaReached('site_abuse', 7, '2026-11-26', 300, 30));

    $mails = array_slice(monitoringMails(), $before);
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getSubject())->toContain('#7')
        ->and($mails[0]->getTextBody())->toContain('300')
        ->and($mails[0]->getTextBody())->toContain('30');
});

it('mails the operator when the platform nears its daily ceiling', function (): void {
    $before = count(monitoringMails());

    event(new MailQuotaReached('platform_90', null, '2026-11-26', 900, 1000));

    $mails = array_slice(monitoringMails(), $before);
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->getTextBody())->toContain('2026-11-26');
});

it('does not mail the operator about a website reaching its own limit', function (string $threshold): void {
    $before = count(monitoringMails());

    event(new MailQuotaReached($threshold, 7, '2026-11-26', 30, 30));

    expect(monitoringMails())->toHaveCount($before);
})->with(['site_80', 'site_100']);

it('never throws when the notice cannot be sent', function (): void {
    config(['mail.mailers.monitoring.transport' => 'smtp', 'mail.mailers.monitoring.port' => 1]);
    Mail::purge('monitoring');

    expect(fn () => event(new MailQuotaReached('site_abuse', 7, '2026-11-26', 300, 30)))->not->toThrow(Throwable::class);
});

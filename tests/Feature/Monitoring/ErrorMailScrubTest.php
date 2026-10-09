<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Http\Middleware\SetCurrentSite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Mime\Email;

/*
 * What an error notice may carry is an ALLOW-LIST: time, environment, site and its host, the route
 * NAME and PATTERN (never the real path, the query string or any URL), the HTTP method, job class or
 * command name, exception class, file:line of the first application frame, the count, the user's
 * numeric id and a well-formed request id. Never the exception message, the request body, SQL text
 * or bindings — only the SQLSTATE for a database error. These tests send the known carriers of
 * personal data through a real request and look for them in everything the mail holds.
 */

/** Everything a reader (or the mail provider) can see: headers, subject and body. */
function monitoringMailText(Email $mail): string
{
    return $mail->getHeaders()->toString()."\n".$mail->getSubject()."\n".$mail->getTextBody();
}

beforeEach(function (): void {
    seedTenancy();
    $this->monitoringPrefix = enableMonitoring();

    Route::middleware(SetCurrentSite::class)->group(function (): void {
        Route::post('/__monitoring-probe/{token}', fn () => throw new RuntimeException(
            'Order for khach@example.com, phone 0912345678901, failed',
        ))->name('monitoring.probe');

        Route::get('/__monitoring-sql', fn () => DB::select(
            'select * from monitoring_no_such_table where email = ?',
            ['binding-secret@example.com'],
        ))->name('monitoring.sql');
    });

    Route::get('/__monitoring-platform', fn () => throw new LogicException('platform fault'));
});

afterEach(function (): void {
    forgetMonitoringKeys($this->monitoringPrefix);
});

it('mails the route pattern, never the path, the query, the body or the message', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeader('X-Request-Id', 'req-7f3a.9_b')
        ->post('http://a.test/__monitoring-probe/RESETTOKEN987654?invite=INVITE555', [
            'password' => 'Sup3rSecret!',
            'email' => 'khach@example.com',
        ])
        ->assertStatus(500);

    $mails = monitoringMails();
    expect($mails)->toHaveCount(1);
    $text = monitoringMailText($mails[0]);

    expect($text)
        ->toContain('monitoring.probe')
        ->toContain('__monitoring-probe/{token}')
        ->toContain('POST')
        ->toContain('RuntimeException')
        ->toContain('tests/Feature/Monitoring/ErrorMailScrubTest.php:')
        ->toContain('#1')
        ->toContain('a.test')
        ->toContain('req-7f3a.9_b')
        ->toContain((string) $user->getKey())
        ->not->toContain('RESETTOKEN987654')
        ->not->toContain('INVITE555')
        ->not->toContain('invite=')
        ->not->toContain('Sup3rSecret')
        ->not->toContain('khach@example.com')
        ->not->toContain('0912345678901')
        ->not->toContain('Order for')
        ->not->toContain($user->email)
        ->not->toContain('http://')
        ->not->toContain('https://');
});

it('mails only the SQLSTATE of a database error, never the SQL or its bindings', function (): void {
    $this->get('http://a.test/__monitoring-sql')->assertStatus(500);

    $mails = monitoringMails();
    expect($mails)->toHaveCount(1);
    $text = monitoringMailText($mails[0]);

    expect($text)
        ->toContain('42P01')
        ->toContain('QueryException')
        ->not->toContain('binding-secret@example.com')
        ->not->toContain('monitoring_no_such_table')
        ->not->toContain('select');
});

it('leaves out a request id that does not look like one', function (): void {
    $this->withHeader('X-Request-Id', 'bad id <script>alert(1)</script>')
        ->post('http://a.test/__monitoring-probe/abc')
        ->assertStatus(500);

    $text = monitoringMailText(monitoringMails()[0]);

    expect($text)
        ->not->toContain('bad id')
        ->not->toContain('script')
        ->not->toContain(__('monitoring.mail.field.request_id', [], 'vi'));
});

it('names the platform, not a site, for an error outside any site', function (): void {
    $this->get('http://platform.invalid/__monitoring-platform')->assertStatus(500);

    $text = monitoringMailText(monitoringMails()[0]);

    expect($text)
        ->toContain(__('monitoring.mail.site_platform', [], 'vi'))
        ->toContain('LogicException')
        ->not->toContain('platform.invalid')
        ->not->toContain('platform fault');
});

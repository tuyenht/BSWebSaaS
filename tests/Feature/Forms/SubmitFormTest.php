<?php

use App\Modules\Forms\Actions\SubmitForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * SubmitForm (Increment 2) — lead-capture pipeline (§16/§23/§27). Anti-spam runs BEFORE the
 * lead is trusted: a filled honeypot or a failed Turnstile check flags the submission as spam
 * (stored for review, never notified). A clean lead is stored 'new', its typed columns are
 * graduated for the inbox, and the notify_emails recipients are emailed best-effort. RLS
 * (BelongsToSite) stamps site_id from the current context.
 */

beforeEach(function (): void {
    seedTenancy();
    Notification::fake();
    // Truncate both settings tables so no stale SMTP ciphertext (encrypted under a different
    // key in the shared dev DB) can cause a DecryptException during TenantMailer::apply()
    // and silently swallow the best-effort lead notification.
    DB::connection('pgsql_admin')->statement(
        'truncate site_settings, platform_settings restart identity cascade'
    );
    // Deterministic: Turnstile unconfigured (dev) → server-side check skipped (honeypot still guards).
    config(['services.turnstile.secret_key' => '']);
});

/**
 * @param  list<string>  $notify
 */
function contactForm(int $site = 1, array $notify = ['sales@anvinh.test']): Form
{
    return app(Tenancy::class)->enter($site, fn () => Form::query()->create([
        'key' => 'contact',
        'name' => ['vi' => 'Liên hệ'],
        'notify_emails' => $notify,
    ]));
}

it('captures a clean lead as new, graduates typed columns, and notifies recipients', function (): void {
    $form = contactForm();

    $submission = app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách A', 'email' => 'a@example.test', 'phone' => '0900000000', 'message' => 'Báo giá đồ gá'],
        honeypot: null,
        turnstileToken: null,
        meta: ['ip' => '203.0.113.7', 'user_agent' => 'PestUA', 'source_url' => 'https://av1.test/lien-he'],
    ));

    expect($submission->site_id)->toBe(1)
        ->and($submission->status)->toBe('new')
        ->and($submission->is_spam)->toBeFalse()
        ->and($submission->name)->toBe('Khách A')
        ->and($submission->email)->toBe('a@example.test')
        ->and($submission->phone)->toBe('0900000000')
        ->and($submission->data['message'])->toBe('Báo giá đồ gá')
        ->and($submission->source_url)->toBe('https://av1.test/lien-he')
        ->and($submission->ip_address)->toBe('203.0.113.7');

    Notification::assertSentOnDemand(NewLeadNotification::class);
});

it('flags a honeypot-tripped submission as spam and does not notify', function (): void {
    $form = contactForm();

    $submission = app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Bot', 'email' => 'bot@spam.test'],
        honeypot: 'http://spam.example',
    ));

    expect($submission->status)->toBe('spam')
        ->and($submission->is_spam)->toBeTrue();

    Notification::assertNothingSent();
});

it('flags the submission as spam when turnstile verification fails', function (): void {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $form = contactForm();

    $submission = app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách', 'email' => 'k@example.test'],
        honeypot: null,
        turnstileToken: 'bad-token',
    ));

    expect($submission->status)->toBe('spam')
        ->and($submission->is_spam)->toBeTrue();

    Notification::assertNothingSent();
});

it('passes turnstile when verification succeeds and stores the lead as new', function (): void {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);

    $form = contactForm();

    $submission = app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách', 'email' => 'k@example.test'],
        honeypot: null,
        turnstileToken: 'good-token',
    ));

    expect($submission->status)->toBe('new')
        ->and($submission->is_spam)->toBeFalse();

    Notification::assertSentOnDemand(NewLeadNotification::class);
});

it('fails closed (flags spam, no crash) when turnstile is unreachable', function (): void {
    config(['services.turnstile.secret_key' => 'test-secret']);
    Http::fake(function (): void {
        throw new ConnectionException('Connection timed out');
    });

    $form = contactForm();

    $submission = app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách', 'email' => 'k@example.test'],
        honeypot: null,
        turnstileToken: 'a-token',
    ));

    expect($submission->status)->toBe('spam')->and($submission->is_spam)->toBeTrue();
    Notification::assertNothingSent();
});

it('caps an over-long source_url to the column limit (no insert crash)', function (): void {
    $form = contactForm();
    // A Referer with many UTM params easily exceeds varchar(255); Postgres rejects (not truncates).
    $longUrl = 'https://av1.test/lien-he?'.str_repeat('utm_source=x&', 40);

    $submission = app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách', 'email' => 'k@example.test'],
        honeypot: null,
        turnstileToken: null,
        meta: ['source_url' => $longUrl],
    ));

    expect(mb_strlen((string) $submission->source_url))->toBeLessThanOrEqual(255)
        ->and($submission->status)->toBe('new');
});

it('sets reply-to to the lead email so the recipient replies to the customer', function (): void {
    $form = contactForm();
    $submission = app(Tenancy::class)->enter(1, fn () => $form->submissions()->create([
        'name' => 'Khách A',
        'email' => 'khach@example.test',
        'data' => ['name' => 'Khách A', 'email' => 'khach@example.test'],
    ]));

    $mail = new NewLeadNotification($submission, $form)->toMail(new AnonymousNotifiable);

    expect($mail->replyTo[0][0])->toBe('khach@example.test') // MailMessage stores [address, name] tuples
        ->and($mail->replyTo[0][1])->toBe('Khách A');
});

it('does not notify when the form has no recipients', function (): void {
    $form = contactForm(notify: []);

    app(Tenancy::class)->enter(1, fn () => app(SubmitForm::class)->handle(
        $form,
        ['name' => 'Khách', 'email' => 'k@example.test'],
    ));

    // No lead EMAIL without recipients. (Members who may read leads still get the in-app notice —
    // a separate channel, covered by the notifications tests.)
    Notification::assertSentTimes(NewLeadNotification::class, 0);
    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(1);
});

<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/*
 * Storefront lead submission (Increment 3) — the public POST endpoint POST /forms/{key}. It is
 * CSRF-exempt (public, session-less; defended by Turnstile + honeypot + rate-limit per §23/R20),
 * resolves the form by key within the host's site context (RLS), validates the payload, captures
 * the lead via SubmitForm, and redirects back with a localized confirmation. A missing/disabled
 * form 404s; too many attempts from one IP are throttled with a human-readable error.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    Notification::fake();
    Cache::flush(); // reset the per-IP rate-limiter so it doesn't leak across tests (form id is always 1)
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    config(['services.turnstile.secret_key' => '']); // dev → Turnstile skipped (honeypot still guards)

    app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'contact',
        'name' => ['vi' => 'Liên hệ'],
        'notify_emails' => ['sales@anvinh.test'],
        'status' => 'active',
    ]));
});

/**
 * @param  array<string, string>  $payload
 */
function submitContact(array $payload): TestResponse
{
    return test()->post('http://a.test/forms/contact', $payload);
}

it('captures a valid lead, redirects back with a confirmation, and notifies', function (): void {
    submitContact([
        'name' => 'Khách A',
        'email' => 'a@example.test',
        'phone' => '0900000000',
        'message' => 'Cần báo giá đồ gá',
    ])->assertRedirect()->assertSessionHas('form_success');

    $leads = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->get());

    expect($leads)->toHaveCount(1)
        ->and($leads->first()->status)->toBe('new')
        ->and($leads->first()->name)->toBe('Khách A')
        ->and($leads->first()->email)->toBe('a@example.test');

    Notification::assertSentOnDemand(NewLeadNotification::class);
});

it('rejects a payload missing the required name + message with friendly errors', function (): void {
    submitContact(['email' => 'a@example.test'])
        ->assertRedirect()
        ->assertSessionHasErrors(['name', 'message']);

    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(0);
    Notification::assertNothingSent();
});

it('renders validation errors in the site locale regardless of app.locale', function (): void {
    config(['app.locale' => 'en']); // prod default — errors must still be vi for a vi site

    submitContact(['email' => 'a@example.test']) // missing name + message
        ->assertSessionHasErrors(['name' => 'Vui lòng nhập họ tên.']);
});

it('answers the visitor in the language of the page they filled in', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]));

    submitContact(['email' => 'a@example.test', 'locale' => 'en'])   // missing name + message
        ->assertSessionHasErrors(['name' => 'Please enter your name.']);
});

it('falls back to the site default for a language visitors cannot open', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => false]));

    submitContact(['email' => 'a@example.test', 'locale' => 'en'])   // en is not public yet
        ->assertSessionHasErrors(['name' => 'Vui lòng nhập họ tên.']);
    submitContact(['email' => 'a@example.test', 'locale' => 'xx'])
        ->assertSessionHasErrors(['name' => 'Vui lòng nhập họ tên.']);
});

it('emails the site owner in the site language whatever language the visitor used', function (): void {
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]));

    submitContact(['name' => 'Visitor', 'email' => 'v@example.test', 'message' => 'Quote please', 'locale' => 'en'])
        ->assertSessionHas('form_success');

    Notification::assertSentOnDemand(NewLeadNotification::class, fn (NewLeadNotification $note): bool => $note->locale === 'vi');
});

it('requires at least one of email or phone', function (): void {
    submitContact(['name' => 'Khách', 'message' => 'Xin chào'])
        ->assertRedirect()
        ->assertSessionHasErrors(['email', 'phone']);
});

it('captures but flags a honeypot-tripped submission as spam without notifying', function (): void {
    submitContact([
        'name' => 'Bot',
        'email' => 'bot@spam.test',
        'message' => 'spam',
        'company_website' => 'http://spam.example', // hidden honeypot a human never fills
    ])->assertRedirect();

    $lead = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->firstOrFail());
    expect($lead->status)->toBe('spam')->and($lead->is_spam)->toBeTrue();

    Notification::assertNothingSent();
});

it('404s when the form key does not resolve in this site', function (): void {
    test()->post('http://a.test/forms/nope', ['name' => 'x', 'message' => 'y', 'email' => 'a@b.test'])
        ->assertNotFound();
});

it('throttles repeated submissions from the same IP', function (): void {
    $payload = ['name' => 'Khách', 'email' => 'a@example.test', 'message' => 'Xin chào'];

    for ($i = 0; $i < 5; $i++) {
        submitContact($payload)->assertSessionHasNoErrors();
    }

    submitContact($payload)->assertSessionHasErrors('form');

    // Only the 5 allowed attempts were stored; the throttled 6th never reached SubmitForm.
    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(5);
});

/** Add the consent checkbox the provisioned contact form carries (required, labelled per language). */
function addConsentField(): void
{
    app(Tenancy::class)->enter(1, fn () => Form::query()->firstWhere('key', 'contact')->fields()->create([
        'key' => 'consent', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 5,
        'label' => ['vi' => 'Tôi đồng ý để website lưu thông tin.', 'en' => 'I agree this website may store my details.'],
    ]));
}

it('refuses a lead whose form asks for consent until the box is ticked', function (): void {
    addConsentField();

    submitContact(['name' => 'Khách A', 'phone' => '0900000000', 'message' => 'Cần báo giá'])
        ->assertRedirect()
        ->assertSessionHasErrors(['consent' => 'Vui lòng đánh dấu ô đồng ý để chúng tôi được lưu và dùng thông tin liên hệ của bạn.']);

    expect(app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count()))->toBe(0);
});

it('keeps the sentence the visitor agreed to, in their language, as the evidence of consent', function (): void {
    addConsentField();
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]));

    submitContact(['name' => 'Guest', 'phone' => '0900000000', 'message' => 'Quote please', 'consent' => '1', 'locale' => 'en'])
        ->assertRedirect()->assertSessionHas('form_success');

    $lead = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->firstOrFail());
    expect($lead->data['consent'])->toBe('I agree this website may store my details.');
});

it('does not ask for consent on a form without a consent box', function (): void {
    submitContact(['name' => 'Khách B', 'phone' => '0900000001', 'message' => 'Hỏi giá'])
        ->assertRedirect()->assertSessionHasNoErrors();
});

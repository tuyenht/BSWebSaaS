<?php

use App\Modules\Forms\Components\FormComponent;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * A lead is validated against the fields the site defined for its form: every field the visitor is
 * shown is checked and kept, nothing else is. A field a visitor could never answer (a key that
 * would clash with the platform's own inputs, a choice field without choices) is neither shown nor
 * collected, so it can never make a form impossible to send.
 */

beforeEach(function (): void {
    seedTenancy();
    Notification::fake();
    Cache::flush(); // the per-IP rate limiter
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    config(['services.turnstile.secret_key' => '', 'services.turnstile.site_key' => '']);
});

/**
 * @param  list<array<string, mixed>>  $fields
 */
function lrForm(string $key, array $fields): void
{
    app(Tenancy::class)->enter(1, function () use ($key, $fields): void {
        Form::query()->create(['key' => $key, 'name' => ['vi' => $key], 'status' => 'active'])
            ->fields()->createMany($fields);
    });
}

/**
 * @return array<string, mixed>
 */
function lrField(string $key, string $type, bool $required = false, array $extra = []): array
{
    static $order = 0;

    return ['key' => $key, 'type' => $type, 'label' => ['vi' => ucfirst($key)], 'is_required' => $required, 'sort_order' => ++$order, ...$extra];
}

function lrLead(): ?FormSubmission
{
    return app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->first());
}

/** @return list<string> the keys the storefront form block shows for a form */
function lrShown(string $key): array
{
    $data = app(Tenancy::class)->enter(1, fn () => app(FormComponent::class)->data(['form_key' => $key]));

    return array_column($data['fields'], 'key');
}

const LR_SERVICE_CHOICES = ['choices' => [
    ['value' => 'cnc', 'label' => ['vi' => 'Gia công CNC']],
    ['value' => 'mold', 'label' => ['vi' => 'Khuôn mẫu']],
]];

it('keeps a field the form defines beyond the contact ones', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('phone', 'tel', true), lrField('company', 'text', true)]);

    test()->post('http://a.test/forms/quote', ['name' => 'A', 'phone' => '0900000000', 'company' => 'ACME'])
        ->assertSessionHasNoErrors();

    expect(lrLead()?->data)->toBe(['name' => 'A', 'phone' => '0900000000', 'company' => 'ACME']);
});

it('takes a lead from a form that has no message field', function (): void {
    lrForm('callback', [lrField('name', 'text', true), lrField('phone', 'tel', true)]);

    test()->post('http://a.test/forms/callback', ['name' => 'A', 'phone' => '0900000000'])
        ->assertSessionHasNoErrors();

    expect(lrLead())->not->toBeNull();
});

it('refuses a lead that leaves a required field empty, naming the field', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('company', 'text', true)]);

    test()->post('http://a.test/forms/quote', ['name' => 'A'])
        ->assertSessionHasErrors(['company' => 'Vui lòng điền Company.']);

    expect(lrLead())->toBeNull();
});

it('keeps nothing the form does not define', function (): void {
    lrForm('quote', [lrField('name', 'text', true)]);

    test()->post('http://a.test/forms/quote', ['name' => 'A', 'is_admin' => '1', 'message' => 'x'])
        ->assertSessionHasNoErrors();

    expect(lrLead()?->data)->toBe(['name' => 'A']);
});

it('caps a text answer at its column limit', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('details', 'textarea')]);

    test()->post('http://a.test/forms/quote', ['name' => 'A', 'details' => str_repeat('x', 5001)])
        ->assertSessionHasErrors(['details' => 'Details quá dài (tối đa 5000 ký tự).']);
});

it('neither shows nor requires a select while the form block cannot draw its choices yet', function (): void {
    // The block draws a select as a text box today: asking for a choice the visitor cannot see
    // would make the form impossible to send. Once the block draws choices this flips.
    lrForm('quote', [lrField('name', 'text', true), lrField('service', 'select', true, ['options' => LR_SERVICE_CHOICES])]);

    expect(lrShown('quote'))->toBe(['name']);
    test()->post('http://a.test/forms/quote', ['name' => 'A', 'service' => 'Gia công CNC'])->assertSessionHasNoErrors();
    expect(lrLead()?->data)->toBe(['name' => 'A']);
});

it('refuses a list where the form asks for one answer', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('company', 'text')]);

    test()->post('http://a.test/forms/quote', ['name' => ['A', 'B'], 'company' => ['x' => 'y']])
        ->assertSessionHasErrors(['name', 'company']);
    expect(lrLead())->toBeNull();
});

it('takes a lead that leaves only a phone when email and phone are both optional', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('email', 'email'), lrField('phone', 'tel')]);

    test()->post('http://a.test/forms/quote', ['name' => 'A', 'phone' => '0900000000'])->assertSessionHasNoErrors();
    expect(lrLead()?->email)->toBeNull();
});

it('answers a wrong address in any email field in plain words', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('work_email', 'email')]);

    test()->post('http://a.test/forms/quote', ['name' => 'A', 'work_email' => 'abc'])
        ->assertSessionHasErrors(['work_email' => __('forms.err_email_invalid')]);
});

it('never files a text answer keyed "email" as the lead\'s reply address unless it is one', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('email', 'text')]);

    test()->post('http://a.test/forms/quote', ['name' => 'A', 'email' => 'abc'])->assertSessionHasNoErrors();
    expect(lrLead()?->email)->toBeNull()
        ->and(lrLead()?->data['email'])->toBe('abc');
});

it('neither shows nor requires a select that has no choices, so the form can still be sent', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('need', 'select', true)]);

    expect(lrShown('quote'))->toBe(['name']);
    test()->post('http://a.test/forms/quote', ['name' => 'A', 'need' => 'x'])->assertSessionHasNoErrors();
    expect(lrLead()?->data)->toBe(['name' => 'A']);
});

it('neither shows nor collects a field whose key clashes with the platform\'s own inputs', function (string $key): void {
    lrForm('quote', [lrField($key, 'text', true), lrField('name', 'text', true)]);

    expect(lrShown('quote'))->toBe(['name']);
    // `_method` would make the framework re-route the post itself, whatever the form defines: the
    // point is that no visitor is ever shown such a field, so the form never sends one.
    $answer = $key === '_method' ? [] : [$key => 'x'];
    test()->post('http://a.test/forms/quote', [...$answer, 'name' => 'A'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(lrLead()?->data)->toBe(['name' => 'A']);
})->with(['form', 'locale', 'lead_form', 'company_website', '_method', 'a.b', 'Name', '1st']);

it('still asks for one of email or phone when the form has both and requires neither', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('email', 'email'), lrField('phone', 'tel')]);

    test()->post('http://a.test/forms/quote', ['name' => 'A'])
        ->assertSessionHasErrors(['email' => __('forms.err_contact_required'), 'phone' => __('forms.err_contact_required')]);
});

it('looks the form and its fields up once per lead', function (): void {
    lrForm('quote', [lrField('name', 'text', true), lrField('consent', 'checkbox', true)]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->post('http://a.test/forms/quote', ['name' => 'A', 'consent' => '1'])->assertSessionHasNoErrors();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $sql): bool => (bool) preg_match('/from "forms"/', $sql))->count())->toBe(1)
        ->and($queries->filter(fn (string $sql): bool => (bool) preg_match('/from "form_fields"/', $sql))->count())->toBe(1)
        ->and(lrLead()?->data['consent'])->toBe('Consent');
});

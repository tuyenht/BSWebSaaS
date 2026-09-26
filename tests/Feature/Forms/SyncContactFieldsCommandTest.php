<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * forms:sync-contact-fields brings the contact form of sites provisioned earlier up to the current
 * set of fields (today: the required consent box). It only ADDS missing fields — a site's recipients,
 * labels and existing fields stay as they are — and skips a site that has no contact form.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    DB::connection('pgsql_admin')->statement('truncate form_submissions, form_fields, forms restart identity cascade');
});

it('adds the consent box to an existing contact form without touching what the site set', function (): void {
    app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'notify_emails' => ['sales@a.test'], 'status' => 'active',
    ])->fields()->create(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Tên của bạn'], 'is_required' => true, 'sort_order' => 1]));

    $this->artisan('forms:sync-contact-fields')->assertSuccessful();
    $this->artisan('forms:sync-contact-fields')->assertSuccessful(); // idempotent

    [$form, $keys, $nameLabel] = app(Tenancy::class)->enter(1, function (): array {
        $form = Form::query()->where('key', 'contact')->with('fields')->firstOrFail();

        return [$form, $form->fields->pluck('key')->all(), $form->fields->firstWhere('key', 'name')->label];
    });

    expect($keys)->toBe(['name', 'email', 'phone', 'message', 'consent'])
        ->and($form->notify_emails)->toBe(['sales@a.test'])
        ->and($nameLabel)->toBe(['vi' => 'Tên của bạn']);
});

it('leaves a site without a contact form alone', function (): void {
    $this->artisan('forms:sync-contact-fields')->assertSuccessful();

    expect(app(Tenancy::class)->enter(2, fn () => Form::query()->count()))->toBe(0);
});

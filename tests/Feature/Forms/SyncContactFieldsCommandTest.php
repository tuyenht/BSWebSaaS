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
        // The site's own wording stays; only the languages it lacked are added.
        ->and($nameLabel['vi'])->toBe('Tên của bạn')
        ->and(array_keys($nameLabel))->toBe(['en', 'ja', 'vi', 'zh']);
});

it('rewrites the consent sentence from the translations only when asked, and nothing else', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']);
        $form->fields()->create(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Tên của bạn'], 'is_required' => true, 'sort_order' => 1]);
        $form->fields()->create(['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Câu đồng ý cũ'], 'is_required' => true, 'sort_order' => 5]);
    });
    $labels = fn (): array => app(Tenancy::class)->enter(1, fn (): array => Form::query()->where('key', 'contact')
        ->with('fields')->firstOrFail()->fields->pluck('label', 'key')->all());

    $this->artisan('forms:sync-contact-fields')->assertSuccessful();
    $before = $labels();
    expect($before['consent']['vi'])->toBe('Câu đồng ý cũ');

    $this->artisan('forms:sync-contact-fields --refresh-consent')
        ->expectsOutputToContain('Consent sentences rewritten: 1.')
        ->assertSuccessful();
    $after = $labels();
    expect($after['consent']['vi'])->toBe(__('forms.consent_label', [], 'vi'))
        ->and(array_keys($after['consent']))->toBe(['en', 'ja', 'vi', 'zh'])
        ->and($after['name'])->toBe($before['name'])
        ->and($after['name']['vi'])->toBe('Tên của bạn');

    $this->artisan('forms:sync-contact-fields --refresh-consent')
        ->expectsOutputToContain('Consent sentences rewritten: 0.')
        ->assertSuccessful();
});

it('gives a Vietnamese-only form the languages it lacks, keeping every word the site wrote', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $form = Form::query()->create([
            'key' => 'contact', 'name' => ['vi' => 'Liên hệ báo giá'], 'status' => 'active',
            'success_message' => ['vi' => 'Đã nhận, cảm ơn anh/chị.'],
        ]);
        $form->fields()->create(['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Yêu cầu của bạn'], 'is_required' => true, 'sort_order' => 4]);
        $form->fields()->create(['key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Công ty'], 'is_required' => false, 'sort_order' => 9]);
    });

    $this->artisan('forms:sync-contact-fields')->assertSuccessful();

    [$form, $labels] = app(Tenancy::class)->enter(1, function (): array {
        $form = Form::query()->where('key', 'contact')->with('fields')->firstOrFail();

        return [$form, $form->fields->pluck('label', 'key')->all()];
    });

    expect($form->name)->toBe(['en' => __('forms.contact_form_name', [], 'en'), 'ja' => __('forms.contact_form_name', [], 'ja'), 'vi' => 'Liên hệ báo giá', 'zh' => __('forms.contact_form_name', [], 'zh')])
        ->and($form->success_message['vi'])->toBe('Đã nhận, cảm ơn anh/chị.')
        ->and($form->success_message['en'])->toBe(__('forms.submit_success', [], 'en'))
        ->and($labels['message']['vi'])->toBe('Yêu cầu của bạn')
        ->and($labels['message']['en'])->toBe(__('forms.lead_field_message', [], 'en'))
        ->and($labels['name']['en'])->toBe(__('forms.lead_field_name', [], 'en'))
        // A field the site added itself is not the platform's wording to fill.
        ->and($labels['company'])->toBe(['vi' => 'Công ty']);
});

it('leaves a site without a contact form alone', function (): void {
    $this->artisan('forms:sync-contact-fields')->assertSuccessful();

    expect(app(Tenancy::class)->enter(2, fn () => Form::query()->count()))->toBe(0);
});

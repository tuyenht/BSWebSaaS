<?php

use App\Modules\Forms\Actions\EnsureContactForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * The default contact form a new site gets: name and phone share the first row, the email takes the
 * next, and the phone, email and message carry an example in every interface language. A form a site
 * already has keeps its order and wording; only missing fields are added.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test)
    DB::connection('pgsql_admin')->statement('truncate form_submissions, form_fields, forms restart identity cascade');
});

/** @return array<string, array{sort: int, options: mixed}> */
function contactFieldsOfSite1(): array
{
    return app(Tenancy::class)->enter(1, fn (): array => Form::query()->where('key', 'contact')->firstOrFail()->fields()->get()
        ->mapWithKeys(fn ($field): array => [$field->key => ['sort' => $field->sort_order, 'options' => $field->options]])->all());
}

it('gives a new form name and phone first, then email, message and the consent box', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(EnsureContactForm::class)->handle(['sales@a.test']));

    expect(array_keys(contactFieldsOfSite1()))->toBe(['name', 'phone', 'email', 'message', 'consent']);
});

it('writes the examples of the phone, email and message in every interface language', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(EnsureContactForm::class)->handle([]));
    $fields = contactFieldsOfSite1();

    expect($fields['phone']['options']['placeholder']['vi'])->toBe('0912 345 678')
        ->and($fields['email']['options']['placeholder']['vi'])->toBe('ten@congty.vn')
        ->and($fields['message']['options']['placeholder']['vi'])->toBe('Ví dụ: sản phẩm, số lượng, thời gian cần…')
        ->and(array_keys($fields['phone']['options']['placeholder']))->toBe(['en', 'ja', 'vi', 'zh'])
        ->and($fields['message']['options']['placeholder']['en'])->not->toBe($fields['message']['options']['placeholder']['vi'])
        ->and($fields['name']['options'])->not->toHaveKey('placeholder')
        ->and($fields['consent']['options'])->not->toHaveKey('placeholder');
});

it('leaves the order and the examples of a form the site already has as they are', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active'])->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'email', 'type' => 'email', 'label' => ['vi' => 'Email'], 'sort_order' => 2],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Số điện thoại'], 'sort_order' => 3],
            ['key' => 'message', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung'], 'is_required' => true, 'sort_order' => 4],
        ]);
    });
    $before = contactFieldsOfSite1();

    app(Tenancy::class)->enter(1, fn () => app(EnsureContactForm::class)->handle([]));
    $after = contactFieldsOfSite1();

    expect(array_keys($after))->toBe(['name', 'email', 'phone', 'message', 'consent'])
        ->and(array_intersect_key($after, $before))->toBe($before);
});

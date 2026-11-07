<?php

use App\Modules\Admin\Livewire\Forms\Detail;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Notifications\NewLeadNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * A lead reads the way the visitor filled it in: each answer under its field's label, in the form's
 * order, a picked choice by its label. A ticked consent box shows the sentence stored with the lead
 * when it was sent — the evidence — even after the box's wording changes. The owner's email lists
 * every answer, not only the contact ones.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * A quote form (contact + company + service select + consent) and one lead on it.
 *
 * @param  array<string, mixed>  $data
 * @return array{0: Form, 1: FormSubmission}
 */
function quoteLead(array $data): array
{
    return app(Tenancy::class)->enter(1, function () use ($data): array {
        $form = Form::query()->create(['key' => 'quote', 'name' => ['vi' => 'Báo giá'], 'status' => 'active']);
        $form->fields()->createMany([
            ['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1],
            ['key' => 'phone', 'type' => 'tel', 'label' => ['vi' => 'Số điện thoại'], 'sort_order' => 2],
            ['key' => 'company', 'type' => 'text', 'label' => ['vi' => 'Công ty'], 'sort_order' => 3],
            ['key' => 'service', 'type' => 'select', 'label' => ['vi' => 'Nhu cầu'], 'sort_order' => 4,
                'options' => ['choices' => [['value' => 'cnc', 'label' => ['vi' => 'Gia công CNC']]]]],
            ['key' => 'details', 'type' => 'textarea', 'label' => ['vi' => 'Nội dung cần báo giá'], 'sort_order' => 5],
            ['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Câu đồng ý hiện tại'], 'is_required' => true, 'sort_order' => 6],
        ]);
        $lead = $form->submissions()->create(['data' => $data, 'name' => 'Khách A', 'status' => 'read']);

        return [$form, $lead];
    });
}

function openLead(FormSubmission $lead): TestResponse
{
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));

    return test()->actingAs($viewer)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk();
}

it('shows each answer under its field label, in the form\'s order, a choice by its label', function (): void {
    [, $lead] = quoteLead(['details' => 'Trục 40 cái', 'company' => 'ACME', 'name' => 'Khách A', 'service' => 'cnc', 'phone' => '0900000000']);

    openLead($lead)
        ->assertSeeInOrder(['Họ tên', 'Khách A', 'Số điện thoại', '0900000000', 'Công ty', 'ACME', 'Nhu cầu', 'Gia công CNC', 'Nội dung cần báo giá', 'Trục 40 cái'])
        ->assertDontSee('>company<', false)
        ->assertDontSee('>service<', false);
});

it('shows the consent sentence stored with the lead, not the box\'s current wording', function (): void {
    [, $lead] = quoteLead(['name' => 'Khách A', 'consent' => 'Câu đồng ý lúc khách gửi.']);

    openLead($lead)
        ->assertSee(__('forms.detail_ticked'))
        ->assertSee('Câu đồng ý lúc khách gửi.')
        ->assertDontSee('Câu đồng ý hiện tại');
});

it('says a box was ticked when no sentence was stored with it', function (): void {
    [, $lead] = quoteLead(['name' => 'Khách A', 'consent' => true]);

    openLead($lead)->assertSee(__('forms.detail_ticked_no_text'));
});

it('still shows an answer whose field was removed later, under its key', function (): void {
    [, $lead] = quoteLead(['name' => 'Khách A', 'budget' => '50 triệu']);

    openLead($lead)->assertSeeInOrder(['budget', '50 triệu']);
});

it('keeps showing the labels after a manager changes the status', function (): void {
    [, $lead] = quoteLead(['name' => 'Khách A', 'company' => 'ACME']);
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($manager, 'editor', ['forms.view', 'forms.manage']));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)
        ->test(Detail::class, ['ulid' => $lead->ulid])
        ->call('setStatus', 'archived')
        ->assertSee('Công ty')
        ->assertSee('ACME'));
});

it('lists every answer in the owner\'s email under its label, without the consent sentence', function (): void {
    [$form, $lead] = quoteLead(['name' => 'Khách A', 'company' => 'ACME', 'service' => 'cnc', 'consent' => 'Câu đồng ý lúc khách gửi.']);

    $lines = implode("\n", app(Tenancy::class)->enter(1, fn () => (new NewLeadNotification($lead, $form))->toMail(new AnonymousNotifiable)->introLines));

    expect($lines)->toContain('**Công ty:** ACME')
        ->toContain('**Nhu cầu:** Gia công CNC')
        ->not->toContain('Câu đồng ý');
});

it('shows a picked choice by its value when its label is empty in that language', function (): void {
    [$form, $lead] = quoteLead(['name' => 'Khách A', 'service' => 'cnc']);
    app(Tenancy::class)->enter(1, fn () => $form->fields()->where('key', 'service')
        ->update(['options' => ['choices' => [['value' => 'cnc', 'label' => ['vi' => '']]]]]));

    openLead($lead)->assertSeeInOrder(['Nhu cầu', 'cnc']);
});

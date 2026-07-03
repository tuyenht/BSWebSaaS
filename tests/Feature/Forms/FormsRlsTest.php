<?php

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Forms foundation — RLS isolation (§2 / Gate #1). A lead captured for one site must never
 * be readable in another site's context (RLS + FORCE, fail-closed). BelongsToSite stamps
 * site_id from the current context on create.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) + 2 (b.test)
});

it('stamps site_id from the current site context on create', function (): void {
    $form = app(Tenancy::class)->enter(1, fn () => Form::query()->create([
        'key' => 'contact',
        'name' => ['vi' => 'Liên hệ'],
    ]));

    expect($form->site_id)->toBe(1);
});

it('isolates form submissions per site (RLS fail-closed)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ']]);
        FormSubmission::query()->create([
            'form_id' => $form->id,
            'name' => 'Khách A',
            'email' => 'a@example.test',
        ]);
    });

    // Site 1 sees its own lead; site 2 sees none.
    $inSite1 = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->count());
    $inSite2 = app(Tenancy::class)->enter(2, fn () => FormSubmission::query()->count());

    expect($inSite1)->toBe(1)
        ->and($inSite2)->toBe(0);
});

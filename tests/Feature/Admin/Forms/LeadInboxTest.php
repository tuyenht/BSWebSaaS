<?php

use App\Modules\Admin\Livewire\Forms\Detail;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * Lead inbox (Increment 5) — tenant-plane admin. Read is gated by forms.view (site-scoped: view on
 * one site is not membership on another); leads are RLS-isolated per site. Status changes require
 * forms.manage (the view/manage split) — a 'new' lead auto-marks 'read' only for a manager. The
 * list is eager-loaded (flat query budget, no N+1).
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * @param  array<string, mixed>  $attrs
 */
function seedLead(int $site, array $attrs = []): FormSubmission
{
    return app(Tenancy::class)->enter($site, function () use ($attrs) {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ']]);

        return FormSubmission::query()->create(array_merge([
            'form_id' => $form->id,
            'data' => ['name' => 'Khách', 'message' => 'Xin chào'],
            'name' => 'Khách',
            'email' => 'k@example.test',
            'status' => 'new',
        ], $attrs));
    });
}

it('lets a member with forms.view open the inbox and see its leads', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Alpha Corp']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee(__('forms.inbox_title'))
        ->assertSee('Alpha Corp');
});

it('forbids a member without forms.view', function () {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/forms')->assertForbidden();
});

it('isolates leads per site (RLS) — site A inbox never shows site B leads', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Alpha Corp']);
    seedLead(2, ['name' => 'Bravo Ltd']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee('Alpha Corp')
        ->assertDontSee('Bravo Ltd');
});

it('filters the inbox by status', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Fresh Lead', 'status' => 'new']);
    seedLead(1, ['name' => 'Junk Lead', 'status' => 'spam', 'is_spam' => true]);

    $this->actingAs($viewer)->get('http://a.test/admin/forms?status=spam')
        ->assertOk()
        ->assertSee('Junk Lead')
        ->assertDontSee('Fresh Lead');
});

it('auto-marks a new lead read when a manager opens it', function () {
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($manager, 'admin', ['forms.view', 'forms.manage']));
    $lead = seedLead(1, ['status' => 'new']);

    $this->actingAs($manager)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk();

    $status = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->find($lead->id)?->status);
    expect($status)->toBe('read');
});

it('does not mutate a new lead when a view-only member opens it', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $lead = seedLead(1, ['status' => 'new']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk();

    $status = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->find($lead->id)?->status);
    expect($status)->toBe('new');
});

it('lets a manager change a lead status', function () {
    $manager = User::factory()->create();
    $lead = seedLead(1, ['status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead) {
        grantRole($manager, 'admin', ['forms.view', 'forms.manage']);

        Livewire::actingAs($manager->fresh())
            ->test(Detail::class, ['ulid' => $lead->ulid])
            ->call('setStatus', 'archived');

        expect(FormSubmission::query()->find($lead->id)?->status)->toBe('archived');
    });
});

it('forbids a view-only member from changing a lead status', function () {
    $viewer = User::factory()->create();
    $lead = seedLead(1, ['status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($viewer, $lead) {
        grantRole($viewer, 'editor', ['forms.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Detail::class, ['ulid' => $lead->ulid])
            ->call('setStatus', 'spam')
            ->assertForbidden();

        expect(FormSubmission::query()->find($lead->id)?->status)->toBe('new');
    });
});

it('lists leads on a flat query budget (no N+1 across rows)', function () {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer) {
        grantRole($viewer, 'editor', ['forms.view']);
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ']]);
        foreach (range(1, 10) as $i) {
            FormSubmission::query()->create([
                'form_id' => $form->id,
                'data' => ['name' => "Khách {$i}"],
                'name' => "Khách {$i}",
                'status' => 'new',
            ]);
        }
    });

    $count = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms')->assertOk());

    expect($count)->toBeLessThanOrEqual(18); // flat; an N+1 over the form relation would exceed this
});

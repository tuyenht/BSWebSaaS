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

beforeEach(function (): void {
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

it('lets a member with forms.view open the inbox and see its leads', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Alpha Corp']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee(__('forms.inbox_title'))
        ->assertSee('Alpha Corp');
});

it('forbids a member without forms.view', function (): void {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/forms')->assertForbidden();
});

it('isolates leads per site (RLS) — site A inbox never shows site B leads', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Alpha Corp']);
    seedLead(2, ['name' => 'Bravo Ltd']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee('Alpha Corp')
        ->assertDontSee('Bravo Ltd');
});

it('filters the inbox by status', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Fresh Lead', 'status' => 'new']);
    seedLead(1, ['name' => 'Junk Lead', 'status' => 'spam', 'is_spam' => true]);

    $this->actingAs($viewer)->get('http://a.test/admin/forms?status=spam')
        ->assertOk()
        ->assertSee('Junk Lead')
        ->assertDontSee('Fresh Lead');
});

it('auto-marks a new lead read when a manager opens it', function (): void {
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($manager, 'admin', ['forms.view', 'forms.manage']));
    $lead = seedLead(1, ['status' => 'new']);

    $this->actingAs($manager)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk();

    $status = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->find($lead->id)?->status);
    expect($status)->toBe('read');
});

it('does not mutate a new lead when a view-only member opens it', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $lead = seedLead(1, ['status' => 'new']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk();

    $status = app(Tenancy::class)->enter(1, fn () => FormSubmission::query()->find($lead->id)?->status);
    expect($status)->toBe('new');
});

it('lets a manager change a lead status', function (): void {
    $manager = User::factory()->create();
    $lead = seedLead(1, ['status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        grantRole($manager, 'admin', ['forms.view', 'forms.manage']);

        Livewire::actingAs($manager->fresh())
            ->test(Detail::class, ['ulid' => $lead->ulid])
            ->call('setStatus', 'archived');

        expect(FormSubmission::query()->find($lead->id)?->status)->toBe('archived');
    });
});

it('forbids a view-only member from changing a lead status', function (): void {
    $viewer = User::factory()->create();
    $lead = seedLead(1, ['status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($viewer, $lead): void {
        grantRole($viewer, 'editor', ['forms.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Detail::class, ['ulid' => $lead->ulid])
            ->call('setStatus', 'spam')
            ->assertForbidden();

        expect(FormSubmission::query()->find($lead->id)?->status)->toBe('new');
    });
});

it('shows the inbox received time in the site zone, not UTC', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    seedLead(1, ['name' => 'Zone Co', 'created_at' => '2026-07-10 07:30:00']);

    // Default site zone is Asia/Ho_Chi_Minh (UTC+7): 07:30 UTC stored -> 14:30 local shown.
    $this->actingAs($viewer)->get('http://a.test/admin/forms')
        ->assertOk()
        ->assertSee('10/07/2026 14:30')
        ->assertDontSee('10/07/2026 07:30');
});

it('shows a lead detail time in the site zone, not UTC', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $lead = seedLead(1, ['status' => 'read', 'created_at' => '2026-07-10 07:30:00']);

    $this->actingAs($viewer)->get('http://a.test/admin/forms/'.$lead->ulid)
        ->assertOk()
        ->assertSee('10/07/2026 14:30')
        ->assertDontSee('10/07/2026 07:30');
});

it('lists leads on a flat query budget (no N+1 across rows)', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer): void {
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

    // 18 flat + 1 one-time SiteTime zone lookup (site_settings, memoized for the request) = 19;
    // an N+1 over the form relation or a per-row zone lookup would exceed this.
    expect($count)->toBeLessThanOrEqual(19);
});

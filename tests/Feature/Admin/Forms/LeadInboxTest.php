<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
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
    // both websites' plans include the contacts module (a test that needs it off takes it away)
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
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
        ->assertSee(__('leads.title'))
        ->assertSee('Alpha Corp');
});

it('shows a member without forms.view the no-permission card and no lead', function (): void {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));
    $lead = seedLead(1, ['name' => 'Alpha Corp', 'phone' => '0912345678']);

    foreach (['http://a.test/admin/forms', 'http://a.test/admin/forms/'.$lead->ulid] as $url) {
        $this->actingAs($author)->get($url)
            ->assertOk()
            ->assertSee('data-state="noperm"', false)
            ->assertSee(__('leads.noperm_title'))
            ->assertDontSee('Alpha Corp')
            ->assertDontSee('0912345678');
    }
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
            ->test(Inbox::class, ['ulid' => $lead->ulid])
            ->call('setStatus', $lead->ulid, 'archived');

        expect(FormSubmission::query()->find($lead->id)?->status)->toBe('archived');
    });
});

it('forbids a view-only member from changing a lead status', function (): void {
    $viewer = User::factory()->create();
    $lead = seedLead(1, ['status' => 'new']);

    app(Tenancy::class)->enter(1, function () use ($viewer, $lead): void {
        grantRole($viewer, 'editor', ['forms.view']);

        Livewire::actingAs($viewer->fresh())
            ->test(Inbox::class, ['ulid' => $lead->ulid])
            ->call('setStatus', $lead->ulid, 'spam')
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

it('lists leads on a flat query budget (no N+1 across rows or forms)', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'editor', ['forms.view']));
    $seed = function (string $key, int $n): void {
        app(Tenancy::class)->enter(1, function () use ($key, $n): void {
            $form = Form::query()->firstOrCreate(['key' => $key], ['name' => ['vi' => 'Biểu mẫu '.$key]]);
            foreach (range(1, $n) as $i) {
                FormSubmission::query()->create(['form_id' => $form->id, 'data' => ['message' => "Nội dung {$i}"], 'name' => "Khách {$key} {$i}", 'status' => 'new']);
            }
        });
    };
    $seed('contact', 5);
    // a first visit fills the request-wide lookups (zone, plan, the member's roles) the way a warm worker has them
    $cold = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms')->assertOk());
    $few = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms')->assertOk());
    $seed('quote', 12);
    $many = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/forms')->assertOk());

    // the page, the shell and the list: forms + their fields, the group counts, the form and time
    // counts, the rows and the open lead — the same with 5 leads on one form as
    // with 17 on two; a query per row or per form would grow it
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($cold)->toBeLessThanOrEqual(23)
        ->and($many)->toBe($few)->toBeLessThanOrEqual(14);
});

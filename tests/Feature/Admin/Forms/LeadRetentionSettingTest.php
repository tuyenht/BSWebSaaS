<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadRetention;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Thời hạn lưu giữ liên hệ» in the ⋮ of «Khách liên hệ»: a member who manages leads picks 12, 24
 * or 36 months or «Không tự xóa» (24 when never set). A shorter period is stored with the one before
 * and when it changed (the purge waits 7 days); a longer one applies at once. Each change writes one
 * audit row with the months only. A member who only reads leads sees nothing to change; support
 * staff signed in for a member never shorten it; another website's setting is never touched.
 */

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-12-10 08:00:00', 'UTC'));
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** A member of $site holding exactly $permissions. @param list<string> $permissions */
function lrsMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'retention-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

function lrsLead(int $site): FormSubmission
{
    return app(Tenancy::class)->enter($site, function (): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(['form_id' => $form->id, 'name' => 'Nguyễn Thị Hoa', 'phone' => '0912 345 678', 'status' => 'read', 'data' => ['name' => 'Nguyễn Thị Hoa']]);
    });
}

/** @return array<string, mixed>|null the stored value of $site, read past RLS */
function lrsStored(int $site): ?array
{
    $value = DB::connection('pgsql_admin')->table('site_settings')->where('site_id', $site)->where('group_name', LeadRetention::GROUP)->where('key', LeadRetention::KEY)->value('value');

    if ($value === null) {
        return null;
    }
    $stored = json_decode((string) $value, true);
    // jsonb keeps its own key order: compared in the order the class writes them
    expect(array_keys($stored))->toEqualCanonicalizing(['months', 'was', 'shortened_at']);

    return ['months' => $stored['months'], 'was' => $stored['was'], 'shortened_at' => $stored['shortened_at']];
}

/** @return list<array<string, mixed>> */
function lrsAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.leads.retention_changed')->orderBy('id')->get()
        ->map(function ($row): array {
            $meta = json_decode((string) $row->meta, true);
            ksort($meta);

            return ['site' => (int) $row->site_id, 'actor' => (int) $row->actor_id, 'meta' => $meta];
        })->all();
}

/** Sign $member in with support staff acting for them. */
function lrsSupport(User $member): void
{
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($member);
    app(SupportSession::class)->start($member, $member, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });
}

it('opens on 24 months when never set, and a shorter pick is saved with the wait and audited without the visitors', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);
    lrsLead(1);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->assertSee(__('leads.ret_title'))
            ->assertSeeHtml('wire:click="openRetention"')
            ->call('openRetention')
            ->assertSet('retentionOpen', true)
            ->assertSet('retentionPick', '24')
            ->assertSee(__('leads.ret_opt_24'))
            ->assertSee(__('leads.ret_spam'))
            ->set('retentionPick', '12')
            ->call('saveRetention')
            ->assertSet('retentionOpen', false)
            ->assertSet('retentionError', '')
            ->assertSet('toastMsg', __('leads.ret_saved', ['months' => 12]));
    });

    expect(lrsStored(1))->toBe(['months' => 12, 'was' => 24, 'shortened_at' => now()->utc()->toIso8601String()])
        ->and(lrsStored(2))->toBeNull();
    $audits = lrsAudits();
    expect($audits)->toBe([['site' => 1, 'actor' => $manager->id, 'meta' => ['from' => 24, 'to' => 12, 'waits' => true]]])
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->get()->toJson(JSON_UNESCAPED_UNICODE))->not->toContain('Nguyễn', '0912');
});

it('applies a longer period, or «Không tự xóa», at once', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        SiteSetting::query()->create(['group_name' => LeadRetention::GROUP, 'key' => LeadRetention::KEY, 'value' => ['months' => 12, 'was' => 24, 'shortened_at' => now()->subDay()->utc()->toIso8601String()]]);
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('openRetention')
            ->assertSet('retentionPick', '12')
            ->set('retentionPick', '36')
            ->call('saveRetention')
            ->assertSet('toastMsg', __('leads.ret_saved', ['months' => 36]));
    });
    expect(lrsStored(1))->toBe(['months' => 36, 'was' => null, 'shortened_at' => null]);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('openRetention')->assertSet('retentionPick', '36')
            ->set('retentionPick', 'none')->call('saveRetention')
            ->assertSet('toastMsg', __('leads.ret_saved_none'));
    });

    expect(lrsStored(1))->toBe(['months' => null, 'was' => null, 'shortened_at' => null])
        ->and(array_column(lrsAudits(), 'meta'))->toBe([['from' => 12, 'to' => 36, 'waits' => false], ['from' => 36, 'to' => null, 'waits' => false]]);
});

it('shows the law warning with «Không tự xóa» picked', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        SiteSetting::query()->create(['group_name' => LeadRetention::GROUP, 'key' => LeadRetention::KEY, 'value' => ['months' => null, 'was' => null, 'shortened_at' => null]]);
        $html = Livewire::actingAs($manager)->test(Inbox::class)->call('openRetention')->assertSet('retentionPick', 'none')->html();
        expect($html)->toContain(e(__('leads.ret_none_warn_1')), 'x-show="$wire.retentionPick === \'none\'"')
            ->and($html)->not->toMatch('/x-show="\$wire\.retentionPick === \'none\'"\s+style="display: none;"/');
    });
});

it('saves nothing and audits nothing when the period stays as it is', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('openRetention')->call('saveRetention')
            ->assertSet('retentionOpen', false)
            ->assertSet('toastMsg', __('leads.ret_saved', ['months' => 24]));
    });

    expect(lrsStored(1))->toBeNull()->and(lrsAudits())->toBe([]);
});

it('refuses a period that is not one of the choices', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('openRetention')->set('retentionPick', '6')->call('saveRetention')
            ->assertSet('retentionOpen', true)
            ->assertSet('retentionError', __('leads.ret_invalid'))
            ->assertSee(__('leads.ret_invalid'));
    });

    expect(lrsStored(1))->toBeNull()->and(lrsAudits())->toBe([]);
});

it('gives a member who only reads leads nothing to change', function (): void {
    $viewer = lrsMember(['forms.view']);
    lrsLead(1);

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        Livewire::actingAs($viewer)->test(Inbox::class)
            ->assertSee('Nguyễn Thị Hoa')
            ->assertDontSeeHtml('openRetention')
            ->assertDontSee(__('leads.ret_title'))
            ->call('openRetention')
            ->assertForbidden();
        Livewire::actingAs($viewer)->test(Inbox::class)
            ->set('retentionPick', '12')
            ->call('saveRetention')
            ->assertForbidden();
    });

    expect(lrsStored(1))->toBeNull()->and(lrsAudits())->toBe([]);
});

it('never lets support staff signed in for a member shorten the period, and lets them lengthen it', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);
    lrsSupport($manager);

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class)
            ->assertSeeHtml('wire:click="openRetention"')
            ->call('openRetention')
            ->set('retentionPick', '12')
            ->call('saveRetention')
            ->assertSet('retentionOpen', true)
            ->assertSet('retentionError', __('leads.ret_support'))
            ->assertSee(__('leads.ret_support'));
        expect(LeadRetention::current()['months'])->toBe(24);

        $page->set('retentionPick', '36')->call('saveRetention')
            ->assertSet('retentionOpen', false)
            ->assertSet('retentionError', '');
    });

    expect(lrsStored(1))->toBe(['months' => 36, 'was' => null, 'shortened_at' => null])
        ->and(array_column(lrsAudits(), 'meta'))->toBe([['from' => 24, 'to' => 36, 'waits' => false]]);
});

it('reads and writes only the current website\'s setting', function (): void {
    $manager = lrsMember(['forms.view', 'forms.manage']);
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->create(['group_name' => LeadRetention::GROUP, 'key' => LeadRetention::KEY, 'value' => ['months' => 36, 'was' => null, 'shortened_at' => null]]));

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        expect(LeadRetention::current()['months'])->toBe(24);
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('openRetention')->assertSet('retentionPick', '24')
            ->set('retentionPick', 'none')->call('saveRetention');
    });

    expect(lrsStored(1))->toBe(['months' => null, 'was' => null, 'shortened_at' => null])
        ->and(lrsStored(2))->toBe(['months' => 36, 'was' => null, 'shortened_at' => null])
        ->and(array_column(lrsAudits(), 'site'))->toBe([1]);
});

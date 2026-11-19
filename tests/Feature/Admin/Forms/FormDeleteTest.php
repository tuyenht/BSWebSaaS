<?php

use App\Modules\Admin\Livewire\Forms\FormList;
use App\Modules\Forms\Actions\DeleteForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * «Xóa» on the «Biểu mẫu» list: a form goes for good with its fields and every lead it took (no
 * Trash), after a question that names the leads and offers «Tắt biểu mẫu» instead. FormPolicy
 * decides who may (forms.manage, never the system form, also not for a platform operator); one audit
 * row names the form and the count, never what a lead said; the bell's notices of those leads go
 * too; another site's form is never reached.
 */

beforeEach(function (): void {
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

/** A form of $site with two fields and $leads leads (+ $spam spam ones). */
function deletableForm(int $site, string $key, string $name, int $leads = 0, int $spam = 0, string $status = 'active'): Form
{
    return app(Tenancy::class)->enter($site, function () use ($key, $name, $leads, $spam, $status): Form {
        $form = Form::query()->create(['key' => $key, 'name' => ['vi' => $name], 'status' => $status]);
        $form->fields()->create(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên'], 'is_required' => true, 'sort_order' => 1]);
        $form->fields()->create(['key' => 'consent', 'type' => 'checkbox', 'label' => ['vi' => 'Tôi đồng ý'], 'is_required' => true, 'sort_order' => 2]);
        for ($i = 0; $i < $leads + $spam; $i++) {
            $isSpam = $i >= $leads;
            FormSubmission::query()->create(['form_id' => $form->id, 'data' => ['message' => 'Xin báo giá '.$i], 'name' => 'Khách '.$i, 'status' => $isSpam ? 'spam' : 'new', 'is_spam' => $isSpam]);
        }

        return $form;
    });
}

/** Audit rows of form deletes. */
function formDeleteAudits(): int
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.form.deleted')->count();
}

/** A «new lead» notice for $user about $lead, as SubmitForm leaves it (it carries the visitor's name). */
function leadNotice(int $site, User $user, FormSubmission $lead): void
{
    DB::connection('pgsql_admin')->table('site_notifications')->insert([
        'ulid' => (string) Str::ulid(), 'site_id' => $site, 'user_id' => $user->id, 'kind' => 'lead.new',
        'data' => json_encode(['submission' => $lead->ulid, 'name' => $lead->name]), 'created_at' => now(),
    ]);
}

/** @return list<string> the leads the «new lead» notices still point at, read past RLS */
function noticedLeads(): array
{
    return DB::connection('pgsql_admin')->table('site_notifications')->where('kind', 'lead.new')
        ->orderBy('id')->pluck(DB::raw("data->>'submission'"))->map(fn ($v): string => (string) $v)->all();
}

/** @return array{forms: int, fields: int, leads: int} rows left for $form, read past RLS */
function formRowsLeft(Form $form): array
{
    $admin = DB::connection('pgsql_admin');

    return [
        'forms' => $admin->table('forms')->where('id', $form->id)->count(),
        'fields' => $admin->table('form_fields')->where('form_id', $form->id)->count(),
        'leads' => $admin->table('form_submissions')->where('form_id', $form->id)->count(),
    ];
}

it('asks first, naming the leads that go too and offering to switch the form off instead', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 3, spam: 1);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('askDelete', $form->ulid)
            ->assertSet('confirmOpen', true)
            ->assertSet('deleting', ['ulid' => $form->ulid, 'name' => 'Yêu cầu báo giá', 'leads' => 3, 'active' => true])
            ->assertSee(__('form_builder.del_title_leads', ['name' => 'Yêu cầu báo giá', 'leads' => trans_choice('form_builder.leads_n', 3, ['n' => 3])]))
            ->assertSee(__('form_builder.del_ok_leads', ['leads' => trans_choice('form_builder.leads_n', 3, ['n' => 3])]))
            ->assertSee(__('form_builder.del_suggest_q'))
            ->assertSee(__('form_builder.del_off'))
            ->call('cancelDelete')
            ->assertSet('confirmOpen', false)
            ->assertSet('deleting', null);
    });

    expect(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 4]);
});

it('offers no switch-off for a form that is off or has no leads', function (string $status, int $leads): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: $leads, status: $status);

    app(Tenancy::class)->enter(1, function () use ($manager, $form, $leads): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $form->ulid)
            ->assertDontSee(__('form_builder.del_off'))
            ->assertDontSee(__('form_builder.del_suggest_q'));
        if ($leads === 0) {
            $page->assertSee(__('form_builder.del_title', ['name' => 'Yêu cầu báo giá']))
                ->assertSee(__('form_builder.del_text'));
        }
    });
})->with([
    'on, no leads' => ['active', 0],
    'off, with leads' => ['disabled', 2],
]);

it('deletes the form with its fields and leads, leaves other forms and sites alone, and audits it', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $gone = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 3, spam: 1);
    $kept = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 2);
    $otherSite = deletableForm(2, 'bao-gia', 'Báo giá B', leads: 2);
    $leadOf = fn (Form $form): FormSubmission => FormSubmission::on('pgsql_admin')->withoutGlobalScopes()->where('form_id', $form->id)->orderBy('id')->firstOrFail();
    $managerB = blockLibraryUser(2, 'admin');
    leadNotice(1, $manager, $leadOf($gone));
    leadNotice(1, $manager, $leadOf($kept));
    leadNotice(2, $managerB, $leadOf($otherSite));

    app(Tenancy::class)->enter(1, function () use ($manager, $gone): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('askDelete', $gone->ulid)
            ->call('confirmDelete')
            ->assertSet('confirmOpen', false)
            ->assertSet('toastTone', 'success')
            ->assertSet('toastMsg', __('form_builder.toast_deleted_leads', ['leads' => trans_choice('form_builder.leads_n', 3, ['n' => 3])]))
            ->assertDontSee('Yêu cầu báo giá')
            ->assertSee('Đăng ký tư vấn');
    });

    expect(formRowsLeft($gone))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0])
        ->and(formRowsLeft($kept))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2])
        ->and(formRowsLeft($otherSite))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2]);

    $audit = DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.form.deleted')->orderByDesc('id')->first();
    expect(noticedLeads())->toBe([$leadOf($kept)->ulid, $leadOf($otherSite)->ulid]);

    expect(formDeleteAudits())->toBe(1)
        ->and((int) $audit?->site_id)->toBe(1)
        ->and((int) $audit?->actor_id)->toBe($manager->id)
        ->and((int) $audit?->auditable_id)->toBe($gone->id)
        ->and(json_decode((string) $audit?->meta, true))->toEqual(['key' => 'bao-gia', 'leads' => 3, 'spam' => 1])
        ->and((string) $audit?->meta)->not->toContain('Khách');
});

it('switches the form off instead, keeping its leads, with undo', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('askDelete', $form->ulid)
            ->call('switchOffInstead')
            ->assertSet('confirmOpen', false)
            ->assertSet('deleting', null)
            ->assertSet('undoOp', ['ulid' => $form->ulid, 'active' => true]);
        expect(Form::query()->find($form->id)?->status)->toBe('disabled');
    });

    expect(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2]);
});

it('never deletes another site\'s form', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $other = deletableForm(2, 'bao-gia', 'Báo giá B', leads: 2);

    app(Tenancy::class)->enter(1, function () use ($manager, $other): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('askDelete', $other->ulid)
            ->assertSet('confirmOpen', false)
            ->assertSet('deleting', null)
            ->assertSet('toastTone', 'error')
            ->assertSet('toastMsg', __('form_builder.toast_gone'));
    });

    // a manager of site B, on site A's screen, reaches none of site B's forms either
    $managerB = blockLibraryUser(2, 'admin');
    app(Tenancy::class)->enter(1, function () use ($managerB, $other): void {
        Livewire::actingAs($managerB)->test(FormList::class)->call('askDelete', $other->ulid)->assertSet('deleting', null);
    });

    expect(formRowsLeft($other))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2])
        ->and(formDeleteAudits())->toBe(0);
});

it('lets only the roles holding forms.manage delete a form', function (string $role, bool $may): void {
    $member = blockLibraryUser(1, $role);
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1);

    app(Tenancy::class)->enter(1, function () use ($member, $form, $may): void {
        $page = Livewire::actingAs($member)->test(FormList::class)->call('askDelete', $form->ulid);
        $page->assertSet('confirmOpen', $may)->assertSet('toastTone', $may ? 'success' : 'error');
        if ($may) {
            $page->call('confirmDelete')->assertSet('toastTone', 'success');
        }
    });

    expect(formRowsLeft($form)['forms'])->toBe($may ? 0 : 1);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'editor' => ['editor', true],
    'author' => ['author', false],
    'contributor' => ['contributor', false],
    'viewer' => ['viewer', false],
]);

it('never deletes the system contact form, and gives it no «Xóa»', function (): void {
    $manager = blockLibraryUser(1, 'owner');
    $system = deletableForm(1, Form::SYSTEM_KEY, 'Liên hệ', leads: 1);

    $this->actingAs($manager)->get('http://a.test/admin/form-builder')->assertOk()
        ->assertSee('Liên hệ')->assertDontSee('askDelete', false);

    app(Tenancy::class)->enter(1, function () use ($manager, $system): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('askDelete', $system->ulid)
            ->assertSet('confirmOpen', false)
            ->assertSet('toastTone', 'error');
    });

    expect(formRowsLeft($system))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1]);
});

it('never deletes the system contact form for a platform operator either, who passes every policy', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    $operator = $operator->fresh();
    $system = deletableForm(1, Form::SYSTEM_KEY, 'Liên hệ', leads: 1);

    app(Tenancy::class)->enter(1, function () use ($operator, $system): void {
        expect(Gate::forUser($operator)->allows('delete', $system))->toBeTrue();   // why the screen and the action refuse it themselves
        Livewire::actingAs($operator)->test(FormList::class)
            ->call('askDelete', $system->ulid)
            ->assertSet('confirmOpen', false)
            ->assertSet('toastMsg', __('form_builder.toast_cannot'));

        expect(fn () => app(DeleteForm::class)->handle($system))->toThrow(AuthorizationException::class);
    });

    expect(formRowsLeft($system))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1])
        ->and(formDeleteAudits())->toBe(0);
});

it('asks again, deleting nothing, when leads came in after the question', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $form->ulid)->assertSet('deleting.leads', 1);
        FormSubmission::query()->create(['form_id' => $form->id, 'data' => [], 'name' => 'Khách mới', 'status' => 'new', 'is_spam' => false]);
        $page->call('confirmDelete')
            ->assertSet('confirmOpen', true)
            ->assertSet('deleting.leads', 2)
            ->assertSet('toastMsg', __('form_builder.toast_more_leads'))
            ->assertSee(__('form_builder.del_ok_leads', ['leads' => trans_choice('form_builder.leads_n', 2, ['n' => 2])]));
        expect(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2]);

        $page->call('confirmDelete')->assertSet('confirmOpen', false)->assertSet('toastTone', 'success');
    });

    expect(formRowsLeft($form)['forms'])->toBe(0)
        ->and(formDeleteAudits())->toBe(1);
});

it('says so, and deletes nothing more, when the form went between the question and the answer', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1);
    $kept = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 1);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $form->ulid)->assertSet('confirmOpen', true);
        DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->delete();
        $page->call('confirmDelete')->assertSet('confirmOpen', false)
            ->assertSet('toastTone', 'error')
            ->assertSet('toastMsg', __('form_builder.toast_gone'));
    });

    expect(formRowsLeft($kept))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1])
        ->and(formDeleteAudits())->toBe(0);
});

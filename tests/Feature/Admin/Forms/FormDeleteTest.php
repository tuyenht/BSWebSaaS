<?php

use App\Modules\Admin\Livewire\Forms\FormList;
use App\Modules\Forms\Actions\DeleteForm;
use App\Modules\Forms\Actions\SetFormStatus;
use App\Modules\Forms\Jobs\DeleteLargeForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * «Xóa» on the «Biểu mẫu» list: a form goes for good with its fields and every lead it took (no
 * Trash), after a question that names the leads and offers «Tắt biểu mẫu» instead. FormPolicy
 * decides who may (forms.manage, never the system form, also not for a platform operator); one audit
 * row names the form and the count, never what a lead said; the bell's notices of those leads go
 * too; another site's form is never reached; support staff signed in for a member never delete one.
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

/** The form's claim as a run of its delete holds it: its updated_at as Postgres gives it back. */
function formClaim(Form $form): string
{
    return (string) DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->value('updated_at');
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
        ->orderBy('id')->selectRaw("data->>'submission' as lead")->pluck('lead')->map(fn ($v): string => (string) $v)->all();
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

    // and from the other side: site B's manager, on site B's screen, never reaches a form of site A
    $mine = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 1);
    $managerB = blockLibraryUser(2, 'admin');
    app(Tenancy::class)->enter(2, function () use ($managerB, $mine): void {
        Livewire::actingAs($managerB)->test(FormList::class)
            ->call('askDelete', $mine->ulid)
            ->assertSet('deleting', null)
            ->assertSet('toastMsg', __('form_builder.toast_gone'))
            ->set('confirmOpen', true)   // a client that opens the dialog itself has nothing to confirm
            ->call('confirmDelete')
            ->assertSet('confirmOpen', false);
    });

    expect(formRowsLeft($other))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2])
        ->and(formRowsLeft($mine))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1])
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
        // read in the same transaction: the new lead is not committed yet
        expect(Form::query()->whereKey($form->id)->exists())->toBeTrue()
            ->and(FormSubmission::query()->where('form_id', $form->id)->count())->toBe(2);

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

    // the action itself, reached after the form went: nothing done, nothing audited
    $again = app(Tenancy::class)->enter(1, fn (): ?array => app(DeleteForm::class)->handle($form));

    expect($again)->toBeNull()
        ->and(formRowsLeft($kept))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1])
        ->and(formDeleteAudits())->toBe(0);
});

it('deletes at once when "more than 10,000" was shown, however many came in since', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá');
    $over = FormList::LEAD_CAP + 1;   // what the list shows for any count above the cap
    $real = FormList::LEAD_CAP + 3;
    DB::connection('pgsql_admin')->statement(
        "insert into form_submissions (ulid, site_id, form_id, data, name, status, is_spam, created_at, updated_at)
         select upper(substr(md5(g::text || ':' || ?), 1, 26)), 1, ?, '{}', 'Khách', 'new', false, now(), now() from generate_series(1, ?) g",
        [$form->id, $form->id, $real],
    );

    app(Tenancy::class)->enter(1, function () use ($manager, $form, $over, $real): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $form->ulid)->assertSet('deleting.leads', $over);
        FormSubmission::query()->create(['form_id' => $form->id, 'data' => [], 'name' => 'Khách mới', 'status' => 'new', 'is_spam' => false]);
        $page->call('confirmDelete')
            ->assertSet('confirmOpen', false)
            ->assertSet('toastMsg', __('form_builder.toast_deleted_leads', ['leads' => trans_choice('form_builder.leads_n', $real + 1, ['n' => Number::format($real + 1, locale: app()->getLocale())])]));
    });

    expect(formRowsLeft($form))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0]);
});

it('asks again with "more than 10,000" when leads came in past the cap after a smaller count', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $form->ulid)->assertSet('deleting.leads', 1);
        DB::statement(
            "insert into form_submissions (ulid, site_id, form_id, data, name, status, is_spam, created_at, updated_at)
             select upper(substr(md5(g::text || ':x:' || ?), 1, 26)), 1, ?, '{}', 'Khách', 'new', false, now(), now() from generate_series(1, ?) g",
            [$form->id, $form->id, FormList::LEAD_CAP + 5],
        );
        $page->call('confirmDelete')->assertSet('confirmOpen', true)->assertSet('deleting.leads', FormList::LEAD_CAP + 1);
    });

    expect(formRowsLeft($form)['forms'])->toBe(1);
});

it('switches a form with more leads than one request deletes to deleting, hides it, and queues its batches', function (): void {
    Queue::fake();
    config(['forms.delete_inline_max_leads' => 2]);
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2, spam: 1); // spam counts too: 3 leads > 2
    $kept = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 1);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('askDelete', $form->ulid)
            ->assertSet('deleting.leads', 2)
            ->call('confirmDelete')
            ->assertSet('confirmOpen', false)
            ->assertSet('toastTone', 'success')
            ->assertSet('toastMsg', __('form_builder.toast_deleting', ['leads' => trans_choice('form_builder.leads_n', 2, ['n' => 2])]))
            ->assertDontSee('Yêu cầu báo giá')
            ->assertSee('Đăng ký tư vấn');

        // it reads as gone everywhere: the list, the editor, a second «Xóa»
        expect(Form::query()->whereKey($form->id)->exists())->toBeFalse()
            ->and(app(DeleteForm::class)->handle($form))->toBeNull();
    });

    // nothing was deleted inside the request: the job does it
    // the run holds the form by the mark queued with it
    Queue::assertPushed(DeleteLargeForm::class, fn (DeleteLargeForm $job): bool => $job->siteId === 1 && $job->formId === $form->id && $job->afterAt === null && $job->claim === formClaim($form));
    Queue::assertPushed(DeleteLargeForm::class, 1);
    expect(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 3])
        ->and(DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->value('status'))->toBe(Form::DELETING)
        ->and(formRowsLeft($kept))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1]);

    // audited at once, with the count the member agreed to
    $audit = DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.form.deleted')->orderByDesc('id')->first();
    expect(formDeleteAudits())->toBe(1)
        ->and(json_decode((string) $audit?->meta, true))->toEqual(['key' => 'bao-gia', 'leads' => 2, 'leads_over_cap' => false, 'in_batches' => true]);

    // exactly at the limit a form still goes inside the request
    $small = deletableForm(1, 'nho', 'Nhỏ', leads: 2);
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)->test(FormList::class)
        ->call('askDelete', $small->ulid)->call('confirmDelete')
        ->assertSet('toastMsg', __('form_builder.toast_deleted_leads', ['leads' => trans_choice('form_builder.leads_n', 2, ['n' => 2])])));
    expect(formRowsLeft($small))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0]);
    Queue::assertPushed(DeleteLargeForm::class, 1);
});

it('asks again, switching nothing, when leads came in past the shown count of a form deleted in batches', function (): void {
    Queue::fake();
    config(['forms.delete_inline_max_leads' => 1]);
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2);

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        $page = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $form->ulid)->assertSet('deleting.leads', 2);
        FormSubmission::query()->create(['form_id' => $form->id, 'data' => [], 'name' => 'Khách mới', 'status' => 'new', 'is_spam' => false]);
        $page->call('confirmDelete')->assertSet('confirmOpen', true)->assertSet('deleting.leads', 3);
        expect(Form::query()->find($form->id)?->status)->toBe('active');
    });

    Queue::assertNotPushed(DeleteLargeForm::class);
    expect(formDeleteAudits())->toBe(0);
});

it('refuses a visitor\'s lead while the form is being deleted', function (): void {
    Queue::fake();
    Notification::fake();
    Cache::flush(); // the per-IP rate limiter
    config(['forms.delete_inline_max_leads' => 1, 'services.turnstile.secret_key' => '', 'services.turnstile.site_key' => '']);
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2);
    $lead = ['name' => 'Khách lạ', 'consent' => '1'];

    // while it is on, the same lead goes through
    $this->post('http://a.test/forms/bao-gia', $lead)->assertRedirect();
    expect(formRowsLeft($form)['leads'])->toBe(3);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)->test(FormList::class)
        ->call('askDelete', $form->ulid)->call('confirmDelete'));

    $this->post('http://a.test/forms/bao-gia', $lead)->assertNotFound();
    expect(formRowsLeft($form)['leads'])->toBe(3);
});

it('never lets support staff signed in for a member delete a form and its leads, small or in batches', function (): void {
    Queue::fake();
    config(['forms.delete_inline_max_leads' => 2]);
    $manager = blockLibraryUser(1, 'admin');
    $small = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2);
    $large = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 3); // past the inline limit: would go in batches
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($manager);
    // Livewire's test requests skip the middleware: hand each one this session, as StartSession does
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $small, $large): void {
        // the question was opened before support staff signed in (or a payload replays it): the answer is refused too
        $opened = Livewire::actingAs($manager)->test(FormList::class)->call('askDelete', $large->ulid)->assertSet('confirmOpen', true);
        app(SupportSession::class)->start($manager, $manager, 1, 'Kiểm tra', now()->addMinutes(30));

        Livewire::actingAs($manager)->test(FormList::class)
            ->assertSee(__('form_builder.delete'))
            ->assertSee(__('admin.support.locked'))
            ->assertDontSee('askDelete(', false)
            ->call('askDelete', $small->ulid)->assertForbidden();
        $opened->call('confirmDelete')->assertForbidden();
        Livewire::actingAs($manager)->test(FormList::class)->call('confirmDelete')->assertForbidden();
        // the action refuses it on its own, whoever calls it
        expect(fn () => app(DeleteForm::class)->handle($small))->toThrow(AuthorizationException::class, __('admin.support.locked'))
            ->and(fn () => app(DeleteForm::class)->handle($large, 3))->toThrow(AuthorizationException::class, __('admin.support.locked'));
    });

    Queue::assertNotPushed(DeleteLargeForm::class);
    expect(formRowsLeft($small))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2])
        ->and(formRowsLeft($large))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 3])
        ->and(DB::connection('pgsql_admin')->table('forms')->where('id', $large->id)->value('status'))->toBe('active')
        ->and(formDeleteAudits())->toBe(0);

    // once the support session ends, the member deletes the form as usual
    app(SupportSession::class)->end();
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)->test(FormList::class)
        ->assertSee('askDelete(', false)
        ->call('askDelete', $small->ulid)->call('confirmDelete')
        ->assertSet('toastMsg', __('form_builder.toast_deleted_leads', ['leads' => trans_choice('form_builder.leads_n', 2, ['n' => 2])])));
    expect(formRowsLeft($small))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0])
        ->and(formDeleteAudits())->toBe(1);
});

it('deletes a large form\'s leads in batches, each in its own site transaction, with their notices, then the form', function (): void {
    config(['forms.delete_batch_leads' => 2]);
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 4, spam: 1, status: Form::DELETING);
    $kept = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 1);
    $theirs = deletableForm(2, 'bao-gia', 'Báo giá B', leads: 1);
    $leadsOf = fn (Form $f) => FormSubmission::on('pgsql_admin')->withoutGlobalScopes()->where('form_id', $f->id)->orderBy('id')->get();
    foreach ($leadsOf($form) as $lead) {
        leadNotice(1, $manager, $lead);
    }
    leadNotice(1, $manager, $leadsOf($kept)->firstOrFail());
    leadNotice(2, blockLibraryUser(2, 'admin'), $leadsOf($theirs)->firstOrFail());

    $contexts = 0;
    $deletes = [];
    DB::listen(function ($query) use (&$contexts, &$deletes): void {
        if (str_contains($query->sql, 'set_config')) {
            $contexts++;
        }
        if (str_starts_with($query->sql, 'delete from "form_submissions"')) {
            // every batch runs with this site's context set for its own transaction
            $deletes[] = DB::scalar("select current_setting('app.current_site_id', true)");
        }
    });
    $done = (new DeleteLargeForm(1, $form->id, formClaim($form)))->handle(app(Tenancy::class));

    expect($done)->toBeTrue()
        ->and($deletes)->toBe(['1', '1', '1']) // 5 leads, 2 at a time
        ->and($contexts)->toBe(5) // three batches, the empty read that ends them, the row delete
        ->and(formRowsLeft($form))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0])
        ->and(formRowsLeft($kept))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1])
        ->and(formRowsLeft($theirs))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 1])
        ->and(noticedLeads())->toBe([$leadsOf($kept)->firstOrFail()->ulid, $leadsOf($theirs)->firstOrFail()->ulid]);
});

it('never touches another site, or a form that is not being deleted, with a queued form delete', function (): void {
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 3, status: Form::DELETING);
    $live = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 2);

    // a job naming site B with site A's form finds nothing under site B's context
    expect((new DeleteLargeForm(2, $form->id, formClaim($form)))->handle(app(Tenancy::class)))->toBeTrue()
        ->and(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 3])
        // a job naming a form that is on deletes none of its leads
        ->and((new DeleteLargeForm(1, $live->id, formClaim($live)))->handle(app(Tenancy::class)))->toBeTrue()
        ->and(formRowsLeft($live))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2]);
});

it('picks up a large form delete where it stopped after a failure, and a second run finds nothing to do', function (): void {
    config(['forms.delete_batch_leads' => 2]);
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 5, status: Form::DELETING);

    $claim = formClaim($form); // a retry is the same job: it holds the same claim

    $seen = 0;
    $listening = true;
    DB::listen(function ($query) use (&$seen, &$listening): void {
        if ($listening && str_starts_with($query->sql, 'delete from "form_submissions"') && ++$seen === 2) {
            throw new RuntimeException('the worker died');
        }
    });
    expect(fn () => (new DeleteLargeForm(1, $form->id, $claim))->handle(app(Tenancy::class)))->toThrow(RuntimeException::class);
    $listening = false;
    // the first batch stayed done, the second rolled back; the form is still there, still hidden
    expect(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 3]);

    expect((new DeleteLargeForm(1, $form->id, $claim))->handle(app(Tenancy::class)))->toBeTrue()
        ->and(formRowsLeft($form))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0])
        ->and((new DeleteLargeForm(1, $form->id, $claim))->handle(app(Tenancy::class)))->toBeTrue();
});

it('switches a form whose delete ran out of tries back to off, with the leads that are left', function (): void {
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2, status: Form::DELETING);

    (new DeleteLargeForm(1, $form->id, formClaim($form)))->failed(new RuntimeException('gave up'));

    expect(DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->value('status'))->toBe('disabled')
        ->and(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 2])
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.form.delete_failed')->where('auditable_id', $form->id)->count())->toBe(1);
    app(Tenancy::class)->enter(1, fn () => expect(Form::query()->whereKey($form->id)->exists())->toBeTrue());
});

it('starts a fresh run from the cursor it was handed, ties by id, and sweeps again for leads below it', function (): void {
    config(['forms.delete_batch_leads' => 1]);
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 5, status: Form::DELETING);
    $admin = DB::connection('pgsql_admin');
    $ids = $admin->table('form_submissions')->where('form_id', $form->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    // the second and third lead share a time: only the id tells them apart
    foreach (['2026-11-01 08:00:00.100000+00', '2026-11-01 08:00:00.200000+00', '2026-11-01 08:00:00.200000+00', '2026-11-01 08:00:01+00', '2026-11-01 08:00:02+00'] as $i => $at) {
        $admin->table('form_submissions')->where('id', $ids[$i])->update(['created_at' => $at]);
    }
    // the cursor as a run hands it over: the second lead's created_at as Postgres gives it back, and its id
    $cursor = (string) $admin->table('form_submissions')->where('id', $ids[1])->value('created_at');

    $deleted = [];
    DB::listen(function ($query) use (&$deleted): void {
        if (str_starts_with($query->sql, 'delete from "form_submissions"')) {
            $deleted = [...$deleted, ...array_map('intval', array_slice($query->bindings, 1, -1))];
        }
    });
    expect((new DeleteLargeForm(1, $form->id, formClaim($form), $cursor, $ids[1]))->handle(app(Tenancy::class)))->toBeTrue()
        // after the cursor first (the third lead, same time, higher id, included); then more than one batch
        // is still there, below the cursor, so a second sweep from the start takes them
        ->and($deleted)->toBe([$ids[2], $ids[3], $ids[4], $ids[0], $ids[1]])
        ->and(formRowsLeft($form))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0]);
});

it('leaves a form being deleted as it is when a switch that loaded it before lands after', function (): void {
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2);
    $stale = app(Tenancy::class)->enter(1, fn (): Form => Form::query()->findOrFail($form->id));   // read while it was on
    DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->update(['status' => Form::DELETING]);

    expect(app(Tenancy::class)->enter(1, fn (): bool => app(SetFormStatus::class)->handle($stale, false)))->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->value('status'))->toBe(Form::DELETING)
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.form.status')->count())->toBe(0);
});

it('queues again only the deletes left without progress, with `forms:resume-deletes`', function (): void {
    Queue::fake();
    $stuck = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1, status: Form::DELETING);
    $running = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 1, status: Form::DELETING);
    $stuckB = deletableForm(2, 'tu-van', 'Tư vấn B', leads: 1, status: Form::DELETING);
    $off = deletableForm(2, 'bao-gia', 'Báo giá B', leads: 1, status: 'disabled');
    $admin = DB::connection('pgsql_admin');
    $admin->table('forms')->whereIn('id', [$stuck->id, $stuckB->id, $off->id])->update(['updated_at' => now()->subHour()]);

    $this->artisan('forms:resume-deletes')->expectsOutput('Queued again 2 form delete(s).')->assertSuccessful();

    Queue::assertPushed(DeleteLargeForm::class, 2);
    Queue::assertPushed(DeleteLargeForm::class, fn (DeleteLargeForm $job): bool => $job->siteId === 1 && $job->formId === $stuck->id && $job->claim === formClaim($stuck));
    Queue::assertPushed(DeleteLargeForm::class, fn (DeleteLargeForm $job): bool => $job->siteId === 2 && $job->formId === $stuckB->id && $job->claim === formClaim($stuckB));
    unset($running);
});

it('runs `forms:resume-deletes` every hour, never two at once', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'forms:resume-deletes'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('says the form is gone, with no «Hoàn tác», when a delete in batches lands while the switch was sent', function (): void {
    $manager = blockLibraryUser(1, 'admin');
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1);
    $switched = false;
    DB::listen(function ($query) use ($form, &$switched): void {
        // the screen has read the form; the delete commits before its switch is written
        if (! $switched && str_starts_with($query->sql, 'select * from "forms" where "ulid"')) {
            $switched = true;
            DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->update(['status' => Form::DELETING]);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $form): void {
        Livewire::actingAs($manager)->test(FormList::class)
            ->call('setActive', $form->ulid, false)
            ->assertSet('toastMsg', __('form_builder.toast_gone'))
            ->assertSet('toastTone', 'error')
            ->assertSet('undoOp', null);
    });
    expect($switched)->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->value('status'))->toBe(Form::DELETING);
});

it('queues the batches for the locked form\'s own site, whatever the site singleton says by then', function (): void {
    Queue::fake();
    config(['forms.delete_inline_max_leads' => 1]);
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 2);
    $shifted = false;
    DB::listen(function ($query) use (&$shifted): void {
        // the audit row is written; something later in the request moves the singleton
        if (! $shifted && str_starts_with($query->sql, 'insert into "audit_logs"')) {
            $shifted = true;
            app(CurrentSite::class)->setId(2);
        }
    });

    app(Tenancy::class)->enter(1, fn () => app(DeleteForm::class)->handle($form));

    expect($shifted)->toBeTrue();
    Queue::assertPushed(DeleteLargeForm::class, 1);
    Queue::assertPushed(DeleteLargeForm::class, fn (DeleteLargeForm $job): bool => $job->siteId === 1 && $job->formId === $form->id);
});

it('hands a delete over only while the run still holds it, with a fresh claim and its cursor', function (): void {
    Queue::fake();
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 1, status: Form::DELETING);
    $live = deletableForm(1, 'tu-van', 'Đăng ký tư vấn', leads: 1);
    // claimed a while ago: updated_at keeps whole seconds, and a fresh claim must differ from it
    DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->update(['updated_at' => now()->subMinute()]);
    $claim = formClaim($form);

    app(Tenancy::class)->enter(1, function () use ($form, $live, $claim): void {
        expect(DeleteLargeForm::queueRun(1, $form->id, '2026-01-01 00:00:00+00'))->toBeFalse()   // taken over since
            ->and(DeleteLargeForm::queueRun(2, $form->id, $claim))->toBeFalse()                     // another site's id
            ->and(DeleteLargeForm::queueRun(1, $live->id))->toBeFalse()                             // not being deleted
            ->and(DeleteLargeForm::queueRun(1, $form->id, $claim, null, '2026-11-01 08:00:00+00', 7))->toBeTrue()
            ->and(DeleteLargeForm::queueRun(1, $form->id, $claim))->toBeFalse();                    // the claim moved on
    });

    Queue::assertPushed(DeleteLargeForm::class, 1);
    Queue::assertPushed(DeleteLargeForm::class, fn (DeleteLargeForm $job): bool => $job->claim === formClaim($form)
        && $job->claim !== $claim && $job->afterAt === '2026-11-01 08:00:00+00' && $job->afterId === 7);
});

it('leaves a delete taken over by forms:resume-deletes to the new run: the old one deletes nothing more, and its failure switches nothing back', function (): void {
    Queue::fake();
    $form = deletableForm(1, 'bao-gia', 'Yêu cầu báo giá', leads: 3, status: Form::DELETING);
    // a run queued an hour ago, still waiting behind a long queue
    DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->update(['updated_at' => now()->subHour()]);
    $old = new DeleteLargeForm(1, $form->id, formClaim($form));

    $this->artisan('forms:resume-deletes')->expectsOutput('Queued again 1 form delete(s).')->assertSuccessful();
    // a second sweep finds the new claim fresh and queues nothing
    $this->artisan('forms:resume-deletes')->expectsOutput('Queued again 0 form delete(s).')->assertSuccessful();
    Queue::assertPushed(DeleteLargeForm::class, 1);
    $new = Queue::pushed(DeleteLargeForm::class)->first();
    expect($new)->toBeInstanceOf(DeleteLargeForm::class)->and($new->claim)->not->toBe($old->claim);

    // the old run, when a worker finally starts it, does nothing; out of tries, it switches nothing back
    expect($old->handle(app(Tenancy::class)))->toBeTrue()
        ->and(formRowsLeft($form))->toBe(['forms' => 1, 'fields' => 2, 'leads' => 3]);
    $old->failed(new RuntimeException('gave up'));
    expect(DB::connection('pgsql_admin')->table('forms')->where('id', $form->id)->value('status'))->toBe(Form::DELETING)
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.form.delete_failed')->count())->toBe(0);

    // the new one finishes the delete
    expect($new->handle(app(Tenancy::class)))->toBeTrue()
        ->and(formRowsLeft($form))->toBe(['forms' => 0, 'fields' => 0, 'leads' => 0]);
});

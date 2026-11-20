<?php

use App\Modules\Admin\Livewire\Forms\Inbox;
use App\Modules\Forms\Actions\EraseLead;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * «Xóa theo yêu cầu của khách» in «Khách liên hệ»: a member who manages leads (forms.manage) erases
 * one lead for good after a question that names the visitor and says it cannot be undone. With it
 * go the bell's «new lead» notices of it (their data and search text carry the visitor's name);
 * one audit row says a lead was erased, never who the visitor was. A member who only reads leads
 * never erases one, support staff signed in for a member never do, and another website's lead is
 * never reached.
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

/** A lead of $site's contact form, sent now. */
function eraseLead(int $site, string $name, ?string $phone = null, ?string $email = null): FormSubmission
{
    return app(Tenancy::class)->enter($site, function () use ($name, $phone, $email): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create([
            'form_id' => $form->id, 'name' => $name, 'phone' => $phone, 'email' => $email, 'status' => 'new',
            'data' => ['name' => $name, 'phone' => $phone, 'message' => 'Xin báo giá trục thép'],
        ]);
    });
}

/** A member of $site holding exactly $permissions. */
function eraseMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'erase-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** A «new lead» notice for $user about $lead, as SubmitForm leaves it, with its search text. */
function eraseNotice(int $site, User $user, FormSubmission $lead): void
{
    DB::connection('pgsql_admin')->table('site_notifications')->insert([
        'ulid' => (string) Str::ulid(), 'site_id' => $site, 'user_id' => $user->id, 'kind' => 'lead.new',
        'data' => json_encode(['submission' => $lead->ulid, 'name' => $lead->name]),
        'search_text' => mb_strtolower((string) Str::ascii((string) $lead->name)), 'created_at' => now(),
    ]);
}

function eraseNoticesOf(FormSubmission $lead): int
{
    return DB::connection('pgsql_admin')->table('site_notifications')->whereRaw("data->>'submission' = ?", [$lead->ulid])->count();
}

function eraseExists(FormSubmission $lead): bool
{
    return DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->exists();
}

it('asks first, naming the visitor and saying it cannot be undone, then erases the lead with its notices and one audit row', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $colleague = eraseMember(['forms.view']);
    $lead = eraseLead(1, 'Nguyễn Thị Hoa', '0912 345 678', 'hoa@example.test');
    $other = eraseLead(1, 'Trần Văn Bình', '0987 654 321');
    foreach ([$manager, $colleague] as $user) {
        eraseNotice(1, $user, $lead);
        eraseNotice(1, $user, $other);
    }

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->test(Inbox::class, ['ulid' => $lead->ulid])
            ->assertSee(__('leads.erase'))
            ->call('askErase', $lead->ulid)
            ->assertSet('eraseOpen', true)
            ->assertSee(__('leads.erase_title', ['name' => 'Nguyễn Thị Hoa']))
            ->assertSee(__('leads.erase_text_2'))
            ->assertSee(__('leads.erase_ok'))
            ->call('confirmErase')
            ->assertSet('eraseOpen', false)
            ->assertSet('lead', '')
            ->assertSet('toastMsg', __('leads.toast_erased'))
            ->assertSet('undoOp', null)
            ->assertDontSee('Nguyễn Thị Hoa');
    });

    expect(eraseExists($lead))->toBeFalse()
        ->and(eraseNoticesOf($lead))->toBe(0)
        ->and(eraseExists($other))->toBeTrue()
        ->and(eraseNoticesOf($other))->toBe(2);

    $audits = DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->get();
    expect($audits)->toHaveCount(1);
    $row = json_encode($audits->first(), JSON_UNESCAPED_UNICODE);
    expect($audits->first()->site_id)->toBe(1)
        ->and(json_decode((string) $audits->first()->meta, true))->toBe(['form' => 'contact'])
        ->and($row)->not->toContain('Nguyễn', '0912', 'hoa@example.test', 'báo giá');
});

it('no longer finds an erased lead by its name, with or without the diacritics, nor by its phone', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $lead = eraseLead(1, 'Đặng Quốc Việt', '0903 112 233');
    eraseLead(1, 'Lê Minh');

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        $page = Livewire::actingAs($manager)->test(Inbox::class);
        foreach (['Đặng Quốc', 'dang quoc', 'DANG QUOC VIET', '0903112'] as $q) {
            $page->set('search', $q)->assertSee('Đặng Quốc Việt')->assertDontSee('Lê Minh');
        }
        $page->set('search', '')->call('askErase', $lead->ulid)->call('confirmErase');
        foreach (['Đặng Quốc', 'dang quoc', '0903112'] as $q) {
            $page->set('search', $q)->assertDontSee('Đặng Quốc Việt')->assertSee(__('leads.no_match'));
        }
    });
});

it('never lets a member who only reads leads, or one without leads at all, erase one', function (array $permissions): void {
    $member = eraseMember($permissions);
    $lead = eraseLead(1, 'Phạm Lan');

    app(Tenancy::class)->enter(1, function () use ($member, $lead): void {
        Livewire::actingAs($member)->test(Inbox::class)->call('askErase', $lead->ulid)->assertForbidden();
        Livewire::actingAs($member)->test(Inbox::class)->call('confirmErase')->assertForbidden();
    });

    expect(eraseExists($lead))->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->count())->toBe(0);
})->with([
    'reads leads only' => [['forms.view']],
    'no lead permission' => [['entries.view']],
]);

it('never reaches another website\'s lead, nor its notices', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $foreign = eraseLead(2, 'Khách site B', '0911 000 111');
    $bMember = eraseMember(['forms.view'], 2);
    eraseNotice(2, $bMember, $foreign);

    app(Tenancy::class)->enter(1, function () use ($manager, $foreign): void {
        Livewire::actingAs($manager)->test(Inbox::class)
            ->call('askErase', $foreign->ulid)
            ->assertSet('eraseOpen', false)
            ->assertSet('toastMsg', __('leads.gone'))
            ->call('confirmErase')
            ->assertSet('toastMsg', __('leads.gone'));
    });

    expect(eraseExists($foreign))->toBeTrue()
        ->and(eraseNoticesOf($foreign))->toBe(1)
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->count())->toBe(0);
});

it('takes the lead to erase only from the question, never from the page', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $lead = eraseLead(1, 'Hồ Gia');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)->test(Inbox::class)
        ->set('erasing', ['ulid' => $lead->ulid, 'name' => 'x']));
})->throws(CannotUpdateLockedPropertyException::class);

it('tells a colleague whose question was still open that the lead is already gone', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $lead = eraseLead(1, 'Bùi Khang');

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        $first = Livewire::actingAs($manager)->test(Inbox::class)->call('askErase', $lead->ulid);
        $second = Livewire::actingAs($manager)->test(Inbox::class)->call('askErase', $lead->ulid);
        $first->call('confirmErase')->assertSet('toastMsg', __('leads.toast_erased'));
        $second->call('confirmErase')->assertSet('toastMsg', __('leads.gone'));
    });

    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->count())->toBe(1);
});

it('never erases a lead of a form being deleted: it is already out of reach', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $lead = eraseLead(1, 'Đinh Tú');
    DB::connection('pgsql_admin')->table('forms')->where('id', $lead->form_id)->update(['status' => Form::DELETING]);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($manager)->test(Inbox::class)
        ->call('askErase', $lead->ulid)->assertSet('eraseOpen', false)->assertSet('toastMsg', __('leads.gone')));

    expect(eraseExists($lead))->toBeTrue();
});

it('erases on a flat query budget, however many notices the lead left', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $few = eraseLead(1, 'Khách một');
    $many = eraseLead(1, 'Khách nhiều');
    eraseLead(1, 'Khách ở lại'); // the list is never empty after either erase: the same statements draw it
    eraseNotice(1, $manager, $few);
    foreach (range(1, 6) as $i) {
        eraseNotice(1, eraseMember(['forms.view']), $many);
    }

    $measure = fn (FormSubmission $lead): int => app(Tenancy::class)->enter(1, function () use ($manager, $lead): int {
        $page = Livewire::actingAs($manager)->test(Inbox::class)->call('askErase', $lead->ulid);

        return countQueries(fn () => $page->call('confirmErase'));
    });
    $one = $measure($few);
    $six = $measure($many);

    // the request, the lead's form read and share-locked, the lead locked, its notices deleted in
    // one statement, the lead, the audit row, then the list drawn again; a query per notice would grow it
    expect($six)->toBe($one)->toBeLessThanOrEqual(40)
        ->and(eraseNoticesOf($many))->toBe(0);
});

it('erases a lead once: the second erase of the same lead finds nothing and writes nothing', function (): void {
    $this->actingAs(eraseMember(['forms.view', 'forms.manage']));
    $lead = eraseLead(1, 'Tạ Minh');

    [$first, $second] = app(Tenancy::class)->enter(1, fn (): array => [
        app(EraseLead::class)->handle($lead->ulid), app(EraseLead::class)->handle($lead->ulid),
    ]);

    expect($first)->toBeTrue()->and($second)->toBeFalse()
        ->and(eraseExists($lead))->toBeFalse()
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->count())->toBe(1);
});

it('erases a lead that has no time, with its notices', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $this->actingAs($manager);
    $lead = eraseLead(1, 'Lý Hà');
    eraseNotice(1, $manager, $lead);
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->update(['created_at' => null]);

    expect(app(Tenancy::class)->enter(1, fn (): bool => app(EraseLead::class)->handle($lead->ulid)))->toBeTrue()
        ->and(eraseExists($lead))->toBeFalse()
        ->and(eraseNoticesOf($lead))->toBe(0);
});

it('leaves a lead whose form is being deleted to that delete', function (): void {
    $this->actingAs(eraseMember(['forms.view', 'forms.manage']));
    $lead = eraseLead(1, 'Mai Phương');
    DB::connection('pgsql_admin')->table('forms')->where('id', $lead->form_id)->update(['status' => Form::DELETING]);

    expect(app(Tenancy::class)->enter(1, fn (): bool => app(EraseLead::class)->handle($lead->ulid)))->toBeFalse()
        ->and(eraseExists($lead))->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->count())->toBe(0);
});

it('never lets support staff signed in for a member erase a visitor\'s lead', function (): void {
    $manager = eraseMember(['forms.view', 'forms.manage']);
    $lead = eraseLead(1, 'Kiều Oanh');
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($manager);
    app(SupportSession::class)->start($manager, $manager, 1, 'Kiểm tra', now()->addMinutes(30));
    // Livewire's test requests skip the middleware: hand each one this session, as StartSession does
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $lead): void {
        Livewire::actingAs($manager)->test(Inbox::class, ['ulid' => $lead->ulid])
            ->assertSee(__('leads.erase'))
            ->assertSee(__('admin.support.locked'))
            ->assertDontSee('askErase(', false)
            ->call('askErase', $lead->ulid)->assertForbidden();
        Livewire::actingAs($manager)->test(Inbox::class)->call('confirmErase')->assertForbidden();
    });

    expect(eraseExists($lead))->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.erased')->count())->toBe(0);
});

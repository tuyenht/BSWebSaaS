<?php

use App\Modules\Admin\Support\Leads\LeadFileRows;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Forms\Actions\StoreLeadFile;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormField;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadAnswers;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * «Tệp đính kèm» on a lead in «Khách liên hệ»: each file with its kind and size, «Tải về» for a
 * member who may take files, a plain line for one who may not and for support staff signed in for
 * a member, and a refused file in red with why. The row shows how many came; the files are read
 * once for the open lead only.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    Storage::fake('leads');
});

/** @param list<string> $permissions */
function lifMember(array $permissions): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'lif-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/**
 * A lead of site 1 on the quote form (a drawings field taking 2 files) with $files sent to it, the
 * ones past the field's number refused the way a send refuses them.
 *
 * @param  list<UploadedFile>  $files
 */
function lifLead(string $name, array $files): FormSubmission
{
    return app(Tenancy::class)->enter(1, function () use ($name, $files): FormSubmission {
        $form = Form::query()->where('key', 'quote')->first() ?? tap(Form::query()->create(['key' => 'quote', 'name' => ['vi' => 'Báo giá'], 'status' => 'active']), function (Form $form): void {
            $form->fields()->create(['key' => 'drawing', 'type' => 'file', 'label' => ['vi' => 'Bản vẽ'], 'is_required' => false, 'sort_order' => 1,
                'options' => ['accept' => 'cad'], 'validation' => ['max_files' => 2]]);
        });
        $lead = FormSubmission::query()->create(['form_id' => $form->id, 'name' => $name, 'status' => 'read', 'data' => []]);
        foreach ($files as $i => $file) {
            $i < 2 ? app(StoreLeadFile::class)->handle($lead, 'drawing', 'cad', $file) : app(StoreLeadFile::class)->refuse($lead, 'drawing', $file);
        }

        return $lead;
    });
}

function lifStep(string $name, int $pad = 2_400_000): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "ISO-10303-21;\n".str_repeat('x', $pad));
}

function lifPdf(string $name, int $pad = 880_000): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $pad));
}

it('lists a lead\'s files with their kind and size, and «Tải về» for a member who may take them', function (): void {
    $member = lifMember(['forms.view', 'forms.files']);
    $lead = lifLead('Nguyễn Bình', [lifStep('banh-rang.step'), lifPdf('ban-ve.pdf')]);
    lifLead('Không tệp', []);
    $file = app(Tenancy::class)->enter(1, fn () => $lead->files()->orderBy('id')->firstOrFail());

    $this->actingAs($member)->get('http://a.test/admin/forms')->assertOk()
        ->assertSee(trans_choice('leads.files_n', 2, ['count' => 2]));

    $this->actingAs($member)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk()
        ->assertSee(__('leads.files_title', ['n' => 2]))
        ->assertSee('banh-rang.step')
        ->assertSee('Bản vẽ 3D (STEP) · 2,3 MB')
        ->assertSee('PDF · 859 KB')
        ->assertSee(route('admin.forms.file', $file->ulid), false)
        ->assertSee(__('leads.file_download_aria', ['name' => 'banh-rang.step']))
        ->assertDontSee(__('leads.file_no_perm'));
});

it('shows a member without forms.files the files, without «Tải về»', function (): void {
    $viewer = lifMember(['forms.view']);
    $lead = lifLead('Nguyễn Bình', [lifPdf('ban-ve.pdf')]);
    $file = app(Tenancy::class)->enter(1, fn () => $lead->files()->firstOrFail());

    $this->actingAs($viewer)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk()
        ->assertSee('ban-ve.pdf')
        ->assertSee(__('leads.file_no_perm'))
        ->assertDontSee(route('admin.forms.file', $file->ulid), false);
});

it('shows refused files in red with why, and never offers them', function (): void {
    config(['forms.file_max_mb' => 1]);
    $member = lifMember(['forms.view', 'forms.files']);
    $lead = lifLead('Nguyễn Bình', [
        lifStep('lon.step', 1_200_000),                               // over 1 MB
        UploadedFile::fake()->createWithContent('gia.step', 'MZ'),    // not the kind it says
        lifPdf('thua.pdf', 10),                                       // past the field's 2 files
    ]);
    $files = app(Tenancy::class)->enter(1, fn () => $lead->files()->orderBy('id')->get());
    expect($files->pluck('status')->all())->toBe(['rejected', 'rejected', 'rejected']);

    $page = $this->actingAs($member)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk()
        ->assertSee(__('leads.file_too_big', ['mb' => 1]))
        ->assertSee(__('leads.file_bad'))
        ->assertSee(__('leads.file_over', ['n' => 2]));
    foreach ($files as $file) {
        $page->assertDontSee(route('admin.forms.file', $file->ulid), false);
    }
});

it('shows support staff signed in for a member the files, locked', function (): void {
    Notification::fake();
    Mail::fake();
    $member = lifMember(['forms.view', 'forms.files']);
    $lead = lifLead('Nguyễn Bình', [lifPdf('ban-ve.pdf')]);
    $file = app(Tenancy::class)->enter(1, fn () => $lead->files()->firstOrFail());
    $staff = User::factory()->create(['name' => 'Staff support']);
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    $browser = 'Mozilla/5.0 (support desk)';
    $token = app(SupportSignIn::class)->issue($staff, Site::query()->findOrFail(1), $member, 'Khách nhờ xem liên hệ', 30, $browser);

    $this->withHeader('User-Agent', $browser)->get('http://a.test/admin/support/enter?token='.$token)->assertStatus(303);
    $this->assertAuthenticatedAs($member);

    $this->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk()
        ->assertSee('ban-ve.pdf')
        ->assertSee(__('admin.support.locked'))
        ->assertDontSee(route('admin.forms.file', $file->ulid), false);
});

it('opens a lead on a flat query budget, however many files it has', function (): void {
    $member = lifMember(['forms.view', 'forms.files']);
    $none = lifLead('Không tệp', []);
    $one = lifLead('Một tệp', [lifPdf('a.pdf', 10)]);
    $many = lifLead('Nhiều tệp', [lifPdf('a.pdf', 10), lifStep('b.step', 10), lifPdf('c.pdf', 10), lifPdf('d.pdf', 10)]);
    $open = fn (FormSubmission $lead): int => countQueries(fn () => $this->actingAs($member)->get('http://a.test/admin/forms/'.$lead->ulid)->assertOk());
    $open($none); // a first visit fills the request-wide lookups the way a warm worker has them

    // the files are read once, for the open lead only; a lead whose row counted none reads nothing
    expect($open($many))->toBe($open($one))
        ->and($open($none))->toBe($open($one) - 1);
});

it('writes sizes the way people read them, in the page\'s number format', function (): void {
    expect(LeadFileRows::size(10, 'vi'))->toBe('1 KB')
        ->and(LeadFileRows::size(880_640, 'vi'))->toBe('860 KB')
        ->and(LeadFileRows::size(2_516_582, 'vi'))->toBe('2,4 MB')
        ->and(LeadFileRows::size(2_516_582, 'en'))->toBe('2.4 MB')
        ->and(LeadFileRows::size(1_048_576, 'vi'))->toBe('1,0 MB');
});

it('still shows what a visitor typed in a field that has since become a file field', function (): void {
    $form = (new Form)->setRelation('fields', new Collection([
        new FormField(['key' => 'name', 'type' => 'text', 'label' => ['vi' => 'Họ tên']]),
        new FormField(['key' => 'message', 'type' => 'file', 'label' => ['vi' => 'Bản vẽ']]),
    ]));

    $answers = collect(LeadAnswers::for($form, ['name' => 'Bình', 'message' => 'Cần 40 trục inox'], 'vi'));

    expect($answers->pluck('key')->all())->toBe(['name', 'message'])
        ->and($answers->firstWhere('key', 'message')['value'] ?? null)->toBe('Cần 40 trục inox');
});

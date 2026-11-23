<?php

use App\Modules\Admin\Http\Controllers\LeadFileDownloadController;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Actions\StoreLeadFile;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * «Tải về» of a file a visitor sent with a lead. A member given forms.files gets a 302 to a link
 * signed for 60 seconds that the browser saves (application/octet-stream, attachment), after one
 * audit row naming the file's ulid and the form's key — never the file's name or the visitor. A
 * member without forms.files (the «Chỉ xem» role by default), support staff signed in for a member,
 * another website's file, a refused file and a file whose lead is gone get nothing, and nothing is
 * audited for them.
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
    Storage::fake('leads');
    // the disk binds the callback to itself: the links it signs are kept in an object both can see
    $this->signed = $signed = new ArrayObject;
    Storage::disk('leads')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expires, array $options) use ($signed): string {
        $signed[] = ['path' => $path, 'seconds' => $expires->getTimestamp() - now()->getTimestamp(), 'options' => $options];

        return 'https://bucket.r2.example.test/'.$path.'?X-Amz-Expires=60';
    });
});

/** A member of $site holding exactly $permissions. @param list<string> $permissions */
function lfdMember(array $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'lfd-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** A stored file (or a refused one, for a name the kind does not take) of a new lead of $site. */
function lfdFile(int $site = 1, string $name = 'Hồ sơ Bình.pdf', array $lead = []): FormSubmissionFile
{
    return app(Tenancy::class)->enter($site, function () use ($name, $lead): FormSubmissionFile {
        $form = Form::query()->firstOrCreate(['key' => 'tuyen-dung'], ['name' => ['vi' => 'Tuyển dụng'], 'status' => 'active']);
        $submission = FormSubmission::query()->create(array_merge(['form_id' => $form->id, 'name' => 'Trần Văn Bình', 'status' => 'new', 'data' => []], $lead));

        $file = app(StoreLeadFile::class)->handle($submission, 'cv', 'cv', UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nCV"));
        expect($file)->not->toBeNull();

        return $file;
    });
}

function lfdAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead.file_downloaded')->orderBy('id')->get()->all();
}

it('sends a member with forms.files to a 60-second link the browser saves, after one audit row', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $file = lfdFile();

    $response = $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid);

    $response->assertRedirect('https://bucket.r2.example.test/'.$file->storage_key.'?X-Amz-Expires=60');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($this->signed)->toHaveCount(1)
        ->and($this->signed[0]['path'])->toBe($file->storage_key)
        ->and($this->signed[0]['seconds'])->toBe(60)
        ->and($this->signed[0]['options']['ResponseContentType'])->toBe('application/octet-stream')
        ->and($this->signed[0]['options']['ResponseContentDisposition'])->toStartWith('attachment; filename="Ho so Binh.pdf"');

    $audits = lfdAudits();
    expect($audits)->toHaveCount(1)
        ->and($audits[0]->site_id)->toBe(1)
        ->and($audits[0]->actor_id)->toBe($member->id)
        ->and(json_decode((string) $audits[0]->meta, true))->toBe(['file' => $file->ulid, 'form' => 'tuyen-dung'])
        ->and((string) $audits[0]->meta)->not->toContain('Bình')->not->toContain('pdf');
});

it('audits every download, one row each', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $file = lfdFile();

    foreach ([1, 2, 3] as $_) {
        $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertRedirect();
    }

    expect(lfdAudits())->toHaveCount(3)->and($this->signed)->toHaveCount(3);
});

it('gives forms.files to the roles that handle leads, and not to the «Chỉ xem» role', function (): void {
    $templates = PermissionRegistry::tenantRoleTemplates('corporate');
    expect($templates['editor']['permissions'])->toContain('forms.files')
        ->and($templates['admin']['permissions'])->toContain('forms.files')
        ->and($templates['owner']['permissions'])->toBe('*')
        ->and($templates['viewer']['permissions'])->toContain('forms.view')->not->toContain('forms.files')
        ->and(PermissionRegistry::IMPLIES['forms.files'])->toBe(['forms.view']);

    $file = lfdFile();
    $viewer = lfdMember($templates['viewer']['permissions']);
    $this->actingAs($viewer)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertForbidden();
    $editor = lfdMember($templates['editor']['permissions']);
    $this->actingAs($editor)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertRedirect();
    expect(lfdAudits())->toHaveCount(1);
});

it('refuses a member without forms.files, even one who manages leads, before looking the file up', function (array $permissions): void {
    $member = lfdMember($permissions);
    $file = lfdFile();

    $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertForbidden();
    // an address that names no file answers the same: nothing tells which ones exist
    $this->actingAs($member)->get('http://a.test/admin/forms/files/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertForbidden();
    expect(lfdAudits())->toBe([])->and($this->signed)->toHaveCount(0);
})->with([
    'reads leads' => [['forms.view']],
    'manages leads' => [['forms.view', 'forms.manage']],
    'nothing' => [['entries.view']],
]);

it('never finds another website\'s file', function (): void {
    $file = lfdFile(2);
    $member = lfdMember(['forms.view', 'forms.files']);
    lfdMember(['forms.view', 'forms.files'], 2);

    $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertNotFound();
    expect(lfdAudits())->toBe([])->and($this->signed)->toHaveCount(0);
});

it('hands out no refused file and no file whose lead is gone', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $refused = lfdFile(1, 'setup.exe');
    $orphan = lfdFile(1, 'cv.pdf');
    expect($refused->status)->toBe('rejected');
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $orphan->form_submission_id)->delete();

    $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$refused->ulid)->assertNotFound();
    $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$orphan->ulid)->assertNotFound();
    expect(lfdAudits())->toBe([])->and($this->signed)->toHaveCount(0);
});

it('hands out the files of a lead in the Trash only to those who manage leads', function (): void {
    $file = lfdFile();
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $file->form_submission_id)->update(['deleted_at' => now()]);

    $this->actingAs(lfdMember(['forms.view', 'forms.files']))->get('http://a.test/admin/forms/files/'.$file->ulid)->assertForbidden();
    $this->actingAs(lfdMember(['forms.view', 'forms.manage', 'forms.files']))->get('http://a.test/admin/forms/files/'.$file->ulid)->assertRedirect();
    expect(lfdAudits())->toHaveCount(1);
});

it('hands out nothing of a form being deleted', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $file = lfdFile();
    DB::connection('pgsql_admin')->table('forms')->where('key', 'tuyen-dung')->update(['status' => Form::DELETING]);

    $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertNotFound();
    expect(lfdAudits())->toBe([]);
});

it('refuses a website whose plan has no «Khách liên hệ»', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $file = lfdFile();
    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();

    $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid)->assertForbidden();
    expect(lfdAudits())->toBe([]);
});

it('never lets support staff signed in for a member take a file', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $file = lfdFile();

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($member);
    app(SupportSession::class)->start($member, $member, 1, 'Kiểm tra', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($file): void {
        expect(fn () => app()->call(LeadFileDownloadController::class, ['ulid' => $file->ulid]))
            ->toThrow(AuthorizationException::class, __('admin.support.locked'));
    });

    expect(lfdAudits())->toBe([])->and($this->signed)->toHaveCount(0);
});

it('makes no link when the audit row cannot be written', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);
    $file = lfdFile();
    Audit::swap(new class
    {
        public function recordOrFail(): never
        {
            throw new RuntimeException('audit down');
        }

        /** @param  array<int, mixed>  $args */
        public function __call(string $method, array $args): mixed
        {
            return null;
        }
    });

    $response = $this->actingAs($member)->get('http://a.test/admin/forms/files/'.$file->ulid);

    expect($response->status())->toBe(500)->and($this->signed)->toHaveCount(0);
});

it('answers only a ulid-shaped address', function (): void {
    $member = lfdMember(['forms.view', 'forms.files']);

    $this->actingAs($member)->get('http://a.test/admin/forms/files/1')->assertNotFound();
    $this->actingAs($member)->get('http://a.test/admin/forms/files/..%2F..%2Fetc')->assertNotFound();
});

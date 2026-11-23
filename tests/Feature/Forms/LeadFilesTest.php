<?php

use App\Modules\Forms\Actions\DeleteForm;
use App\Modules\Forms\Actions\EraseLead;
use App\Modules\Forms\Actions\StoreLeadFile;
use App\Modules\Forms\Jobs\DeleteLargeForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Forms\Support\LeadRetention;
use App\Modules\Forms\Support\LeadTrash;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The files a visitor sends with a lead («Tệp đính kèm»). A kept file is streamed to the private
 * lead-files disk under `leads/{site ulid}/{file ulid}` (nothing of the visitor, no name in the key)
 * and gets a `stored` row; a refused one (blocked name, wrong type, forged bytes, too large) gets a
 * `rejected` row with no object; a spam lead keeps nothing at all. The rows are a website's own (RLS).
 * Every way a lead is deleted leaves its file rows with no lead, the objects untouched; the hourly
 * sweeper then deletes each object, then its row, website by website.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    Storage::fake('leads');
});

/** A lead of $site's form $formKey. @param array<string, mixed> $attrs */
function lfLead(int $site, array $attrs = [], string $formKey = 'contact'): FormSubmission
{
    return app(Tenancy::class)->enter($site, function () use ($attrs, $formKey): FormSubmission {
        $form = Form::query()->firstOrCreate(['key' => $formKey], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

        return FormSubmission::query()->create(array_merge([
            'form_id' => $form->id, 'name' => 'Trần Văn Bình', 'status' => 'new', 'data' => ['name' => 'Trần Văn Bình'],
        ], $attrs));
    });
}

function lfPdf(string $name = 'Hồ sơ Bình.pdf', int $pad = 64): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".str_repeat('x', $pad));
}

function lfStore(FormSubmission $lead, UploadedFile $file, string $kind = 'cv', string $field = 'cv'): ?FormSubmissionFile
{
    return app(Tenancy::class)->enter($lead->site_id, fn (): ?FormSubmissionFile => app(StoreLeadFile::class)->handle($lead, $field, $kind, $file));
}

/**
 * The fake lead-files disk with some of its answers replaced: `writeStream` / `delete` return what
 * $answers says, `put` throws (a whole-file write), and every call is noted in its `calls`.
 *
 * @param  array<string, bool>  $answers
 */
function lfDisk(array $answers): FilesystemAdapter
{
    $real = Storage::disk('leads');
    $disk = new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
    {
        /** @var array<string, bool> */
        public array $answers = [];

        /** @var list<array{0: string, 1: list<mixed>}> */
        public array $calls = [];

        public function put($path, $contents, $options = [])
        {
            throw new LogicException('a lead file is never written whole');
        }

        public function writeStream($path, $resource, array $options = [])
        {
            $this->calls[] = ['writeStream', [$path, is_resource($resource), $options]];

            return $this->answers['writeStream'] ?? parent::writeStream($path, $resource, $options);
        }

        public function delete($paths)
        {
            $this->calls[] = ['delete', [$paths]];

            return $this->answers['delete'] ?? parent::delete($paths);
        }
    };
    $disk->answers = $answers;
    Storage::set('leads', $disk);

    return $disk;
}

/** @return array<string, mixed>|null the row as stored, read past RLS */
function lfRow(FormSubmissionFile $file): ?array
{
    $row = DB::connection('pgsql_admin')->table('form_submission_files')->where('id', $file->id)->first();

    return $row === null ? null : (array) $row;
}

it('streams a genuine file to the private disk under a key with no name or visitor data', function (): void {
    $lead = lfLead(1);
    $file = lfStore($lead, lfPdf());

    $row = lfRow($file);
    expect($row)->not->toBeNull()
        ->and($row['status'])->toBe('stored')
        ->and($row['site_id'])->toBe(1)
        ->and($row['form_submission_id'])->toBe($lead->id)
        ->and($row['field_key'])->toBe('cv')
        ->and($row['original_name'])->toBe('Hồ sơ Bình.pdf')
        ->and($row['mime'])->toBe('application/pdf')
        ->and($row['size_bytes'])->toBe(9 + 64)
        ->and($row['storage_key'])->toBe('leads/SITEA000000000000000000001/'.$file->ulid)
        ->and($row['storage_key'])->not->toContain('pdf')->not->toContain('Bình');
    Storage::disk('leads')->assertExists($row['storage_key']);
    expect(Storage::disk('leads')->get($row['storage_key']))->toBe("%PDF-1.4\n".str_repeat('x', 64))
        ->and(Storage::disk('leads')->allFiles())->toBe([$row['storage_key']]);
});

it('writes from a stream, never reading the upload whole into memory', function (): void {
    $lead = lfLead(1);
    $disk = lfDisk([]);

    expect(lfStore($lead, lfPdf())?->status)->toBe('stored')
        ->and($disk->calls)->toHaveCount(1)
        ->and($disk->calls[0][0])->toBe('writeStream')
        ->and($disk->calls[0][1][1])->toBeTrue()
        ->and($disk->calls[0][1][2])->toMatchArray(['ContentType' => 'application/octet-stream', 'ContentDisposition' => 'attachment', 'visibility' => 'private']);
});

it('records a refused file with its name and size and no object', function (string $kind, Closure $upload): void {
    config(['forms.file_max_mb' => 1]);
    $lead = lfLead(1);
    $file = lfStore($lead, $upload(), $kind);

    expect(lfRow($file))->toMatchArray(['status' => 'rejected', 'storage_key' => null, 'mime' => null, 'form_submission_id' => $lead->id])
        ->and(Storage::disk('leads')->allFiles())->toBe([]);
})->with([
    'a blocked name part' => ['cv', fn () => lfPdf('cv.php.pdf')],
    'a program' => ['cv', fn () => UploadedFile::fake()->createWithContent('setup.exe', "MZ\x90\x00")],
    'a type the kind does not take' => ['img', fn () => lfPdf('banve.pdf')],
    'forged bytes' => ['cad', fn () => UploadedFile::fake()->createWithContent('banve.dwg', '<?php echo 1;')],
    'over the size limit' => ['cv', fn () => lfPdf('cv.pdf', 1024 * 1024)],
]);

it('records the size the browser gave for an upload that never arrived whole', function (): void {
    $lead = lfLead(1);
    $file = new UploadedFile(lfPdf()->getRealPath(), 'cv.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

    expect(lfRow(lfStore($lead, $file)))->toMatchArray(['status' => 'rejected', 'storage_key' => null, 'size_bytes' => 9 + 64])
        ->and(Storage::disk('leads')->allFiles())->toBe([]);
});

it('keeps exactly up to the size limit', function (): void {
    config(['forms.file_max_mb' => 1]);
    $lead = lfLead(1);

    expect(lfStore($lead, lfPdf('cv.pdf', 1024 * 1024 - 9))?->status)->toBe('stored');
});

it('refuses to keep a file for a lead that is not saved', function (): void {
    $lead = (new FormSubmission)->forceFill(['site_id' => 1, 'name' => 'Bình', 'status' => 'new']);

    expect(fn () => lfStore($lead, lfPdf()))->toThrow(LogicException::class, 'once the lead is saved');
    expect(Storage::disk('leads')->allFiles())->toBe([])
        ->and(DB::connection('pgsql_admin')->table('form_submission_files')->count())->toBe(0);
});

it('keeps nothing of a spam lead: no object, no row', function (): void {
    $spam = lfLead(1, ['status' => 'spam', 'is_spam' => true]);

    expect(lfStore($spam, lfPdf()))->toBeNull()
        ->and(DB::connection('pgsql_admin')->table('form_submission_files')->count())->toBe(0)
        ->and(Storage::disk('leads')->allFiles())->toBe([]);
});

it('takes the object back out when the lead\'s transaction rolls back, or when no row can be written', function (): void {
    $lead = lfLead(1);
    expect(fn () => app(Tenancy::class)->enter(1, function () use ($lead): void {
        app(StoreLeadFile::class)->handle($lead, 'cv', 'cv', lfPdf());
        expect(Storage::disk('leads')->allFiles())->toHaveCount(1);

        throw new RuntimeException('the request failed after the file');
    }))->toThrow(RuntimeException::class, 'the request failed after the file');
    expect(Storage::disk('leads')->allFiles())->toBe([])
        ->and(DB::connection('pgsql_admin')->table('form_submission_files')->count())->toBe(0);

    // a field key too long for its column is refused before anything is written
    expect(fn () => lfStore($lead, lfPdf(), 'cv', str_repeat('k', 41)))->toThrow(InvalidArgumentException::class);
    expect(Storage::disk('leads')->allFiles())->toBe([]);
});

it('records nothing when the object cannot be written', function (): void {
    $lead = lfLead(1);
    lfDisk(['writeStream' => false]);

    expect(fn () => lfStore($lead, lfPdf()))->toThrow(RuntimeException::class)
        ->and(DB::connection('pgsql_admin')->table('form_submission_files')->count())->toBe(0);
});

it('keeps a lead\'s files only in the lead\'s own website', function (): void {
    $lead = lfLead(1);
    expect(fn () => app(Tenancy::class)->enter(2, fn () => app(StoreLeadFile::class)->handle($lead, 'cv', 'cv', lfPdf())))->toThrow(LogicException::class);

    $file = lfStore($lead, lfPdf());
    expect(app(Tenancy::class)->enter(1, fn () => FormSubmissionFile::query()->count()))->toBe(1)
        ->and(app(Tenancy::class)->enter(2, fn () => FormSubmissionFile::query()->count()))->toBe(0)
        ->and(app(Tenancy::class)->enter(2, fn () => DB::table('form_submission_files')->where('ulid', $file->ulid)->count()))->toBe(0)
        ->and(DB::table('form_submission_files')->count())->toBe(0); // no website: nothing (fail closed)

    // a row naming another website is refused by the policy's WITH CHECK
    expect(fn () => app(Tenancy::class)->enter(2, fn () => DB::table('form_submission_files')->insert([
        'site_id' => 1, 'ulid' => strtoupper((string) Str::ulid()), 'form_submission_id' => $lead->id, 'field_key' => 'cv',
        'original_name' => 'x.pdf', 'size_bytes' => 1, 'status' => 'rejected', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class);
    // and from site B, site A's row cannot be changed or deleted
    expect(app(Tenancy::class)->enter(2, fn (): int => DB::table('form_submission_files')->where('id', $file->id)->update(['status' => 'rejected', 'storage_key' => null])))->toBe(0)
        ->and(app(Tenancy::class)->enter(2, fn (): int => DB::table('form_submission_files')->where('id', $file->id)->delete()))->toBe(0)
        ->and(lfRow($file)['status'])->toBe('stored');
});

it('lists one lead\'s files in the order they came', function (): void {
    $lead = lfLead(1);
    $first = lfStore($lead, lfPdf('a.pdf'));
    $second = lfStore($lead, lfPdf('b.exe'));

    $names = app(Tenancy::class)->enter(1, fn () => $lead->files()->pluck('original_name')->all());
    expect($names)->toBe(['a.pdf', 'b.exe'])
        ->and([$first?->status, $second?->status])->toBe(['stored', 'rejected']);
});

/**
 * Every way a lead is deleted leaves its file rows waiting for the sweeper, their objects untouched.
 *
 * @return list<FormSubmissionFile>
 */
function lfFilesOf(FormSubmission $lead): array
{
    return [lfStore($lead, lfPdf('cv.pdf')), lfStore($lead, lfPdf('cv.exe'))];
}

function lfExpectOrphaned(array $files): void
{
    foreach ($files as $file) {
        $row = lfRow($file);
        expect($row)->not->toBeNull()->and($row['form_submission_id'])->toBeNull();
        if ($row['storage_key'] !== null) {
            Storage::disk('leads')->assertExists($row['storage_key']);
        }
    }
}

it('leaves the files for the sweeper when a lead is erased at the visitor\'s request', function (): void {
    $lead = lfLead(1);
    $files = lfFilesOf($lead);

    expect(app(Tenancy::class)->enter(1, fn (): bool => app(EraseLead::class)->handle($lead->ulid)))->toBeTrue();
    lfExpectOrphaned($files);
});

it('leaves the files for the sweeper when the Trash is emptied', function (): void {
    $this->travelTo(Carbon::parse(LeadTrash::SINCE, 'UTC')->addDays(45)->setTime(1, 0));
    $lead = lfLead(1);
    $files = lfFilesOf($lead);
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->update(['deleted_at' => now()->subDays(31)]);

    $this->artisan('leads:purge-trash')->assertSuccessful();
    expect(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->exists())->toBeFalse();
    lfExpectOrphaned($files);
});

it('leaves the files for the sweeper when a lead passes its retention period', function (): void {
    $this->travelTo(Carbon::parse(LeadRetention::SINCE, 'UTC')->addDays(30)->setTime(1, 0));
    $lead = lfLead(1);
    $files = lfFilesOf($lead);
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->update(['created_at' => now()->subMonths(25), 'updated_at' => now()->subMonths(25)]);

    $this->artisan('leads:purge-old')->assertSuccessful();
    expect(DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->exists())->toBeFalse();
    lfExpectOrphaned($files);
});

it('leaves the files for the sweeper when the lead\'s form is deleted, inline or in batches', function (): void {
    $inline = lfLead(1, [], 'bao-gia');
    $inlineFiles = lfFilesOf($inline);
    $form = app(Tenancy::class)->enter(1, fn (): Form => Form::query()->where('key', 'bao-gia')->firstOrFail());
    expect(app(Tenancy::class)->enter(1, fn (): ?array => app(DeleteForm::class)->handle($form)))->toMatchArray(['deleted' => true, 'later' => false]);
    lfExpectOrphaned($inlineFiles);

    $batched = lfLead(1, [], 'tuyen-dung');
    $batchedFiles = lfFilesOf($batched);
    $big = app(Tenancy::class)->enter(1, fn (): Form => Form::query()->where('key', 'tuyen-dung')->firstOrFail());
    $admin = DB::connection('pgsql_admin');
    $admin->table('forms')->where('id', $big->id)->update(['status' => Form::DELETING]);
    $claim = (string) $admin->table('forms')->where('id', $big->id)->value('updated_at');
    expect((new DeleteLargeForm(1, $big->id, $claim))->handle(app(Tenancy::class)))->toBeTrue()
        ->and($admin->table('forms')->where('id', $big->id)->exists())->toBeFalse();
    lfExpectOrphaned($batchedFiles);
});

it('sweeps each website\'s files of deleted leads: the object first, then the row, and nothing else', function (): void {
    $gone = lfLead(1);
    $kept = lfLead(1, ['name' => 'Lê Thị Cúc']);
    $theirsGone = lfLead(2);
    $goneFiles = lfFilesOf($gone);
    $keptFiles = lfFilesOf($kept);
    $theirsFiles = lfFilesOf($theirsGone);
    $admin = DB::connection('pgsql_admin');
    $admin->table('form_submissions')->whereIn('id', [$gone->id, $theirsGone->id])->delete();

    $this->artisan('forms:sweep-files')->expectsOutputToContain('Deleted 4 file(s)')->assertSuccessful();

    foreach ([...$goneFiles, ...$theirsFiles] as $file) {
        expect(lfRow($file))->toBeNull();
    }
    foreach ($keptFiles as $file) {
        expect(lfRow($file)['form_submission_id'])->toBe($kept->id);
    }
    expect(Storage::disk('leads')->allFiles())->toBe([lfRow($keptFiles[0])['storage_key']]);

    $audits = $admin->table('audit_logs')->where('action', 'forms.lead_files.swept')->orderBy('site_id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('site_id')->all())->toBe([1, 2])
        ->and(json_decode((string) $audits[0]->meta, true))->toBe(['count' => 2, 'scheduled' => true]);

    // again: nothing more to do, and no new audit row
    $this->artisan('forms:sweep-files')->expectsOutputToContain('Deleted 0 file(s)')->assertSuccessful();
    expect($admin->table('audit_logs')->where('action', 'forms.lead_files.swept')->count())->toBe(2);
});

it('keeps the row of an object it could not delete, for the next run, and says so', function (): void {
    $lead = lfLead(1);
    [$stored, $rejected] = lfFilesOf($lead);
    DB::connection('pgsql_admin')->table('form_submissions')->where('id', $lead->id)->delete();
    $real = Storage::disk('leads');
    lfDisk(['delete' => false]);

    $this->artisan('forms:sweep-files')->expectsOutputToContain('1 object(s) could not be deleted')->assertFailed();
    expect(lfRow($stored))->not->toBeNull()
        ->and(lfRow($rejected))->toBeNull(); // a refused file has no object: its row goes
    $real->assertExists(lfRow($stored)['storage_key']);

    Storage::set('leads', $real);
    $this->artisan('forms:sweep-files')->assertSuccessful();
    expect(lfRow($stored))->toBeNull()->and($real->allFiles())->toBe([]);
});

it('walks a website\'s orphans in chunks by id', function (): void {
    $leads = [];
    for ($i = 0; $i < 3; $i++) {
        $leads[] = lfLead(1, ['name' => 'Khách '.$i]);
    }
    $admin = DB::connection('pgsql_admin');
    foreach ($leads as $lead) {
        lfStore($lead, lfPdf());
    }
    $admin->table('form_submissions')->whereIn('id', array_map(fn (FormSubmission $l): int => $l->id, $leads))->delete();
    // 205 more orphans, past one chunk of 200, written straight in
    $admin->statement(<<<'SQL'
        insert into form_submission_files (site_id, ulid, field_key, original_name, size_bytes, status, created_at, updated_at)
        select 1, upper(substr(md5(g::text), 1, 26)), 'cv', 'x.exe', 1, 'rejected', now(), now() from generate_series(1, 205) g
        SQL);

    $this->artisan('forms:sweep-files')->expectsOutputToContain('Deleted 208 file(s)')->assertSuccessful();
    expect($admin->table('form_submission_files')->count())->toBe(0)
        ->and(Storage::disk('leads')->allFiles())->toBe([]);
});

it('runs the sweeper every hour', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'forms:sweep-files'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('signs a 60-second download link to the object, as an attachment that is never shown inline', function (): void {
    $lead = lfLead(1);
    $file = lfStore($lead, lfPdf('Hồ sơ "Bình".pdf'));
    $seen = [];
    Storage::disk('leads')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expires, array $options) use (&$seen): string {
        $seen = ['path' => $path, 'seconds' => $expires->getTimestamp() - now()->getTimestamp(), 'options' => $options];

        return 'https://r2.example.test/'.$path;
    });

    expect(LeadFileStorage::downloadUrl($file))->toBe('https://r2.example.test/'.$file->storage_key)
        ->and($seen['path'])->toBe($file->storage_key)
        ->and($seen['seconds'])->toBe(60)
        ->and($seen['options']['ResponseContentType'])->toBe('application/octet-stream')
        ->and($seen['options']['ResponseContentDisposition'])->toBe('attachment; filename="tep-dinh-kem-1.pdf"');
});

it('names a downloaded file by its place in the lead, never with anything of the visitor\'s name', function (): void {
    $lead = lfLead(1);
    $refused = lfStore($lead, UploadedFile::fake()->createWithContent('Nguyễn Văn Bình.exe', 'MZ'));
    $cv = lfStore($lead, lfPdf('CV Nguyễn Văn Bình.pdf'));
    $drawing = lfStore($lead, UploadedFile::fake()->createWithContent('Bản vẽ của Bình.STEP', "ISO-10303-21;\nHEADER;\n"), 'cad', 'ban_ve');
    $other = lfStore(lfLead(1), lfPdf('Bình.pdf'));
    $seen = [];
    Storage::disk('leads')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expires, array $options) use (&$seen): string {
        $seen[] = $options['ResponseContentDisposition'];

        return 'https://r2.example.test/'.$path;
    });

    expect($refused?->status)->toBe(FormSubmissionFile::REJECTED);
    // in the website, as the download route asks (outside one, no row is seen and every file is 1)
    app(Tenancy::class)->enter(1, function () use ($cv, $drawing, $other): void {
        foreach ([$cv, $drawing, $other] as $file) {
            LeadFileStorage::downloadUrl($file);
        }
    });

    expect($seen)->toBe([
        'attachment; filename="tep-dinh-kem-2.pdf"',
        'attachment; filename="tep-dinh-kem-3.step"',
        'attachment; filename="tep-dinh-kem-1.pdf"',
    ]);
    foreach ($seen as $disposition) {
        expect($disposition)->not->toContain('Binh')->not->toContain('Nguyen')->not->toContain('filename*');
    }
});

<?php

use App\Modules\Forms\Actions\StoreLeadFile;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * forms:reconcile-files deletes the objects on the lead-files disk that no row names: a request
 * killed after writing a visitor's file and before committing leaves one, and no erase or sweep
 * can reach it. Website by website: an object over an hour old that no row of that website holds
 * goes, a younger one (its request may not have committed yet) and every object a row names stay;
 * one website that fails does not stop the others; counts only, never a key or a name.
 */

beforeEach(function (): void {
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    Storage::fake('leads');
});

function lrcSiteUlid(int $site): string
{
    return (string) DB::connection('pgsql_admin')->table('sites')->where('id', $site)->value('ulid');
}

/** A stored file of a new lead of $site. */
function lrcStored(int $site): FormSubmissionFile
{
    return app(Tenancy::class)->enter($site, function (): FormSubmissionFile {
        $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);
        $lead = FormSubmission::query()->create(['form_id' => $form->id, 'name' => 'Trần Văn Bình', 'status' => 'new', 'data' => []]);

        $file = app(StoreLeadFile::class)->handle($lead, 'cv', 'cv', UploadedFile::fake()->createWithContent('Hồ sơ Bình.pdf', "%PDF-1.4\nCV"));
        expect($file?->status)->toBe(FormSubmissionFile::STORED);

        return $file;
    });
}

/** Puts an object no row names under $site's prefix (or at $key), $age seconds old. */
function lrcObject(?int $site, int $age, ?string $key = null): string
{
    $key ??= 'leads/'.lrcSiteUlid((int) $site).'/'.Str::ulid();
    lrcAge(Storage::disk('leads'), $key, $age, true);

    return $key;
}

function lrcAge(FilesystemAdapter $disk, string $key, int $age, bool $write = false): void
{
    if ($write) {
        $disk->put($key, 'tệp của khách');
    }
    touch($disk->path($key), time() - $age);
    clearstatcache();
}

function lrcAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'forms.lead_files.reconciled')
        ->orderBy('site_id')->get(['site_id', 'meta'])
        ->map(fn (object $row): array => [$row->site_id, json_decode((string) $row->meta, true)])->all();
}

it('deletes only the old objects no row of their website names', function (): void {
    $disk = Storage::disk('leads');
    $keptA = lrcStored(1);
    $keptB = lrcStored(2);
    lrcAge($disk, (string) $keptA->storage_key, 7200);
    lrcAge($disk, (string) $keptB->storage_key, 7200);

    $orphan = lrcObject(1, 7200);
    $young = lrcObject(1, 600);
    $junk = lrcObject(null, 7200, 'leads/'.lrcSiteUlid(1).'/ghi-chu.txt');
    // named like site B's file but under site A's prefix: no row of site A holds it
    $misplaced = lrcObject(null, 7200, 'leads/'.lrcSiteUlid(1).'/'.$keptB->ulid);
    $orphanB = lrcObject(2, 90_000);
    $check = lrcObject(null, 7200, 'storage-check/'.Str::lower((string) Str::ulid()));

    $this->artisan('forms:reconcile-files')
        ->expectsOutputToContain('Deleted 4 object(s)')
        ->assertSuccessful();

    expect($disk->exists($orphan))->toBeFalse()
        ->and($disk->exists($junk))->toBeFalse()
        ->and($disk->exists($misplaced))->toBeFalse()
        ->and($disk->exists($orphanB))->toBeFalse()
        ->and($disk->exists($young))->toBeTrue()
        ->and($disk->exists((string) $keptA->storage_key))->toBeTrue()
        ->and($disk->exists((string) $keptB->storage_key))->toBeTrue()
        ->and($disk->exists($check))->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('form_submission_files')->count())->toBe(2)
        ->and(lrcAudits())->toBe([[1, ['count' => 3, 'scheduled' => true]], [2, ['count' => 1, 'scheduled' => true]]]);

    // a second run finds nothing more to delete and writes nothing
    $this->artisan('forms:reconcile-files')->expectsOutputToContain('Deleted 0 object(s)')->assertSuccessful();
    expect(lrcAudits())->toHaveCount(2);
});

it('keeps the object of a file whose lead is gone: that one is the sweeper\'s, by its row', function (): void {
    $file = lrcStored(1);
    lrcAge(Storage::disk('leads'), (string) $file->storage_key, 7200);
    DB::connection('pgsql_admin')->table('form_submissions')->delete();

    $this->artisan('forms:reconcile-files')->assertSuccessful();

    expect(Storage::disk('leads')->exists((string) $file->storage_key))->toBeTrue();
});

it('walks a website\'s objects in chunks', function (): void {
    $keys = [];
    for ($i = 0; $i < 205; $i++) {
        $keys[] = lrcObject(1, 7200);
    }
    $kept = lrcStored(1);
    lrcAge(Storage::disk('leads'), (string) $kept->storage_key, 7200);

    $this->artisan('forms:reconcile-files')->expectsOutputToContain('Deleted 205 object(s)')->assertSuccessful();

    expect(Storage::disk('leads')->allFiles())->toBe([(string) $kept->storage_key])
        ->and(lrcAudits())->toBe([[1, ['count' => 205, 'scheduled' => true]]]);
});

it('goes on with the other websites when one fails, and says so by site and count only', function (): void {
    $a = lrcObject(1, 7200);
    $b = lrcObject(2, 7200);
    $prefixA = 'leads/'.lrcSiteUlid(1).'/';
    $real = Storage::disk('leads');
    Storage::set('leads', new class($real->getDriver(), $real->getAdapter(), $real->getConfig(), $prefixA) extends FilesystemAdapter
    {
        public function __construct($driver, $adapter, array $config, private string $broken)
        {
            parent::__construct($driver, $adapter, $config);
        }

        #[Override]
        public function delete($paths)
        {
            foreach ((array) $paths as $path) {
                if (str_starts_with((string) $path, $this->broken)) {
                    throw new RuntimeException('bucket down for '.$path);
                }
            }

            return parent::delete($paths);
        }
    });
    Log::spy();

    $this->artisan('forms:reconcile-files')
        ->expectsOutputToContain('1 website(s) could not be reconciled')
        ->assertFailed();

    expect($real->exists($a))->toBeTrue()
        ->and($real->exists($b))->toBeFalse()
        ->and(lrcAudits())->toBe([[2, ['count' => 1, 'scheduled' => true]]]);
    Log::shouldHaveReceived('error')->withArgs(fn (mixed $message, mixed $context = null): bool => $context === ['site_id' => 1])->once();
});

it('never lists past a website whose ulid is malformed: that would be every website\'s prefix', function (): void {
    $a = lrcObject(1, 7200);
    $b = lrcObject(2, 7200);
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['ulid' => str_repeat(' ', 26)]);

    $this->artisan('forms:reconcile-files')
        ->expectsOutputToContain('1 website(s) could not be reconciled')
        ->assertFailed();

    expect(Storage::disk('leads')->exists($a))->toBeTrue()
        ->and(Storage::disk('leads')->exists($b))->toBeFalse()
        ->and(lrcAudits())->toBe([[2, ['count' => 1, 'scheduled' => true]]]);
});

it('keeps an object it could not delete for the next run, and fails', function (): void {
    $a = lrcObject(1, 7200);
    $real = Storage::disk('leads');
    Storage::set('leads', new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
    {
        #[Override]
        public function delete($paths)
        {
            return false;
        }
    });
    Log::spy();

    $this->artisan('forms:reconcile-files')
        ->expectsOutputToContain('1 object(s) could not be deleted')
        ->assertFailed();

    expect($real->exists($a))->toBeTrue()->and(lrcAudits())->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (mixed $message, mixed $context = null): bool => $context === ['site_id' => 1, 'count' => 1])->once();
});

it('runs the reconcile pass every day', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'forms:reconcile-files'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

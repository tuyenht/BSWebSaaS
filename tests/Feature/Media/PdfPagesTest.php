<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Jobs\CountPdfPages;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\PdfPages;
use App\Modules\Tenancy\Context\Tenancy;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * A PDF's page count: a new PDF upload queues CountPdfPages after commit; the job runs qpdf (as a
 * list of arguments, under a timeout and prlimit caps) inside the website's own tenant context and
 * keeps a sensible count in meta.pages. Garbage output, a failing exit, a timeout or a count out of
 * range leaves it empty and marks the file, so it is not read again until it changes; qpdf missing
 * marks nothing. qpdf gets PATH and LC_ALL alone; the storage read has a deadline. media:pdf-pages
 * fills older PDFs, a dry run by default.
 */

beforeEach(function (): void {
    seedTenancy();
    Storage::fake('r2');
});

/** A real, minimal PDF of $pages blank pages (correct cross-reference offsets). */
function pdfPagesFixture(int $pages): string
{
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    for ($i = 0; $i < $pages; $i++) {
        $kids[] = (3 + $i).' 0 R';
        $objects[3 + $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>';
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $n => $body) {
        $offsets[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

/** Applies a hand-written incremental update to a two-page fixture: a third page in a new revision. */
function pdfPagesAppendedRevision(): string
{
    $base = pdfPagesFixture(2);
    preg_match('/startxref\n([0-9]+)\n/', $base, $m);
    $pdf = $base;
    $at2 = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3 >>\nendobj\n";
    $at5 = strlen($pdf);
    $pdf .= "5 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\n";
    $xref = strlen($pdf);
    $pdf .= "xref\n0 1\n0000000000 65535 f \n2 1\n".sprintf("%010d 00000 n \n", $at2).'5 1'."\n".sprintf("%010d 00000 n \n", $at5);

    return $pdf."trailer\n<< /Size 6 /Root 1 0 R /Prev {$m[1]} >>\nstartxref\n{$xref}\n%%EOF\n";
}

/** Runs the real qpdf to rewrite $pdf with extra arguments (object streams, encryption). */
function pdfPagesRewrite(string $pdf, array $args): string
{
    $in = (string) tempnam(sys_get_temp_dir(), 'pdf-fixture-');
    $out = $in.'.out.pdf';
    file_put_contents($in, $pdf);
    try {
        $result = (new SymfonyProcess(['qpdf', ...$args, $in, $out]))->mustRun();
        expect($result->getExitCode())->toBe(0);

        return (string) file_get_contents($out);
    } finally {
        @unlink($in);
        @unlink($out);
    }
}

/** A stream that hands out one byte every 0.2 s, to stand in for a stalled storage read. */
final class PdfPagesSlowStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        usleep(200_000);

        return 'x';
    }

    public function stream_eof(): bool
    {
        return false;
    }
}

/** @param  array<string, mixed>  $meta */
function pdfPagesRow(int $site, string $name, array $meta = [], string $mime = 'application/pdf'): Media
{
    return app(Tenancy::class)->enter($site, function () use ($site, $name, $meta, $mime): Media {
        $key = "s{$site}/library/{$name}";
        Storage::disk('r2')->put($key, pdfPagesFixture(3));

        return Media::query()->create(array_filter([
            'collection' => 'library',
            'slug' => pathinfo($name, PATHINFO_FILENAME),
            'hash' => hash('sha256', $site.$name),
            'storage_key' => $key,
            'disk' => 'r2',
            'original_filename' => $name,
            'mime' => $mime,
            'size' => 1024,
            'meta' => $meta === [] ? null : $meta, // none given: the column's default ({})
        ], fn (mixed $v): bool => $v !== null));
    });
}

/** @return array<string, mixed> the row's meta as stored */
function pdfPagesStored(Media $row): array
{
    $raw = app(Tenancy::class)->enter($row->site_id, fn () => Media::withTrashed()->whereKey($row->id)->toBase()->value('meta'));

    return (array) json_decode((string) $raw, true);
}

it('runs qpdf as an argument list under limits and keeps the count in meta.pages', function (): void {
    Process::fake(['*' => Process::result("3\n")]);
    $row = pdfPagesRow(1, 'bang-gia.pdf', ['seed_source' => 'x/y.pdf']);

    (new CountPdfPages(1, $row->id))->handle(app(PdfPages::class));

    expect(pdfPagesStored($row))->toEqual(['pages' => 3, 'seed_source' => 'x/y.pdf']); // the other key is kept
    Process::assertRanTimes(function (PendingProcess $p): bool {
        $cmd = $p->command;

        return is_array($cmd) && $cmd[0] === 'prlimit' && in_array('--', $cmd, true)
            && array_slice($cmd, -3, 2) === ['qpdf', '--show-npages']
            && str_starts_with((string) end($cmd), sys_get_temp_dir())
            && $p->timeout === PdfPages::TIMEOUT_SECONDS
            // nothing of the app's own environment reaches qpdf: PATH and LC_ALL alone
            && array_filter($p->environment, fn (mixed $v): bool => $v !== false) === ['PATH' => PdfPages::PATH, 'LC_ALL' => 'C']
            && array_key_exists('AUTH_PASSWORD_PEPPER', $p->environment) && $p->environment['AUTH_PASSWORD_PEPPER'] === false;
    }, 1);
    expect(glob(sys_get_temp_dir().'/pdf-pages-*') ?: [])->toBe([]); // the private copy is gone
});

it('writes the count into a meta that is not an object', function (): void {
    Process::fake(['*' => Process::result('2')]);
    $row = pdfPagesRow(1, 'mang.pdf');
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($row->id)->toBase()->update(['meta' => DB::raw("'[]'::jsonb")]));

    expect(app(PdfPages::class)->record(1, $row->id))->toBe(2)
        ->and(pdfPagesStored($row))->toBe(['pages' => 2]);
});

it('keeps an existing count: a second run reads nothing and changes nothing', function (): void {
    Process::fake();
    $row = pdfPagesRow(1, 'da-dem.pdf', ['pages' => 12]);

    expect(app(PdfPages::class)->record(1, $row->id))->toBe(12);
    Process::assertNothingRan();
});

it('leaves the count empty for output or exits it cannot trust, and marks the file', function (FakeProcessResult|Closure $result): void {
    Process::fake(['*' => $result]);
    $row = pdfPagesRow(1, 'hong.pdf');

    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(pdfPagesStored($row))->toBe([PdfPages::UNREADABLE_KEY => $row->storage_key]);

    // the same file is not read again, by the job or the command
    $again = false;
    Process::fake(['*' => function () use (&$again): FakeProcessResult {
        $again = true;

        return Process::result('1');
    }]);
    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and($again)->toBeFalse();
})->with([
    'garbage output' => fn () => Process::result("qpdf: lots of words\n3"),
    'zero pages' => fn () => Process::result("0\n"),
    'too many pages' => fn () => Process::result('100001'),
    'negative' => fn () => Process::result('-3'),
    'a fraction' => fn () => Process::result('2.5'),
    'empty' => fn () => Process::result(''),
    'qpdf error (broken or password-protected)' => fn () => Process::result('', 'invalid password', 2),
    'killed by the CPU cap' => fn () => Process::result('', '', 137),
    'timed out' => fn () => function (): never {
        $process = new SymfonyProcess(['true']);

        throw new ProcessTimedOutException(new SymfonyTimedOut($process, SymfonyTimedOut::TYPE_GENERAL), new FakeProcessResult);
    },
]);

it('marks nothing when qpdf is not installed, so a later run tries again', function (int $exit): void {
    Process::fake(['*' => Process::result('', 'prlimit: failed to execute qpdf', $exit)]);
    $row = pdfPagesRow(1, 'chua-co-qpdf.pdf');

    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(pdfPagesStored($row))->toBe([]);
    Process::assertRanTimes(fn (): bool => true, 2);
})->with(['not found (127)' => 127, 'cannot execute (126)' => 126]);

it('counts a file again once it is replaced under a new key', function (): void {
    Process::fake(['*' => Process::sequence([Process::result('', 'damaged', 2), Process::result('6')])]);
    $row = pdfPagesRow(1, 'thay.pdf');
    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull();

    $newKey = 's1/library/thay-moi.pdf';
    Storage::disk('r2')->put($newKey, pdfPagesFixture(6));
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($row->id)->toBase()->update(['storage_key' => $newKey]));

    expect(app(PdfPages::class)->record(1, $row->id))->toBe(6)
        ->and(pdfPagesStored($row)['pages'])->toBe(6);
});

it('marks a row larger than the upload cap without reading it', function (): void {
    Process::fake();
    $row = pdfPagesRow(1, 'qua-lon.pdf');
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($row->id)->toBase()->update(['size' => UploadMedia::MAX_KB * 1024 + 1]));

    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(pdfPagesStored($row))->toBe([PdfPages::UNREADABLE_KEY => $row->storage_key]);
    Process::assertNothingRan();
});

it('gives qpdf PATH and LC_ALL and nothing else of this process', function (): void {
    $env = (new SymfonyProcess(['env'], null, PdfPages::environment()))->mustRun()->getOutput();

    expect(array_values(array_filter(explode("\n", $env))))->toEqualCanonicalizing(['PATH='.PdfPages::PATH, 'LC_ALL=C']);
});

it('stops a storage read that stalls, and caps what it copies', function (): void {
    stream_wrapper_register('pdfpages-slow', PdfPagesSlowStream::class);
    try {
        $in = fopen('pdfpages-slow://x', 'rb');
        $out = fopen('php://memory', 'wb');
        $started = microtime(true);
        expect(PdfPages::copyWithin($in, $out, 1_000_000, 0.5))->toBeNull()
            ->and(microtime(true) - $started)->toBeLessThan(1.5);
    } finally {
        stream_wrapper_unregister('pdfpages-slow');
    }

    $big = fopen('php://memory', 'w+b');
    fwrite($big, str_repeat('a', 100));
    rewind($big);
    $sink = fopen('php://memory', 'wb');
    expect(PdfPages::copyWithin($big, $sink, 40, 5.0))->toBe(41); // one byte past the cap: too big

    rewind($big);
    expect(PdfPages::copyWithin($big, fopen('php://memory', 'wb'), 100, 5.0))->toBe(100);
});

it('asks R2 for the object with a connect and read timeout', function (): void {
    $row = pdfPagesRow(1, 'tren-r2.pdf');
    $disk = Storage::build([
        'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'media-test',
        'endpoint' => 'https://r2.invalid', 'use_path_style_endpoint' => true,
        'request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required',
    ]);
    expect($disk)->toBeInstanceOf(AwsS3V3Adapter::class);
    $seen = null;
    $disk->getClient()->getHandlerList()->setHandler(new MockHandler([
        function (CommandInterface $command) use (&$seen): Result {
            $seen = $command;

            return new Result(['Body' => Utils::streamFor(pdfPagesFixture(3))]);
        },
    ]));
    Storage::set('r2', $disk);
    Process::fake(['*' => Process::result('3')]);

    expect(app(PdfPages::class)->record(1, $row->id))->toBe(3);
    expect($seen)->toBeInstanceOf(CommandInterface::class)
        ->and($seen->getName())->toBe('GetObject')
        ->and($seen['Bucket'])->toBe('media-test')
        ->and($seen['Key'])->toBe($row->storage_key)
        ->and($seen['@http'])->toMatchArray([
            'stream' => true,
            'connect_timeout' => PdfPages::READ_IDLE_SECONDS,
            'read_timeout' => PdfPages::READ_IDLE_SECONDS,
        ]);
});

it('takes the count qpdf gives after repairing a file (exit 3)', function (): void {
    Process::fake(['*' => Process::result("7\n", 'WARNING: file is damaged', 3)]);
    $row = pdfPagesRow(1, 'sua.pdf');

    expect(app(PdfPages::class)->record(1, $row->id))->toBe(7);
});

it('does not give a count to a file swapped while it was being counted', function (): void {
    $row = pdfPagesRow(1, 'doi.pdf');
    Process::fake(['*' => function () use ($row) {
        app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($row->id)->toBase()->update(['storage_key' => 's1/library/doi-moi.pdf']));

        return Process::result('4');
    }]);

    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(pdfPagesStored($row))->toBe([]);
});

it('stays inside the website it was given', function (): void {
    Process::fake(['*' => Process::result('5')]);
    $other = pdfPagesRow(2, 'cua-site-2.pdf');

    // the job for site 1 naming site 2's row finds nothing and writes nothing
    expect(app(PdfPages::class)->record(1, $other->id))->toBeNull()
        ->and(pdfPagesStored($other))->toBe([]);
    Process::assertNothingRan();

    expect(app(PdfPages::class)->record(2, $other->id))->toBe(5);
});

it('skips a row that is not a PDF, and a PDF whose object is missing', function (): void {
    Process::fake(['*' => Process::result('5')]);
    $image = pdfPagesRow(1, 'anh.png', [], 'image/png');
    $gone = pdfPagesRow(1, 'mat.pdf');
    Storage::disk('r2')->delete($gone->storage_key);

    expect(app(PdfPages::class)->record(1, $image->id))->toBeNull()
        ->and(app(PdfPages::class)->record(1, $gone->id))->toBeNull()
        ->and(app(PdfPages::class)->record(1, 999_999_999))->toBeNull();
    Process::assertNothingRan();
});

it('counts real PDFs with the qpdf binary, and leaves the ones it cannot read', function (): void {
    $plain = pdfPagesFixture(4);
    $cases = [
        'one page' => [pdfPagesFixture(1), 1],
        'several pages' => [$plain, 4],
        'object and cross-reference streams' => [pdfPagesRewrite(pdfPagesFixture(5), ['--object-streams=generate']), 5],
        'an appended revision' => [pdfPagesAppendedRevision(), 3],
        'password protected' => [pdfPagesRewrite($plain, ['--encrypt', 'mat-khau', 'chu-so-huu', '256', '--']), null],
        'cut short' => [substr($plain, 0, 60), null],
        'not a PDF' => ["PK\x03\x04 this is a zip, not a PDF\n".str_repeat("\x00\xff", 64), null],
        'empty' => ['', null],
    ];
    expect(pdfPagesRewrite(pdfPagesFixture(5), ['--object-streams=generate']))->toContain('/ObjStm');

    foreach ($cases as $name => [$bytes, $pages]) {
        $path = (string) tempnam(sys_get_temp_dir(), 'pdf-fixture-');
        file_put_contents($path, $bytes);
        try {
            expect(app(PdfPages::class)->count($path))->toBe($pages, $name);
        } finally {
            unlink($path);
        }
    }

    // through the job's path: an unreadable file is marked by qpdf's real verdict
    $row = pdfPagesRow(1, 'that-khong-doc.pdf');
    Storage::disk('r2')->put($row->storage_key, 'not a PDF');
    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(pdfPagesStored($row))->toBe([PdfPages::UNREADABLE_KEY => $row->storage_key]);
})->skip(fn (): bool => (new ExecutableFinder)->find('qpdf') === null, 'qpdf is not installed here (CI installs it in the Pest shards; the app image gets it with its own runtime change)');

it('queues the count after commit for a new PDF upload only', function (): void {
    Queue::fake();

    $pdf = app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle(
        UploadedFile::fake()->createWithContent('Bảng giá.pdf', pdfPagesFixture(2)),
    ));
    Queue::assertPushed(CountPdfPages::class, fn (CountPdfPages $job): bool => $job->siteId === 1 && $job->mediaId === $pdf->id && $job->afterCommit === true);

    // the same bytes again (dedup) and a picture queue nothing more
    app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle(
        UploadedFile::fake()->createWithContent('lai.pdf', pdfPagesFixture(2)),
    ));
    app(Tenancy::class)->enter(1, fn () => app(UploadMedia::class)->handle(UploadedFile::fake()->image('anh.png', 4, 4)));
    Queue::assertPushed(CountPdfPages::class, 1);
});

it('queues the count for a PDF finished through the direct upload', function (): void {
    Queue::fake();
    $tempKey = '_incoming/p/01pdf.pdf';
    Storage::disk('r2')->put($tempKey, pdfPagesFixture(2));
    Cache::put(PresignMediaUpload::ownershipKey($tempKey), 2, now()->addMinutes(5));

    $media = app(Tenancy::class)->enter(2, fn () => app(RegisterMediaUpload::class)->handle($tempKey, 'Hồ sơ.pdf'));

    Queue::assertPushed(CountPdfPages::class, fn (CountPdfPages $job): bool => $job->siteId === 2 && $job->mediaId === $media->id);
});

it('fills older PDFs only on --apply; the dry run reads and writes nothing', function (): void {
    Process::fake(['*' => Process::sequence([Process::result('9'), Process::result('', 'damaged', 2)])]);
    $a = pdfPagesRow(1, 'cu-a.pdf');
    $b = pdfPagesRow(1, 'cu-b.pdf');
    $done = pdfPagesRow(1, 'cu-c.pdf', ['pages' => 4]);
    $other = pdfPagesRow(2, 'cu-d.pdf');

    $this->artisan('media:pdf-pages', ['--site' => '1'])
        ->expectsOutputToContain('Website #1: 3 PDFs, 1 with pages, 0 unreadable before, 2 left.')
        ->expectsOutputToContain('Nothing was written.')
        ->assertSuccessful();
    Process::assertNothingRan();
    expect(pdfPagesStored($a))->toBe([]);

    $this->artisan('media:pdf-pages', ['--site' => '1', '--apply' => true])
        ->expectsOutputToContain('Website #1: 3 PDFs, 1 with pages, 0 unreadable before, 2 left, 1 counted, 1 could not be counted.')
        ->assertSuccessful();
    expect(pdfPagesStored($a))->toBe(['pages' => 9])
        ->and(pdfPagesStored($b))->toBe([PdfPages::UNREADABLE_KEY => $b->storage_key])
        ->and(pdfPagesStored($done))->toBe(['pages' => 4])
        ->and(pdfPagesStored($other))->toBe([]); // another website is untouched
    Process::assertRanTimes(fn (): bool => true, 2);

    // a second run reads nothing: the counted one has its pages, the unreadable one its mark
    $this->artisan('media:pdf-pages', ['--site' => '1', '--apply' => true])
        ->expectsOutputToContain('Website #1: 3 PDFs, 2 with pages, 1 unreadable before, 0 left, 0 counted, 0 could not be counted.')
        ->assertSuccessful();
    Process::assertRanTimes(fn (): bool => true, 2);

    // its file replaced (a new key), the unreadable one is left to count again
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($b->id)->toBase()->update(['storage_key' => 's1/library/cu-b-moi.pdf']));
    $this->artisan('media:pdf-pages', ['--site' => '1'])
        ->expectsOutputToContain('Website #1: 3 PDFs, 2 with pages, 0 unreadable before, 1 left.')
        ->assertSuccessful();
});

it('tries again on the next run a PDF it could not read because qpdf is missing', function (): void {
    Process::fake(['*' => Process::result('', 'prlimit: failed to execute qpdf', 127)]);
    pdfPagesRow(1, 'sau-nay.pdf');

    foreach ([1, 2] as $run) {
        $this->artisan('media:pdf-pages', ['--site' => '1', '--apply' => true])
            ->expectsOutputToContain('Website #1: 1 PDFs, 0 with pages, 0 unreadable before, 1 left, 0 counted, 1 could not be counted.')
            ->assertSuccessful();
    }
    Process::assertRanTimes(fn (): bool => true, 2);
});

it('refuses an unknown website and --dry-run with --apply', function (): void {
    $this->artisan('media:pdf-pages')->assertFailed();
    $this->artisan('media:pdf-pages', ['--site' => '999999'])->assertFailed();
    $this->artisan('media:pdf-pages', ['--site' => '1', '--apply' => true, '--dry-run' => true])->assertFailed();
});

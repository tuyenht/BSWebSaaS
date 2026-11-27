<?php

use App\Modules\Media\Actions\PresignMediaUpload;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Jobs\CountPdfPages;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\PdfPages;
use App\Modules\Tenancy\Context\Tenancy;
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
 * range leaves it empty. media:pdf-pages fills older PDFs, a dry run by default.
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
            && $p->timeout === PdfPages::TIMEOUT_SECONDS;
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

it('leaves the count empty for output or exits it cannot trust', function (FakeProcessResult|Closure $result): void {
    Process::fake(['*' => $result]);
    $row = pdfPagesRow(1, 'hong.pdf');

    expect(app(PdfPages::class)->record(1, $row->id))->toBeNull()
        ->and(pdfPagesStored($row))->toBe([]);
})->with([
    'garbage output' => fn () => Process::result("qpdf: lots of words\n3"),
    'zero pages' => fn () => Process::result("0\n"),
    'too many pages' => fn () => Process::result('100001'),
    'negative' => fn () => Process::result('-3'),
    'a fraction' => fn () => Process::result('2.5'),
    'empty' => fn () => Process::result(''),
    'qpdf error (broken or password-protected)' => fn () => Process::result('', 'invalid password', 2),
    'qpdf missing (prlimit 127)' => fn () => Process::result('', 'failed to execute qpdf', 127),
    'killed by the CPU cap' => fn () => Process::result('', '', 137),
    'timed out' => fn () => function (): never {
        $process = new SymfonyProcess(['true']);

        throw new ProcessTimedOutException(new SymfonyTimedOut($process, SymfonyTimedOut::TYPE_GENERAL), new FakeProcessResult);
    },
]);

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

it('counts a real PDF with the qpdf binary', function (): void {
    foreach ([1, 4] as $n) {
        $path = (string) tempnam(sys_get_temp_dir(), 'pdf-fixture-');
        file_put_contents($path, pdfPagesFixture($n));
        try {
            expect(app(PdfPages::class)->count($path))->toBe($n);
        } finally {
            unlink($path);
        }
    }
    $broken = (string) tempnam(sys_get_temp_dir(), 'pdf-fixture-');
    file_put_contents($broken, substr(pdfPagesFixture(3), 0, 60));
    try {
        expect(app(PdfPages::class)->count($broken))->toBeNull();
    } finally {
        unlink($broken);
    }
})->skip(fn (): bool => (new ExecutableFinder)->find('qpdf') === null, 'qpdf is not installed in this image (it comes with the rebuilt app image: docker/frankenphp/Dockerfile)');

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
        ->expectsOutputToContain('Website #1: 3 PDFs, 1 with pages, 2 left.')
        ->expectsOutputToContain('Nothing was written.')
        ->assertSuccessful();
    Process::assertNothingRan();
    expect(pdfPagesStored($a))->toBe([]);

    $this->artisan('media:pdf-pages', ['--site' => '1', '--apply' => true])
        ->expectsOutputToContain('Website #1: 3 PDFs, 1 with pages, 2 left, 1 counted, 1 could not be counted.')
        ->assertSuccessful();
    expect(pdfPagesStored($a))->toBe(['pages' => 9])
        ->and(pdfPagesStored($b))->toBe([])
        ->and(pdfPagesStored($done))->toBe(['pages' => 4])
        ->and(pdfPagesStored($other))->toBe([]); // another website is untouched
    Process::assertRanTimes(fn (): bool => true, 2);
});

it('refuses an unknown website and --dry-run with --apply', function (): void {
    $this->artisan('media:pdf-pages')->assertFailed();
    $this->artisan('media:pdf-pages', ['--site' => '999999'])->assertFailed();
    $this->artisan('media:pdf-pages', ['--site' => '1', '--apply' => true, '--dry-run' => true])->assertFailed();
});

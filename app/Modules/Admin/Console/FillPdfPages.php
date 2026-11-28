<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\PdfPages;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Fills the page count of PDFs uploaded before pages were counted (new uploads are counted by the
 * CountPdfPages job). One-off, per website.
 *
 * A dry run by default: per website it prints how many PDFs it has (the Trash included), how many
 * already have their pages, how many qpdf could not read and how many are left to count; nothing is
 * read from storage and nothing is written. --apply counts the ones left, in batches by id, each
 * count in the website's own tenant context (qpdf runs outside any transaction), and prints how
 * many were counted and how many could not be.
 *
 * Idempotent: a PDF that has its pages is never counted again, and one qpdf could not read is
 * marked with its storage key and skipped until its file changes (a new key); one that could not be
 * read for another reason (qpdf not installed, storage not answering) is tried again by a later
 * run. One website failing is reported and the rest still run; the command then ends with a
 * failure exit.
 *
 * Lives in Admin, beside media:refs-by-id, because it names websites by their code (the Media module
 * reaches tenancy only through its public surface).
 */
final class FillPdfPages extends Command
{
    /** A PDF still to count: no page count, and no unreadable mark naming its current file. */
    private const string LEFT = "jsonb_typeof(meta->'pages') is distinct from 'number' and (meta->>'".PdfPages::UNREADABLE_KEY."') is distinct from storage_key";

    #[\Override]
    protected $signature = 'media:pdf-pages
        {--site= : The website\'s code or id, or "all"}
        {--dry-run : Only count what is left, write nothing (the default)}
        {--apply : Count the pages and write them}
        {--batch=200 : PDFs read per batch}';

    #[\Override]
    protected $description = 'Fill the page count of PDFs already in the media library (dry run by default)';

    public function handle(Tenancy $tenancy, PdfPages $pages): int
    {
        $apply = $this->option('apply') === true;
        if ($apply && $this->option('dry-run') === true) {
            $this->error('Refused: --dry-run and --apply together. Give one of them.');

            return self::FAILURE;
        }
        $given = trim(Cast::string($this->option('site')));
        if ($given === '') {
            $this->error('Give --site=<code or id> or --site=all.');

            return self::FAILURE;
        }
        $sites = $given === 'all' ? $tenancy->siteIds()->all() : array_filter([$this->site($given)]);
        if ($given !== 'all' && $sites === []) {
            $this->error('No website with that id or code.');

            return self::FAILURE;
        }
        $batch = max(1, min(1000, Cast::int($this->option('batch'), 200)));

        $this->line($apply ? 'Counting the pages of PDFs that have none yet.' : 'Dry run: counting PDFs left without a page count; nothing is read or written.');
        $totals = ['pdfs' => 0, 'known' => 0, 'marked' => 0, 'left' => 0, 'counted' => 0, 'unreadable' => 0];
        $failed = false;
        foreach ($sites as $siteId) {
            try {
                $line = $this->siteTotals($tenancy, $siteId)
                    + ($apply ? $this->fill($tenancy, $pages, $siteId, $batch) : ['counted' => 0, 'unreadable' => 0]);
            } catch (Throwable $e) {
                $failed = true;
                $this->error("Website #{$siteId} failed: {$e->getMessage()}");

                continue;
            }
            foreach ($line as $k => $n) {
                $totals[$k] += $n;
            }
            $this->line("Website #{$siteId}: {$line['pdfs']} PDFs, {$line['known']} with pages, {$line['marked']} unreadable before, {$line['left']} left"
                .($apply ? ", {$line['counted']} counted, {$line['unreadable']} could not be counted" : '').'.');
        }

        $this->line("Total: {$totals['pdfs']} PDFs, {$totals['known']} with pages, {$totals['marked']} unreadable before, {$totals['left']} left"
            .($apply ? ", {$totals['counted']} counted, {$totals['unreadable']} could not be counted" : '').'.');
        if (! $apply) {
            $this->line('Nothing was written. Run again with --apply to count.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{pdfs: int, known: int, marked: int, left: int} */
    private function siteTotals(Tenancy $tenancy, int $siteId): array
    {
        return $tenancy->enter($siteId, function (): array {
            $row = Media::withTrashed()->where('mime', PdfPages::MIME)->toBase()
                ->selectRaw("count(*) as pdfs, count(*) filter (where jsonb_typeof(meta->'pages') = 'number') as known, count(*) filter (where ".self::LEFT.') as left_n')
                ->first();
            $pdfs = Cast::int($row->pdfs ?? 0);
            $known = Cast::int($row->known ?? 0);
            $left = Cast::int($row->left_n ?? 0);

            return ['pdfs' => $pdfs, 'known' => $known, 'marked' => $pdfs - $known - $left, 'left' => $left];
        });
    }

    /** @return array{counted: int, unreadable: int} */
    private function fill(Tenancy $tenancy, PdfPages $pages, int $siteId, int $batch): array
    {
        $done = ['counted' => 0, 'unreadable' => 0];
        $after = 0;
        while (true) {
            /** @var list<int> $ids */
            $ids = $tenancy->enter($siteId, fn (): array => self::left(Media::withTrashed())
                ->where('id', '>', $after)->orderBy('id')->limit($batch)->pluck('id')
                ->map(fn (mixed $id): int => Cast::int($id))->values()->all());
            if ($ids === []) {
                return $done;
            }
            foreach ($ids as $id) {
                $done[$pages->record($siteId, $id) === null ? 'unreadable' : 'counted']++;
            }
            $after = $ids[count($ids) - 1];
        }
    }

    /**
     * PDFs with no page count yet, leaving out those qpdf could not read under their current key.
     *
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    private static function left(Builder $query): Builder
    {
        return $query->where('mime', PdfPages::MIME)->whereRaw(self::LEFT);
    }

    private function site(string $given): ?int
    {
        $byCode = preg_match(MediaKey::CODE_PATTERN, $given) === 1 ? Site::withTrashed()->where('code', $given)->value('id') : null;
        $id = $byCode ?? (ctype_digit($given) ? Site::withTrashed()->whereKey((int) $given)->value('id') : null);

        return $id === null ? null : Cast::int($id);
    }
}

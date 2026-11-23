<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes, website by website, the files whose lead is gone: rows with form_submission_id NULL
 * (the foreign key sets it so on every way a lead is deleted: erased at the visitor's request,
 * emptied from the Trash, past its retention period, with its form). For each such row the object
 * goes first, then the row, so a key is never lost while its object still exists; a row whose
 * object could not be deleted stays for the next run.
 *
 * Chunks of CHUNK rows in id order through form_submission_files_orphan_idx. Each chunk is read in
 * one short transaction in the website's context (Tenancy::enter: SET LOCAL + RLS), its objects are
 * deleted outside any transaction (no database connection is held while the bucket answers), and
 * the rows whose objects are gone are deleted in a second short transaction, still only where the
 * lead is gone (nothing ever sets it back). Re-running deletes nothing twice: deleting an object
 * that is already gone succeeds. A website with no orphan costs one indexed read.
 *
 * One audit row per website that lost anything, with the count and no file name or visitor data. A
 * website that fails, or an object that could not be deleted, is reported; the others go on and the
 * command then fails. Scheduled hourly.
 */
final class SweepLeadFiles extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'forms:sweep-files';

    #[\Override]
    protected $description = 'Delete the stored files (object, then row) of leads that have been deleted';

    public function handle(Tenancy $tenancy): int
    {
        $swept = 0;
        $failedSites = 0;
        $keptObjects = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $site = ['swept' => 0, 'kept' => 0];
            try {
                $this->sweepSite($tenancy, $siteId, $site);
            } catch (Throwable $e) {
                $failedSites++;
                Log::error('forms:sweep-files could not sweep a website.', ['site_id' => $siteId]);
                report($e);
            } finally {
                if ($site['swept'] > 0) {
                    try {
                        $tenancy->enter($siteId, fn () => Audit::record('forms.lead_files.swept', [
                            'meta' => ['count' => $site['swept'], 'scheduled' => true],
                        ]));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                if ($site['kept'] > 0) {
                    Log::warning('forms:sweep-files could not delete some objects; their rows stay for the next run.', ['site_id' => $siteId, 'count' => $site['kept']]);
                }
            }
            $swept += $site['swept'];
            $keptObjects += $site['kept'];
        }

        $this->info("Deleted {$swept} file(s) of deleted leads.");
        if ($failedSites > 0 || $keptObjects > 0) {
            $this->error("{$failedSites} website(s) could not be swept and {$keptObjects} object(s) could not be deleted (see the log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{swept: int, kept: int}  $site  counts so far, kept up to date chunk by chunk
     */
    private function sweepSite(Tenancy $tenancy, int $siteId, array &$site): void
    {
        $disk = LeadFileStorage::disk();
        $after = 0;
        do {
            /** @var list<object{id: int|string, storage_key: string|null}> $rows */
            $rows = $tenancy->enter($siteId, fn (): array => FormSubmissionFile::query()
                ->where('site_id', $siteId)
                ->whereNull('form_submission_id')
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->toBase()
                ->get(['id', 'storage_key'])
                ->all());
            if ($rows === []) {
                return;
            }

            $gone = [];
            foreach ($rows as $row) {
                $id = Cast::int($row->id);
                $after = $id;
                $key = Cast::stringOrNull($row->storage_key);
                if ($key === null || $disk->delete($key)) {
                    $gone[] = $id;
                } else {
                    $site['kept']++;
                }
            }

            if ($gone !== []) {
                $site['swept'] += Cast::int($tenancy->enter($siteId, fn (): int => FormSubmissionFile::query()
                    ->where('site_id', $siteId)
                    ->whereNull('form_submission_id')
                    ->whereIn('id', $gone)
                    ->toBase()
                    ->delete()));
            }
        } while (count($rows) === self::CHUNK);
    }
}

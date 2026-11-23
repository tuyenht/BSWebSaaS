<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\FormSubmissionFile;
use App\Modules\Forms\Support\LeadFileStorage;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Deletes, website by website, the objects on the lead-files disk that no row names: a worker
 * killed after StoreLeadFile wrote an object and before its transaction committed leaves one, and
 * since no row holds its key, neither forms:sweep-files nor an erase can ever reach it, though its
 * body is a visitor's file.
 *
 * For each website (the `sites` table, soft-deleted ones included, in id order) the prefix
 * `leads/{site ulid}/` is listed lazily (the bucket pages its listing; nothing is held whole) in
 * chunks of CHUNK objects. Each chunk asks, in one short read in the website's context
 * (Tenancy::enter: SET LOCAL + RLS), which of its keys a row holds, by the ulid the key ends with
 * (form_submission_files_ulid_unique). An object no row holds is deleted once it is older than
 * GRACE_SECONDS: a younger one may belong to a request whose transaction has not committed yet. The
 * objects are deleted outside any transaction. An object named like a file of another website is
 * not this website's (its row is not visible here), so it goes too: a key always sits under its own
 * website's prefix.
 *
 * One audit row per website that lost anything, with the count only (no key, no file name); a
 * website that fails, or an object that could not be deleted, goes to the log by site id and count,
 * the other websites go on, and the command then fails. Scheduled daily.
 *
 * Sites are never deleted for good on this platform (soft delete only, and no code removes one), so
 * there is no removed website whose whole prefix would need deleting; a future site-removal job
 * must take `leads/{site ulid}/` with it.
 */
final class ReconcileLeadFiles extends Command
{
    private const int CHUNK = 200;

    public const int GRACE_SECONDS = 3600;

    #[\Override]
    protected $signature = 'forms:reconcile-files';

    #[\Override]
    protected $description = 'Delete the lead files on the private disk that no row names (left by a request that never committed)';

    public function handle(Tenancy $tenancy): int
    {
        $deleted = 0;
        $failedSites = 0;
        $keptObjects = 0;

        foreach (DB::table('sites')->select(['id', 'ulid'])->lazyById(self::CHUNK) as $row) {
            $siteId = Cast::int($row->id);
            $site = ['deleted' => 0, 'kept' => 0];
            try {
                $this->reconcileSite($tenancy, $siteId, Cast::string($row->ulid), $site);
            } catch (Throwable $e) {
                $failedSites++;
                Log::error('forms:reconcile-files could not reconcile a website.', ['site_id' => $siteId]);
                report($e);
            } finally {
                if ($site['deleted'] > 0) {
                    try {
                        $tenancy->enter($siteId, fn () => Audit::record('forms.lead_files.reconciled', [
                            'meta' => ['count' => $site['deleted'], 'scheduled' => true],
                        ]));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                if ($site['kept'] > 0) {
                    Log::warning('forms:reconcile-files could not delete some objects no row names; the next run tries again.', ['site_id' => $siteId, 'count' => $site['kept']]);
                }
            }
            $deleted += $site['deleted'];
            $keptObjects += $site['kept'];
        }

        $this->info("Deleted {$deleted} object(s) that no lead file names.");
        if ($failedSites > 0 || $keptObjects > 0) {
            $this->error("{$failedSites} website(s) could not be reconciled and {$keptObjects} object(s) could not be deleted (see the log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{deleted: int, kept: int}  $site  counts so far, kept up to date chunk by chunk
     */
    private function reconcileSite(Tenancy $tenancy, int $siteId, string $siteUlid, array &$site): void
    {
        // a malformed ulid would widen the listing to `leads/` and so to every website's objects
        if (preg_match('/\A[0-9A-Za-z]{26}\z/', $siteUlid) !== 1) {
            throw new LogicException('A website without a well-formed ulid has no lead files prefix.');
        }
        $disk = LeadFileStorage::disk();
        $listing = $disk->getDriver()->listContents('leads/'.$siteUlid.'/', true);
        $before = now()->getTimestamp() - self::GRACE_SECONDS;

        /** @var array<string, int|null> $chunk key => last modified (unix time) */
        $chunk = [];
        foreach ($listing as $item) {
            if (! $item->isFile()) {
                continue;
            }
            $chunk[$item->path()] = $item->lastModified();
            if (count($chunk) === self::CHUNK) {
                $this->reconcileChunk($tenancy, $siteId, $chunk, $before, $site);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->reconcileChunk($tenancy, $siteId, $chunk, $before, $site);
        }
    }

    /**
     * @param  array<string, int|null>  $chunk  key => last modified (unix time)
     * @param  array{deleted: int, kept: int}  $site
     */
    private function reconcileChunk(Tenancy $tenancy, int $siteId, array $chunk, int $before, array &$site): void
    {
        $ulids = [];
        foreach (array_keys($chunk) as $key) {
            $ulid = strtoupper(basename($key));
            if (preg_match('/\A[0-9A-Z]{26}\z/', $ulid) === 1) {
                $ulids[] = $ulid;
            }
        }

        /** @var array<string, true> $named the keys a row of this website holds */
        $named = $ulids === [] ? [] : array_fill_keys(
            array_map(Cast::string(...), $tenancy->enter($siteId, fn (): array => FormSubmissionFile::query()
                ->where('site_id', $siteId)
                ->whereIn('ulid', array_values(array_unique($ulids)))
                ->whereNotNull('storage_key')
                ->toBase()
                ->pluck('storage_key')
                ->all())),
            true,
        );

        $disk = LeadFileStorage::disk();
        foreach ($chunk as $key => $modified) {
            if (isset($named[$key])) {
                continue;
            }
            $modified ??= $disk->lastModified($key);
            if ($modified > $before) {
                continue;
            }
            if ($disk->delete($key)) {
                $site['deleted']++;
            } else {
                $site['kept']++;
            }
        }
    }
}

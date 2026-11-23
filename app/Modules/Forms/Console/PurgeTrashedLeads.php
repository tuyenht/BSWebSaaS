<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadNotices;
use App\Modules\Forms\Support\LeadTrash;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete for good the leads that have sat in the «Khách liên hệ» Trash longer than LeadTrash::DAYS,
 * site by site inside Tenancy::eachSite, so RLS and the model's scope pick each site's own Trash and
 * nothing else. Only rows in the Trash are read (form_submissions_trash_idx); kept leads are never
 * touched.
 *
 * With each lead go its search words (lead_search_terms, ON DELETE CASCADE) and the bell's «new
 * lead» notices of it, which carry the visitor's name or phone (LeadNotices).
 *
 * Chunks of CHUNK rows in id order, each in its own savepoint inside the site's one transaction. Each
 * chunk's SELECT locks its rows, and a savepoint releases no lock: every row picked stays locked
 * until the whole site's purge commits. A «Khôi phục» therefore either commits before the read (the
 * row is not picked) or waits until that site is done and then finds nothing to restore. Re-running
 * deletes nothing twice. The loop runs until a chunk comes back empty: a row a «Khôi phục» changed
 * meanwhile drops out of a chunk, which may then hold fewer than CHUNK rows with more still to go. One audit row per site that lost anything, with the count and no visitor
 * data. Scheduled daily.
 */
final class PurgeTrashedLeads extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'leads:purge-trash';

    #[\Override]
    protected $description = 'Delete for good the leads left in the Trash longer than the retention window';

    public function handle(Tenancy $tenancy): int
    {
        $cutoff = LeadTrash::cutoff(now());
        $purged = 0;

        if ($cutoff !== null) {
            $tenancy->eachSite(function (int $siteId) use ($cutoff, &$purged): void {
                $site = 0;
                do {
                    $done = DB::transaction(function () use ($siteId, $cutoff): int {
                        $chunk = FormSubmission::query()
                            ->where('site_id', $siteId)
                            ->whereNotNull('deleted_at')
                            ->where('deleted_at', '<', $cutoff)
                            ->orderBy('id')
                            ->limit(self::CHUNK)
                            ->lockForUpdate()
                            ->toBase()
                            ->get(['id', 'ulid', 'created_at']);
                        if ($chunk->isEmpty()) {
                            return 0;
                        }
                        LeadNotices::deleteFor($chunk->all());
                        FormSubmission::query()->whereIn('id', $chunk->pluck('id')->map(fn (mixed $id): int => Cast::int($id))->all())
                            ->toBase()->delete();

                        return $chunk->count();
                    });
                    $site += $done;
                } while ($done > 0);

                if ($site > 0) {
                    Audit::record('forms.leads.trash_purged', [
                        'meta' => ['count' => $site, 'scheduled' => true, 'trash_days' => LeadTrash::DAYS],
                    ]);
                }
                $purged += $site;
            });
        }

        $this->info("Purged {$purged} lead(s) left in the Trash over ".LeadTrash::DAYS.' day(s).');

        return self::SUCCESS;
    }
}

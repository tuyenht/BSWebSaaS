<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryTrash;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete for good the posts and pages that have sat in the Trash longer than EntryTrash::days(),
 * site by site inside Tenancy::eachSite, so RLS and the model's scope pick each site's own Trash and
 * nothing else. Only rows already in the Trash are read (onlyTrashed); live content is never touched.
 *
 * Each row goes through the model's forceDelete(), as the Trash's «Xóa vĩnh viễn» does: the foreign
 * keys remove its translations, addresses (routes), category links, versions, change drafts, workflow
 * history, block usages and search words; EntryObserver removes its block-image usage rows
 * (mediables); a redirect keeps its rule and loses only the link back; a child page keeps its place
 * at the top level. The category totals already left the post's category when it went to the Trash.
 *
 * Chunks of CHUNK rows in id order (the order the list's bulk actions lock in, so the two never
 * deadlock), each in its own savepoint inside the site's one transaction. Each chunk's SELECT locks
 * its rows, and a savepoint releases no lock: every row picked stays locked until the whole site's
 * purge commits. A restore therefore either commits before the read (the row is not picked) or waits
 * until that site is done and then finds nothing to restore. Re-running deletes nothing twice. One
 * audit row per site that lost anything. Scheduled daily.
 */
final class PurgeTrashedEntries extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'entries:purge-trash';

    #[\Override]
    protected $description = 'Delete for good the posts and pages left in the Trash longer than the retention window';

    public function handle(Tenancy $tenancy): int
    {
        $days = EntryTrash::days();
        $cutoff = EntryTrash::cutoff(now());
        $purged = 0;

        if ($cutoff !== null) {
            $tenancy->eachSite(function (int $siteId) use ($cutoff, $days, &$purged): void {
                $site = 0;
                do {
                    $done = DB::transaction(function () use ($siteId, $cutoff): int {
                        $chunk = Entry::onlyTrashed()
                            ->where('site_id', $siteId)
                            ->where('deleted_at', '<', $cutoff)
                            ->orderBy('id')
                            ->limit(self::CHUNK)
                            ->lockForUpdate()
                            ->get(['id', 'site_id', 'ulid', 'type', 'parent_id', 'author_id', 'deleted_at']);
                        foreach ($chunk as $entry) {
                            $entry->forceDelete();
                        }

                        return $chunk->count();
                    });
                    $site += $done;
                } while ($done === self::CHUNK);

                if ($site > 0) {
                    Audit::record('entries.entry.purged', [
                        'meta' => ['entries' => $site, 'scheduled' => true, 'trash_days' => $days],
                    ]);
                }
                $purged += $site;
            });
        }

        $this->info("Purged {$purged} post(s) and page(s) left in the Trash over {$days} day(s).");

        return self::SUCCESS;
    }
}

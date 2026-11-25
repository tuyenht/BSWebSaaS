<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\UnpublishExpiredEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes off the website the live posts and pages whose end time («Gỡ khỏi website lúc», expires_at)
 * has come, through UnpublishExpiredEntry. The counterpart of entries:publish-due, in the same shape:
 * per site inside Tenancy::enter (RLS and the model scope pick each site's own rows), every minute, so
 * an item leaves within a minute of its time (its detail page already answers 404 from that moment,
 * RouteResolver). The due rows are found by a partial index on (site_id, expires_at).
 *
 * The due rows are taken in keyset batches, each in its own short transaction. One entry that fails is
 * reported and stays due for the next run; a lock conflict rolls back that batch (Postgres has aborted
 * its transaction) and the site's rest is swept again the next minute.
 */
final class UnpublishDueEntries extends Command
{
    #[\Override]
    protected $signature = 'entries:unpublish-due {--batch=200 : Due rows per batch}';

    #[\Override]
    protected $description = 'Take off the website the posts and pages whose end time has come';

    public function handle(Tenancy $tenancy, UnpublishExpiredEntry $unpublisher): int
    {
        // due rows per batch: each batch is its own short tenant transaction, so its row locks are brief
        $size = max(1, Cast::int($this->option('batch'), 200));
        $counts = [UnpublishExpiredEntry::UNPUBLISHED => 0, UnpublishExpiredEntry::FORCED => 0, UnpublishExpiredEntry::HELD => 0, 'failed' => 0];
        $deferred = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $after = 0;
            do {
                try {
                    // one short transaction per batch, its counts added once it commits: a batch rolled
                    // back by a lock conflict took nothing down, and the site's rest waits for the next run
                    [$batch, $after, $more] = $tenancy->enter($siteId, fn (): array => $this->sweepBatch($unpublisher, $after, $size));
                } catch (Throwable $e) {
                    if (! ConcurrencyError::is($e)) {
                        throw $e;
                    }

                    report($e);
                    $deferred++;

                    continue 2;
                }

                foreach ($batch as $outcome => $n) {
                    $counts[$outcome] += $n;
                }
            } while ($more);
        }

        $this->info("Took {$counts[UnpublishExpiredEntry::UNPUBLISHED]} entr(y/ies) off the website at their end time, {$counts[UnpublishExpiredEntry::FORCED]} more forced off under an approval workflow with no move for it, {$counts[UnpublishExpiredEntry::HELD]} held (the Home page), {$counts['failed']} failed, {$deferred} site(s) left for the next run after a lock conflict.");

        return self::SUCCESS;
    }

    /**
     * The next batch of the site's due rows after id $after (keyset, oldest first), only their keys
     * read: the action locks and re-reads each one.
     *
     * @return array{0: array<string, int>, 1: int, 2: bool} the outcomes, the last id read, whether a full batch was read
     */
    private function sweepBatch(UnpublishExpiredEntry $unpublisher, int $after, int $size): array
    {
        $counts = [UnpublishExpiredEntry::UNPUBLISHED => 0, UnpublishExpiredEntry::FORCED => 0, UnpublishExpiredEntry::HELD => 0, 'failed' => 0];

        $due = Entry::query()
            ->select(['id', 'site_id'])
            ->where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit($size)
            ->get();

        foreach ($due as $entry) {
            try {
                $outcome = $unpublisher->handle($entry);
                if ($outcome !== UnpublishExpiredEntry::NOT_DUE) {
                    $counts[$outcome]++;
                }
            } catch (Throwable $e) {
                // the site's transaction is aborted: roll it back as one (caught per site above)
                if (ConcurrencyError::is($e)) {
                    throw $e;
                }

                // one entry never takes the sweep down with it, nor undoes the ones already taken down
                report($e);
                $counts['failed']++;
            }
        }

        return [$counts, (int) ($due->last()->id ?? $after), $due->count() === $size];
    }
}

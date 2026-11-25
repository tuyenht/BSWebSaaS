<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Actions\UnpublishExpiredProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * Takes off the website the live products whose end time («Gỡ khỏi website lúc», expires_at) has
 * come, through UnpublishExpiredProduct. The same shape as entries:unpublish-due: per site inside
 * Tenancy::enter, every minute (the product page already answers 404 from that moment, ProductPage),
 * due rows found by a partial index on (site_id, expires_at). One product that fails is reported and
 * stays due; a lock conflict leaves that site for the next run.
 */
final class UnpublishDueProducts extends Command
{
    #[\Override]
    protected $signature = 'products:unpublish-due';

    #[\Override]
    protected $description = 'Take off the website the products whose end time has come';

    /** Due rows per batch: each batch is its own short tenant transaction, so its row locks are brief. */
    private const int BATCH = 200;

    public function handle(Tenancy $tenancy, UnpublishExpiredProduct $unpublisher): int
    {
        $done = 0;
        $failed = 0;
        $deferred = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $after = 0;
            do {
                try {
                    // one short transaction per batch, counted once it commits (see the entries sweep)
                    [$batchDone, $batchFailed, $after, $more] = $tenancy->enter($siteId, fn (): array => $this->sweepBatch($unpublisher, $after));
                } catch (Throwable $e) {
                    if (! ConcurrencyError::is($e)) {
                        throw $e;
                    }

                    report($e);
                    $deferred++;

                    continue 2;
                }

                $done += $batchDone;
                $failed += $batchFailed;
            } while ($more);
        }

        $this->info("Took {$done} product(s) off the website at their end time, {$failed} failed, {$deferred} site(s) left for the next run after a lock conflict.");

        return self::SUCCESS;
    }

    /**
     * The next batch of the site's due products after id $after (keyset), only their keys read.
     *
     * @return array{0: int, 1: int, 2: int, 3: bool} taken down, failed, the last id read, whether a full batch was read
     */
    private function sweepBatch(UnpublishExpiredProduct $unpublisher, int $after): array
    {
        $done = 0;
        $failed = 0;

        $due = Product::query()
            ->select(['id', 'site_id'])
            ->where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($due as $product) {
            try {
                if ($unpublisher->handle($product)) {
                    $done++;
                }
            } catch (Throwable $e) {
                if (ConcurrencyError::is($e)) {
                    throw $e;
                }

                report($e);
                $failed++;
            }
        }

        return [$done, $failed, (int) ($due->last()->id ?? $after), $due->count() === self::BATCH];
    }
}

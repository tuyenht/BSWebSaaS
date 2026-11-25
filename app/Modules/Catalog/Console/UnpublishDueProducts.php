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

    public function handle(Tenancy $tenancy, UnpublishExpiredProduct $unpublisher): int
    {
        $done = 0;
        $failed = 0;
        $deferred = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            try {
                [$siteDone, $siteFailed] = $tenancy->enter($siteId, fn (): array => $this->sweepSite($unpublisher));
            } catch (Throwable $e) {
                if (! ConcurrencyError::is($e)) {
                    throw $e;
                }

                report($e);
                $deferred++;

                continue;
            }

            $done += $siteDone;
            $failed += $siteFailed;
        }

        $this->info("Took {$done} product(s) off the website at their end time, {$failed} failed, {$deferred} site(s) left for the next run after a lock conflict.");

        return self::SUCCESS;
    }

    /** @return array{int, int} taken down, failed */
    private function sweepSite(UnpublishExpiredProduct $unpublisher): array
    {
        $done = 0;
        $failed = 0;

        $due = Product::query()
            ->where('status', 'published')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
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

        return [$done, $failed];
    }
}

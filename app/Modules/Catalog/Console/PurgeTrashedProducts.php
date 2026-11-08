<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Actions\ForceDeleteProducts;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductTrash;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Delete for good the products that have sat in the Trash longer than ProductTrash::DAYS days, oldest
 * first, a chunk per transaction, through the same action as the Trash's "Xóa vĩnh viễn". Runs site by
 * site inside Tenancy::eachSite so RLS and the model's scope pick each site's own Trash. Live products
 * are never touched. Scheduled daily.
 */
final class PurgeTrashedProducts extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'products:purge-trash';

    #[\Override]
    protected $description = 'Delete for good the products left in the Trash longer than the retention window';

    public function handle(Tenancy $tenancy, ForceDeleteProducts $delete): int
    {
        $cutoff = now()->subDays(ProductTrash::DAYS);
        $purged = 0;

        $tenancy->eachSite(function (int $siteId) use ($cutoff, $delete, &$purged): void {
            do {
                $chunk = Product::onlyTrashed()
                    ->where('site_id', $siteId)
                    ->where('deleted_at', '<', $cutoff)
                    ->orderBy('deleted_at')->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get(['id', 'site_id', 'ulid', 'deleted_at']);
                $done = $chunk->isEmpty() ? 0 : $delete->handle($chunk, scheduled: true);
                $purged += $done;
            } while ($done === self::CHUNK);
        });

        $this->info("Purged {$purged} product(s) left in the Trash over ".ProductTrash::DAYS.' day(s).');

        return self::SUCCESS;
    }
}

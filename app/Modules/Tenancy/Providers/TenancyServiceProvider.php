<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped → Octane flushes it between operations (defense layer #3, with the
        // explicit FlushTenancyContext listener as backup).
        $this->app->scoped(CurrentSite::class);
    }

    public function boot(): void
    {
        if (config('tenancy.assert', 'strict') === 'off') {
            return;
        }

        // Per-query in-memory guard (no DB round-trip): whenever a site is resolved,
        // every query must run inside a transaction — otherwise SET LOCAL site_id is
        // not active and RLS would see an empty/stale GUC (failure scenario #1).
        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            $current = $this->app->make(CurrentSite::class);

            if ($current->hasId() && $connection->transactionLevel() === 0) {
                throw new RuntimeException('Tenant query executed outside a transaction — SET LOCAL site_id is not active.');
            }
        });
    }
}

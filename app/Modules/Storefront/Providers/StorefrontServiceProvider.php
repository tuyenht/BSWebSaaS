<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Providers;

use App\Modules\Shared\Contracts\EdgeCache;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\EdgeCachePause;
use App\Modules\Storefront\Console\FlushContactClicks;
use App\Modules\Storefront\Console\PruneContactClicks;
use App\Modules\Storefront\EdgeCache\CloudflareEdgeCache;
use App\Modules\Storefront\EdgeCache\EdgeCachePurges;
use App\Modules\Storefront\EdgeCache\NullEdgeCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The edge cache in front of the public pages: which driver purges it, and the hook that purges a
 * site after a committed change to what it shows.
 */
final class StorefrontServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Config only, never in code; without both values nothing is purged (pages age out).
        $this->app->singleton(EdgeCache::class, static function (): EdgeCache {
            $zone = Cast::string(config('services.cloudflare.zone_id'));
            $token = Cast::string(config('services.cloudflare.purge_token'));

            return $zone !== '' && $token !== '' ? new CloudflareEdgeCache($zone, $token) : new NullEdgeCache;
        });

        // Per request or job (Octane-safe): what this operation has marked for a purge.
        $this->app->scoped(EdgeCachePurges::class);
        // Per request or job too: work running inside EdgeCachePause::during() purges nothing.
        $this->app->scoped(EdgeCachePause::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([FlushContactClicks::class, PruneContactClicks::class]);
        }

        // app(), not $this->app: under Octane each request runs in a clone of the booted app.
        DB::listen(static function (QueryExecuted $query): void {
            if (EdgeCachePurges::writesPublicTable($query->sql)) {
                app(EdgeCachePurges::class)->written();
            }
        });
        Event::listen(TransactionRolledBack::class, static function (): void {
            if (app()->resolved(EdgeCachePurges::class)) {
                app(EdgeCachePurges::class)->rolledBack();
            }
        });
    }
}

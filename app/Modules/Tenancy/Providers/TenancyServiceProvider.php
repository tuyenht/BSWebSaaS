<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Shared\Contracts\DomainProvisioner;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Domains\CaddyOnDemandProvisioner;
use App\Modules\Tenancy\Domains\DnsLookup;
use App\Modules\Tenancy\Domains\SystemDnsLookup;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class TenancyServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // Scoped → Octane flushes it between operations (defense layer #3, with the
        // explicit FlushTenancyContext listener as backup).
        $this->app->scoped(CurrentSite::class);

        // SiteLocale memoizes the per-site default for the request; scoped so the memo is
        // flushed between operations and never leaks a stale default across requests.
        $this->app->scoped(SiteLocale::class);

        // PermalinkConfig memoizes the per-site permalink pattern (e.g. pages flat vs nested);
        // scoped for the same reason — the memo must not leak across requests.
        $this->app->scoped(PermalinkConfig::class);

        // SiteTime memoizes the per-site display timezone; scoped so the memo never leaks a
        // stale zone across requests.
        $this->app->scoped(SiteTime::class);

        // Entitlement memoizes a site's module keys for the request (the admin nav and the page
        // both ask); scoped so the memo never outlives the request or the job.
        $this->app->scoped(Entitlement::class);

        // Public DNS, behind an interface so tests answer without the network.
        $this->app->bind(DnsLookup::class, SystemDnsLookup::class);

        // ADR-07: how a customer's domain gets its certificate stays swappable.
        $this->app->bind(DomainProvisioner::class, CaddyOnDemandProvisioner::class);
    }

    public function boot(): void
    {
        $this->warnWhenAdminCredentialsLoaded();

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

    /**
     * In production the superuser password belongs only to the one-off migrate container: code
     * running in the app, the queue or the scheduler must not be able to rewrite the audit log or
     * switch off row-level security. Logged, never thrown, so a misconfigured host keeps serving.
     */
    public function warnWhenAdminCredentialsLoaded(): void
    {
        if (! $this->app->isProduction() || config('tenancy.privileged_ops') === true) {
            return;
        }

        $password = config('database.connections.pgsql_admin.password');
        $url = config('database.connections.pgsql_admin.url');
        $urlPassword = is_string($url) && $url !== '' ? parse_url($url, PHP_URL_PASS) : null;

        if ((is_string($password) && $password !== '') || (is_string($urlPassword) && $urlPassword !== '')) {
            Log::critical('The superuser database password is loaded in a production app process; keep it only in the migrate service (docs/runbooks/prod-deploy.md).');
        }
    }
}

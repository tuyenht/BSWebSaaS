<?php

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\SeedMeasurementSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainName;
use Illuminate\Console\Command;

/**
 * Fill a measurement site (resolved by hostname) with the pages the CI `cwv` job measures. Only for
 * a site on a platform subdomain, never in production; idempotent.
 */
final class SeedMeasurementCommand extends Command
{
    #[\Override]
    protected $signature = 'site:seed-measurement {--host= : Hostname of the measurement site (a platform subdomain)}';

    #[\Override]
    protected $description = 'Seed the speed and accessibility measurement pages into a test site. Idempotent; never in production.';

    public function handle(Tenancy $tenancy, SeedMeasurementSite $action): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: the measurement pages are never seeded in production.');

            return self::FAILURE;
        }

        $host = $this->option('host');
        $host = is_string($host) ? strtolower(trim($host)) : '';
        if (! DomainName::isPlatformSubdomain($host)) {
            $this->error("Refused: '{$host}' is not a subdomain of the platform base domains.");

            return self::FAILURE;
        }

        $resolved = app(SiteResolver::class)->resolve($host);
        if ($resolved === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        $tenancy->enter($resolved->siteId, fn () => $action->handle());

        $this->info("Seeded the measurement pages for {$host}.");

        return self::SUCCESS;
    }
}

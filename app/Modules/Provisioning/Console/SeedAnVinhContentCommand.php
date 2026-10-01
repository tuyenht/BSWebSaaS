<?php

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;

/**
 * Seed An Vinh's project content into its site (resolved by primary hostname), inside the
 * tenant context. Idempotent — safe to re-run after deploy on prod.
 */
final class SeedAnVinhContentCommand extends Command
{
    #[\Override]
    protected $signature = 'anvinh:seed-content {--host=av1.baoson.net : Primary hostname of the An Vinh site}';

    #[\Override]
    protected $description = 'Seed An Vinh project content (du-an category + sectors + projects). Idempotent.';

    public function handle(Tenancy $tenancy, SeedAnVinhContent $action): int
    {
        $host = $this->option('host');
        $host = is_string($host) ? $host : '';

        $resolved = app(SiteResolver::class)->resolve($host);
        if ($resolved === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        $tenancy->enter($resolved->siteId, fn () => $action->handle());

        $this->info("Seeded An Vinh project content for {$host}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\SeedAnVinhContent;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Console\Command;

/**
 * Seed An Vinh's project content into its site (resolved by primary hostname), inside the
 * tenant context. Idempotent — safe to re-run after deploy on prod.
 */
final class SeedAnVinhContentCommand extends Command
{
    protected $signature = 'anvinh:seed-content {--host=av1.baoson.net : Primary hostname of the An Vinh site}';

    protected $description = 'Seed An Vinh project content (du-an category + sectors + projects). Idempotent.';

    public function handle(Tenancy $tenancy, SeedAnVinhContent $action): int
    {
        $host = $this->option('host');
        $host = is_string($host) ? $host : '';

        $domain = Domain::query()->where('hostname', $host)->first();
        if ($domain === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        $tenancy->enter($domain->site_id, fn () => $action->handle());

        $this->info("Seeded An Vinh project content for {$host}.");

        return self::SUCCESS;
    }
}

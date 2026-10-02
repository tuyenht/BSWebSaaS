<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Tenancy\Domains\DomainHealth;
use Illuminate\Console\Command;

/**
 * The daily look at every customer's own domain (design.md §28): marks the ones that stopped
 * pointing at the platform, clears the mark when they come back, stops serving a domain that has
 * been failing for 30 days, and drops the ones nobody pointed here within 7 days. The rules live
 * in Tenancy\Domains\DomainHealth; this runs it and puts each change on the site's audit trail.
 */
final class RecheckDomainsCommand extends Command
{
    #[\Override]
    protected $signature = 'domains:recheck';

    #[\Override]
    protected $description = 'Re-check every customer domain: mark the failing ones, release the lost and the never-pointed.';

    public function handle(DomainHealth $health): int
    {
        $events = $health->sweep();

        foreach ($events as $event) {
            Audit::record('tenancy.domain.'.$event['event'], [
                'site_id' => $event['site_id'],
                'actor_label' => 'system',
                'meta' => ['hostname' => $event['hostname']],
            ]);

            $this->line("{$event['event']}: {$event['hostname']}");
        }

        $this->info(count($events).' change(s).');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Tenancy\Domains\WaitingDomains;
use Illuminate\Console\Command;

/**
 * The 15-minute look at the names still waiting to be pointed or proved (design.md §28), which
 * the Domains screen promises: a name the owner pointed goes live without anyone pressing Check.
 * The rules live in Tenancy\Domains\WaitingDomains; this runs it and puts each name it verified —
 * and each site that lost it to the proved owner — on the sites' audit trails, as a check by hand
 * would. The site that lost a name is told by the admin's listener on DomainNeedsAttention.
 */
final class RecheckWaitingDomainsCommand extends Command
{
    #[\Override]
    protected $signature = 'domains:recheck-waiting {--budget= : stop taking new names after this many seconds}';

    #[\Override]
    protected $description = 'Check again the customer domains still waiting to be pointed or proved, and verify the ones that are.';

    public function handle(WaitingDomains $waiting): int
    {
        $budget = $this->option('budget');
        $verified = $waiting->recheck(is_numeric($budget) ? (int) $budget : null);

        foreach ($verified as $name) {
            Audit::record('tenancy.domain.verified', [
                'site_id' => $name['site_id'],
                'actor_label' => 'system',
                'meta' => ['hostname' => $name['hostname'], 'took_over_from' => count($name['evicted'])],
            ]);

            foreach ($name['evicted'] as $evicted) {
                Audit::record('tenancy.domain.taken_over', [
                    'site_id' => $evicted,
                    'actor_label' => 'system',
                    'meta' => ['hostname' => $name['hostname']],
                ]);
            }

            $this->line("verified: {$name['hostname']}");
        }

        $this->info(count($verified).' verified.');

        return self::SUCCESS;
    }
}

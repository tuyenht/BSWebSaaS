<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Throwable;

/**
 * The frequent look at the names still waiting (design.md §28): the Domains screen tells the owner
 * the platform checks again on its own every 15 minutes, so a name pointed — or proved — and never
 * checked by hand still goes live. It is the owner's own check (CheckDomain), run for them:
 *
 *  - only names that never went live: not verified and with no failing streak — a name still to be
 *    pointed, or one another site holds that waits for its TXT proof. Live, failing and stopped
 *    names are the daily sweep's (DomainHealth);
 *  - the spelling that runs; its other spelling is checked along with it. The redirecting spelling
 *    is looked at on its own only when a check found it waiting for its own proof (the running one
 *    may be live already);
 *  - read by keyset in chunks, one site's transaction per name, and stopped once the time budget
 *    is spent: a slow resolver (the system resolver's own timeout bounds each lookup) never makes
 *    one run reach the next;
 *  - idempotent: a verified name leaves the set; a name still waiting is only looked at again.
 *
 * Without the target's own addresses nothing is checked (a resolver outage is not the customers'
 * fault). One name failing to process never stops the rest. `domains` is a GLOBAL table (D6): read
 * with no tenant context; each check runs inside its site's.
 *
 * Returns the names this run verified, for the caller to audit.
 */
final readonly class WaitingDomains
{
    public const int CHUNK = 100;

    public function __construct(
        private Pointing $pointing,
        private Tenancy $tenancy,
        private CheckDomain $check,
    ) {}

    /**
     * @param  int|null  $budgetSeconds  stop taking new names after this long (config by default)
     * @return list<array{site_id: int, hostname: string, evicted: list<int>}>
     */
    public function recheck(?int $budgetSeconds = null): array
    {
        if (! $this->pointing->targetResolves()) {
            report(new RuntimeException('domains:recheck-waiting skipped — the target host did not resolve.'));

            return [];
        }

        $budget = $budgetSeconds ?? Cast::int(config('tenancy.custom_domains.recheck_budget_seconds'), 600);
        $deadline = microtime(true) + max(0, $budget);
        $verified = [];
        $seen = [];

        Domain::query()
            ->where('kind', 'custom')->whereNull('verified_at')->whereNull('failing_since')
            ->select(['id', 'site_id', 'hostname', 'is_redirect', 'failure_reason'])
            ->chunkById(self::CHUNK, function (Collection $chunk) use (&$verified, &$seen, $deadline): bool {
                foreach ($chunk as $row) {
                    if (microtime(true) >= $deadline) {
                        return false;
                    }

                    // A redirecting spelling rides along with the running one, unless it waits for its own proof.
                    if ($row->is_redirect && $row->failure_reason !== 'needs_proof') {
                        continue;
                    }

                    $key = $row->site_id.'|'.DomainName::pairKey($row->hostname);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    try {
                        $result = $this->tenancy->enter($row->site_id, fn (): DomainCheck => $this->check->handle($row->hostname));
                    } catch (Throwable $e) {
                        report($e);

                        continue;
                    }

                    if ($result->verified()) {
                        $verified[] = ['site_id' => $row->site_id, 'hostname' => $row->hostname, 'evicted' => $result->evictedSiteIds];
                    }

                    if ($result->twin?->verified() === true) {
                        $verified[] = ['site_id' => $row->site_id, 'hostname' => DomainName::sibling($row->hostname), 'evicted' => $result->twin->evictedSiteIds];
                    }
                }

                return true;
            });

        return $verified;
    }
}

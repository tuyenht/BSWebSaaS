<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The frequent look at the names still waiting (design.md §28): the Domains screen tells the owner
 * the platform checks again on its own every 15 minutes, so a name pointed — or proved — and never
 * checked by hand still goes live. It is the owner's own check (CheckDomain), run for them:
 *
 *  - only spellings that never went live: not verified and with no failing streak — a name still
 *    to be pointed, one another site holds that waits for its TXT proof, or the other spelling of
 *    a live name the owner has not pointed yet. Live, failing and stopped names are the daily
 *    sweep's (DomainHealth);
 *  - one check per pair and run: a waiting spelling is checked with its other spelling when that
 *    one waits too (CheckDomain checks both); a spelling whose other one is live is checked alone;
 *  - the DNS lookups (CheckDomain::probe) run before the site's transaction opens; only the write
 *    runs inside it, so no transaction stays open and no row stays locked while DNS answers;
 *  - read by keyset in chunks and stopped once the time budget is spent (a slow resolver never
 *    makes one run reach the next). The next run starts after the last row this one reached and
 *    wraps round to the start, so every waiting name is reached however slow the first ones are;
 *  - idempotent: a verified name leaves the set; a name still waiting is only looked at again.
 *
 * Without the target's own addresses nothing is checked (a resolver outage is not the customers'
 * fault). One name failing to process never stops the rest. `domains` is a GLOBAL table (D6): read
 * with no tenant context; each write runs inside its site's.
 *
 * Returns the names this run verified, for the caller to audit.
 */
final readonly class WaitingDomains
{
    public const int CHUNK = 100;

    /** Where the next run starts: the id of the last waiting row a run reached (0 = the start). */
    public const string RESUME_KEY = 'domains:recheck-waiting:after';

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
        $deadline = CarbonImmutable::now()->addSeconds(max(0, $budget));
        $after = Cast::int(Cache::get(self::RESUME_KEY), 0);
        $state = ['verified' => [], 'seen' => [], 'last' => $after, 'stopped' => false];

        $visit = function (Collection $chunk) use (&$state, $deadline): bool {
            foreach ($chunk as $row) {
                assert($row instanceof Domain);

                if (CarbonImmutable::now()->gte($deadline)) {
                    $state['stopped'] = true;

                    return false;
                }

                $state['last'] = $row->id;
                $key = $row->site_id.'|'.DomainName::pairKey($row->hostname);

                // Both spellings of a pair are checked together: the second one of the run is done.
                if (isset($state['seen'][$key])) {
                    continue;
                }
                $state['seen'][$key] = true;

                foreach ($this->checkOne($row) as $name) {
                    $state['verified'][] = $name;
                }
            }

            return true;
        };

        // From where the last run stopped to the end, then round from the start up to there.
        $this->waiting()->where('id', '>', $after)->chunkById(self::CHUNK, $visit);

        if (! $state['stopped'] && $after > 0) {
            $this->waiting()->where('id', '<=', $after)->chunkById(self::CHUNK, $visit);
        }

        Cache::forever(self::RESUME_KEY, $state['stopped'] ? $state['last'] : 0);

        return $state['verified'];
    }

    /** @return Builder<Domain> */
    private function waiting(): Builder
    {
        return Domain::query()
            ->where('kind', 'custom')->whereNull('verified_at')->whereNull('failing_since')
            ->select(['id', 'site_id', 'hostname']);
    }

    /**
     * Check one waiting spelling (and its other spelling with it) as its owner would.
     *
     * @return list<array{site_id: int, hostname: string, evicted: list<int>}>
     */
    private function checkOne(Domain $row): array
    {
        try {
            $probe = $this->check->probe($row->hostname, $row->site_id);
            $result = $this->tenancy->enter($row->site_id, fn (): DomainCheck => $this->check->apply($probe));
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $verified = [];

        if ($result->verified()) {
            $verified[] = ['site_id' => $row->site_id, 'hostname' => $row->hostname, 'evicted' => $result->evictedSiteIds];
        }

        if ($result->twin?->verified() === true) {
            $verified[] = ['site_id' => $row->site_id, 'hostname' => DomainName::sibling($row->hostname), 'evicted' => $result->twin->evictedSiteIds];
        }

        return $verified;
    }
}

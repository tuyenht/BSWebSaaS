<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The daily look at every customer's own domain (design.md §28). Domains expire and get pointed
 * elsewhere without telling anyone:
 *
 *  - a verified domain that no longer points at the platform is marked failing (since when, and
 *    why); when it points here again the mark is cleared. Nothing is removed for failing;
 *  - after 30 days of failing it stops being verified: it no longer resolves or renews its
 *    certificate, and if it was the primary the platform subdomain takes over. The row stays, so
 *    the owner sees what happened and can check it again;
 *  - a domain that was attached but never pointed here is dropped after 7 days of waiting.
 *
 * Returns what changed, for the caller to audit. `domains` is a GLOBAL table (D6): no tenant
 * context. One domain failing to process never stops the rest.
 */
final readonly class DomainHealth
{
    public const int WAIT_DAYS = 7;

    public const int LOST_AFTER_DAYS = 30;

    public function __construct(
        private Pointing $pointing,
        private SiteResolver $sites,
    ) {}

    /**
     * @return list<array{site_id: int, hostname: string, event: 'failing'|'recovered'|'lost'|'wait_expired'}>
     */
    public function sweep(): array
    {
        $events = [];

        // Without the target's own addresses every domain would look as if it pointed elsewhere:
        // a resolver outage must not mark the whole platform's domains as failing.
        if (! $this->pointing->targetResolves()) {
            report(new RuntimeException('domains:recheck skipped — the target host did not resolve.'));

            return [];
        }

        Domain::query()->where('kind', 'custom')->orderBy('id')->lazyById(200)->each(function (Domain $domain) use (&$events): void {
            try {
                $event = $this->look($domain);
            } catch (Throwable $e) {
                report($e);

                return;
            }

            if ($event !== null) {
                $events[] = ['site_id' => $domain->site_id, 'hostname' => $domain->hostname, 'event' => $event];
            }
        });

        return $events;
    }

    /**
     * @return 'failing'|'recovered'|'lost'|'wait_expired'|null
     */
    private function look(Domain $domain): ?string
    {
        if ($domain->verified_at === null) {
            // A domain that was live and then lost stays, with its reason, for the owner to see
            // and check again; only a domain that never pointed here expires.
            if ($domain->failing_since === null && $domain->created_at !== null && $domain->created_at->lt(now()->subDays(self::WAIT_DAYS))) {
                $domain->delete();

                return 'wait_expired';
            }

            return null;
        }

        $problem = $this->pointing->problem($domain->hostname);

        if ($problem === null) {
            $recovered = $domain->failing_since !== null;
            $domain->forceFill(['checked_at' => now(), 'failing_since' => null, 'failure_reason' => null])->save();

            if ($recovered && $domain->is_primary) {
                $this->sites->forgetSite($domain->site_id);   // the site's other hosts send visitors here again
            }

            return $recovered ? 'recovered' : null;
        }

        $since = $domain->failing_since ?? now();

        if ($since->lt(now()->subDays(self::LOST_AFTER_DAYS))) {
            DB::transaction(function () use ($domain, $problem): void {
                $domain->forceFill([
                    'verified_at' => null, 'ssl_status' => null, 'is_primary' => false,
                    'checked_at' => now(), 'failure_reason' => $problem,
                ])->save();
                PrimaryDomain::fallBackToSubdomain($domain->site_id);
            });
            $this->sites->forget($domain->hostname);
            $this->sites->forgetSite($domain->site_id);

            return 'lost';
        }

        $first = $domain->failing_since === null;
        $domain->forceFill(['checked_at' => now(), 'failing_since' => $since, 'failure_reason' => $problem])->save();

        if ($first && $domain->is_primary) {
            $this->sites->forgetSite($domain->site_id);   // the site's other hosts stop sending visitors here
        }

        return $first ? 'failing' : null;
    }
}

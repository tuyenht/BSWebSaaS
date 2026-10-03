<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use App\Modules\Tenancy\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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
 *  - a domain that was attached but never pointed here is dropped after 7 days of waiting, unless
 *    the other spelling of its name (with or without `www.`) is live on the same site.
 *
 * A week before a failing domain would stop being served it says so once more ('expiring').
 *
 * Returns what changed, for the caller to audit, and announces it (DomainNeedsAttention) for the
 * site's managers — once per site and name, both spellings together. `domains` is a GLOBAL table
 * (D6): no tenant context. One domain failing to process never stops the rest.
 */
final readonly class DomainHealth
{
    public const int WAIT_DAYS = 7;

    public const int LOST_AFTER_DAYS = 30;

    public const int WARN_DAYS_BEFORE_LOST = 7;

    public function __construct(
        private Pointing $pointing,
        private SiteResolver $sites,
    ) {}

    /**
     * @return list<array{site_id: int, hostname: string, event: 'failing'|'expiring'|'recovered'|'lost'|'wait_expired'}>
     */
    public function sweep(): array
    {
        $events = [];
        $notices = [];

        // Without the target's own addresses every domain would look as if it pointed elsewhere:
        // a resolver outage must not mark the whole platform's domains as failing.
        if (! $this->pointing->targetResolves()) {
            report(new RuntimeException('domains:recheck skipped — the target host did not resolve.'));

            return [];
        }

        Domain::query()->where('kind', 'custom')->orderBy('id')->lazyById(200)->each(function (Domain $domain) use (&$events, &$notices): void {
            try {
                $event = $this->look($domain);
            } catch (Throwable $e) {
                report($e);

                return;
            }

            if ($event === null) {
                return;
            }

            $events[] = ['site_id' => $domain->site_id, 'hostname' => $domain->hostname, 'event' => $event];

            // Both spellings of a name usually change together: one notice for the pair.
            $key = $domain->site_id.'|'.DomainName::pairKey($domain->hostname).'|'.$event;
            $notices[$key] ??= [
                'site_id' => $domain->site_id,
                'event' => $event,
                'hostnames' => [],
                'until' => in_array($event, ['failing', 'expiring'], true) && $domain->failing_since !== null
                    ? CarbonImmutable::instance($domain->failing_since)->addDays(self::LOST_AFTER_DAYS)
                    : null,
            ];
            $notices[$key]['hostnames'][] = $domain->hostname;
        });

        foreach ($notices as $notice) {
            event(new DomainNeedsAttention($notice['site_id'], $notice['event'], $notice['hostnames'], $notice['until']));
        }

        return $events;
    }

    /**
     * @return 'failing'|'expiring'|'recovered'|'lost'|'wait_expired'|null
     */
    private function look(Domain $domain): ?string
    {
        if ($domain->verified_at === null) {
            // A domain that was live and then lost stays, with its reason, for the owner to see
            // and check again; only a domain that never pointed here expires — and not while the
            // other spelling of its name is live on the same site: pointing it later is enough.
            if ($domain->failing_since === null && $domain->created_at !== null && $domain->created_at->lt(now()->subDays(self::WAIT_DAYS))
                && ! $this->twinIsLive($domain)) {
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

        if ($first) {
            return 'failing';
        }

        // Once, a week before the end: the key carries the failing streak, so a later streak warns again.
        $warnFrom = now()->subDays(self::LOST_AFTER_DAYS - self::WARN_DAYS_BEFORE_LOST);

        return $since->lte($warnFrom) && Cache::add("tenancy:domain-expiring:{$domain->id}:{$since->getTimestamp()}", true, now()->addDays(self::WARN_DAYS_BEFORE_LOST + 1))
            ? 'expiring'
            : null;
    }

    private function twinIsLive(Domain $domain): bool
    {
        return Domain::query()
            ->where('site_id', $domain->site_id)
            ->where('hostname', DomainName::sibling($domain->hostname))
            ->whereNotNull('verified_at')
            ->exists();
    }
}

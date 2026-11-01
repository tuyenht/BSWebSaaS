<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Domains;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Domains\DomainHealth;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Reads one site's names for its Domains screen (design.md §28) from the columns the domain engine
 * keeps — nothing is stored for the screen:
 *
 *  - stopped (`lost`): not verified, with a failing streak (the daily sweep keeps the streak
 *    when it stops serving a name after a month);
 *  - waiting: not verified, no streak — unless the name is contested: a check found another site
 *    claiming it (`needs_proof`), or another site already serves it. Only that it is held
 *    elsewhere is known here; never which site holds it;
 *  - verified names read their HTTPS state, or their failing streak and the days it has left.
 *
 * `domains` is a GLOBAL table with no row-level security: every read names the site. A site has a
 * handful of names (the plan's limit bounds them), so one capped query reads them all.
 */
final readonly class DomainGroups
{
    /** Far above any plan's limit: the screen never pages, and never reads without bound. */
    public const int MAX_ROWS = 200;

    private const array COLUMNS = [
        'id', 'site_id', 'hostname', 'kind', 'is_primary', 'ssl_status', 'verified_at', 'failing_since',
        'failure_reason', 'pointing_method', 'is_redirect', 'created_at',
    ];

    public function __construct(private SiteTime $time) {}

    public function forSite(int $siteId): DomainOverview
    {
        /** @var Collection<int, Domain> $rows */
        $rows = Domain::query()->where('site_id', $siteId)->orderBy('id')->limit(self::MAX_ROWS)->get(self::COLUMNS);

        $platform = $rows->where('kind', '!=', 'custom');
        $pairs = $rows->where('kind', 'custom')->groupBy(fn (Domain $row): string => DomainName::pairKey($row->hostname));
        $heldElsewhere = $this->heldElsewhere($siteId, $pairs);
        $now = CarbonImmutable::now();

        $groups = [];
        foreach ($platform as $row) {
            $groups[] = new DomainGroup($row->hostname, true, 'active', $row->hostname, null, $row->is_primary);
        }
        foreach ($pairs as $name => $pair) {
            $groups[] = $this->group((string) $name, $pair, $heldElsewhere, $now);
        }

        $primary = $rows->firstWhere('is_primary', true);

        return new DomainOverview(
            $groups,
            $pairs->count(),
            count(array_filter($groups, fn (DomainGroup $group): bool => $group->state === 'lost')),
            $primary?->hostname,
        );
    }

    /**
     * @param  Collection<int, Domain>  $pair
     * @param  array<string, true>  $heldElsewhere
     */
    private function group(string $name, Collection $pair, array $heldElsewhere, CarbonImmutable $now): DomainGroup
    {
        $runs = $pair->firstWhere('is_redirect', false) ?? $pair->first();
        assert($runs instanceof Domain);
        $alt = $pair->first(fn (Domain $row): bool => $row->hostname !== $runs->hostname);

        $until = $runs->failing_since !== null
            ? $this->time->forDisplay(CarbonImmutable::instance($runs->failing_since)->addDays(DomainHealth::LOST_AFTER_DAYS))
            : null;
        $daysLeft = $until !== null ? max(0, (int) ceil($now->diffInSeconds($until, false) / 86400)) : null;
        $state = $this->state($runs, $heldElsewhere, $daysLeft);

        return new DomainGroup(
            name: $name,
            platform: false,
            state: $state,
            runs: $runs->hostname,
            alt: $alt?->hostname,
            primary: $runs->is_primary,
            altOptional: $runs->verified_at !== null && $alt !== null && $alt->verified_at === null,
            daysLeft: in_array($state, ['failing', 'expiring'], true) ? $daysLeft : null,
            removeOn: $state === 'wait' && $runs->created_at !== null
                ? $this->time->forDisplay(CarbonImmutable::instance($runs->created_at)->addDays(DomainHealth::WAIT_DAYS))
                : null,
            until: in_array($state, ['failing', 'expiring'], true) ? $until : null,
            httpsError: $state === 'https_err' ? self::httpsError($runs->failure_reason) : null,
            byNumber: $runs->pointing_method === PointingMethod::ARecord->value,
        );
    }

    /** @param  array<string, true>  $heldElsewhere */
    private function state(Domain $runs, array $heldElsewhere, ?int $daysLeft): string
    {
        if ($runs->verified_at === null) {
            if ($runs->failing_since !== null) {
                return 'lost';
            }

            return $runs->failure_reason === 'needs_proof' || isset($heldElsewhere[$runs->hostname]) ? 'claim' : 'wait';
        }

        if ($runs->failing_since !== null) {
            return $daysLeft !== null && $daysLeft <= DomainHealth::WARN_DAYS_BEFORE_LOST ? 'expiring' : 'failing';
        }

        return match ($runs->ssl_status) {
            'pending' => 'issuing',
            'failed' => 'https_err',
            'active' => 'active',
            default => 'verified',
        };
    }

    /**
     * The waiting names of this site another site already serves — read as a yes or no per
     * name, through the index on verified hostnames.
     *
     * @param  Collection<string, Collection<int, Domain>>  $pairs
     * @return array<string, true>
     */
    private function heldElsewhere(int $siteId, Collection $pairs): array
    {
        $waiting = [];
        foreach ($pairs as $rows) {
            foreach ($rows as $row) {
                if (! $row->is_redirect && $row->verified_at === null && $row->failing_since === null && $row->failure_reason !== 'needs_proof') {
                    $waiting[] = $row->hostname;
                }
            }
        }

        if ($waiting === []) {
            return [];
        }

        $held = Domain::query()
            ->whereIn('hostname', $waiting)->whereNotNull('verified_at')->where('site_id', '!=', $siteId)
            ->pluck('hostname')->all();

        return array_fill_keys(Cast::strings($held), true);
    }

    /** What the certificate step's failure means for the owner; a reason it does not name reads as not pointed. */
    private static function httpsError(?string $reason): string
    {
        return match ($reason) {
            'points_elsewhere' => 'elsewhere',
            'proxy' => 'proxy',
            'caa' => 'caa',
            default => 'notpointed',
        };
    }
}

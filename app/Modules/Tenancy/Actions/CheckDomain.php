<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainCheck;
use App\Modules\Tenancy\Domains\DomainHealth;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\DomainProbe;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Domains\Pointing;
use App\Modules\Tenancy\Domains\PrimaryDomain;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Check a domain attached to the current site and, when it points at the platform, verify it
 * (design.md §28). Verification is what lets the domain be served and issued a certificate.
 *
 *  - Pointing at the platform = every address the domain resolves to is an address of the target
 *    host. That alone verifies a domain nobody else claims.
 *  - A domain another site also claims — waiting there, or live there — is CONTESTED: pointing
 *    here is not enough, because the shared target cannot tell which site the owner meant. The
 *    site that publishes its own TXT record keeps the domain; every other site loses it. That
 *    proof always wins, so the real owner can take a domain back from whoever holds it.
 *  - A domain already verified on this site stays verified while it points here: a newcomer
 *    waiting for the same name does not push the holder back to proving.
 *
 * Both spellings of the name (with and without `www.`) are checked together: the one that runs and
 * the one that only redirects to it. The twin's outcome rides along; it may lag behind (the owner
 * has not created its record yet) without holding the name back.
 *
 * A site that loses the name is announced (DomainNeedsAttention 'taken_over') once the change
 * commits; so is a check that starts or ends a live domain's failing streak ('failing' /
 * 'recovered'), since the daily sweep will then see nothing new.
 *
 * Returns the outcome instead of throwing for DNS states — those are ordinary, the caller shows
 * them. Who may manage a site's domains is the caller's check. The DNS lookups (probe) come first,
 * outside any lock; the writes (apply) run inside the tenant context.
 */
final readonly class CheckDomain
{
    public function __construct(
        private CurrentSite $current,
        private Pointing $pointing,
        private OwnershipProof $proof,
        private SiteResolver $sites,
    ) {}

    /**
     * Check the name for the current site: probe() then apply(), for a caller already inside the
     * site's context (no row is locked while DNS answers).
     *
     * @throws DomainChangeRejectedException invalid · not_found
     */
    public function handle(string $input): DomainCheck
    {
        return $this->apply($this->probe($input, $this->current->id()));
    }

    /**
     * Ask DNS about the name — and its other spelling when apply() may check it too. Opens no
     * transaction and needs no tenant context (`domains` and `sites` are global, D6, and every read
     * names the site), so a caller that enters the site itself runs this first: nothing stays open
     * or locked while a slow nameserver answers.
     *
     * @throws DomainChangeRejectedException invalid · not_found
     */
    public function probe(string $input, int $siteId): DomainProbe
    {
        $host = DomainName::parse($input);
        $twin = DomainName::sibling($host);
        $rows = Domain::query()
            ->where('site_id', $siteId)->where('kind', 'custom')->whereIn('hostname', [$host, $twin])
            ->get(['hostname', 'verified_at', 'failing_since']);
        $mine = $rows->firstWhere('hostname', $host);

        if ($mine === null) {
            throw new DomainChangeRejectedException('not_found');
        }

        $probe = $this->ask($host, $siteId);
        $other = $rows->firstWhere('hostname', $twin);

        // apply() checks the other spelling while it waits, and when this check starts or ends the
        // name's failing streak — look it up in those cases (a lost name may look it up for nothing).
        $streakMayChange = $probe->pointing !== null
            ? $mine->verified_at !== null && $mine->failing_since === null
            : $mine->failing_since !== null;

        return $other !== null && ($other->verified_at === null || $streakMayChange)
            ? $probe->withTwin($this->ask($twin, $siteId))
            : $probe;
    }

    /**
     * Write what probe() found, inside the site's context (each spelling in its own short
     * transaction, its rows locked only for the write).
     *
     * @throws DomainChangeRejectedException not_found
     */
    public function apply(DomainProbe $probe): DomainCheck
    {
        $siteId = $this->current->id();

        if ($probe->siteId !== $siteId) {
            throw new RuntimeException("A domain check gathered for site {$probe->siteId} cannot be written in site {$siteId}.");
        }

        $host = $probe->host;
        $check = $this->write($probe);

        // The other spelling is checked too while it waits — and when this check found the name
        // failing or back, so both spellings move (and are announced) together.
        $twin = DomainName::sibling($host);
        $twinVerifiedAt = Domain::query()
            ->where('site_id', $siteId)->where('hostname', $twin)->where('kind', 'custom')
            ->first(['verified_at']);

        if ($probe->twin !== null && $twinVerifiedAt !== null && ($twinVerifiedAt->verified_at === null || $check->streak !== null)) {
            $check = $check->withTwin($this->write($probe->twin));
        }

        $this->announceTakeovers($check, $host, $twin);
        $this->announceStreaks($siteId, $check, $host, $twin);

        return $check;
    }

    /** The news the daily sweep would have given, had nobody checked first: one notice per change. */
    private function announceStreaks(int $siteId, DomainCheck $check, string $host, string $twin): void
    {
        /** @var array<'started'|'ended', list<string>> $changed */
        $changed = [];
        if ($check->streak !== null) {
            $changed[$check->streak][] = $host;
        }
        $twinStreak = $check->twin !== null ? $check->twin->streak : null;
        if ($twinStreak !== null) {
            $changed[$twinStreak][] = $twin;
        }

        foreach ($changed as $streak => $hostnames) {
            event(new DomainNeedsAttention(
                $siteId,
                $streak === 'started' ? 'failing' : 'recovered',
                $hostnames,
                $streak === 'started' ? CarbonImmutable::now()->addDays(DomainHealth::LOST_AFTER_DAYS) : null,
            ));
        }
    }

    /** Each site that lost the name hears it once, for both spellings it lost. */
    private function announceTakeovers(DomainCheck $check, string $host, string $twin): void
    {
        $lost = [];
        foreach ($check->evictedSiteIds as $siteId) {
            $lost[$siteId][] = $host;
        }
        foreach ($check->twin->evictedSiteIds ?? [] as $siteId) {
            $lost[$siteId][] = $twin;
        }

        foreach ($lost as $siteId => $hostnames) {
            event(new DomainNeedsAttention($siteId, 'taken_over', $hostnames));
        }
    }

    /** One spelling's DNS answers: does it point here, and is this site's TXT record published? */
    private function ask(string $host, int $siteId): DomainProbe
    {
        $pointing = $this->pointing->problem($host);

        return new DomainProbe(
            $siteId,
            $host,
            $this->proof->recordName($host),
            $this->proof->recordValue($siteId, $host),
            $pointing,
            $pointing === null && $this->proof->published($siteId, $host),
        );
    }

    /** @throws DomainChangeRejectedException not_found */
    private function write(DomainProbe $probe): DomainCheck
    {
        $host = $probe->host;
        $siteId = $probe->siteId;
        $txtName = $probe->txtName;
        $txtValue = $probe->txtValue;
        $pointing = $probe->pointing;
        $proved = $probe->proved;

        $check = DB::transaction(function () use ($host, $siteId, $txtName, $txtValue, $pointing, $proved): DomainCheck {
            // Every site's row for this name, locked: two sites checking at once must not both win.
            $rows = Domain::query()->where('hostname', $host)->where('kind', 'custom')->lockForUpdate()->get();
            $mine = $rows->firstWhere('site_id', $siteId);

            if ($mine === null) {
                throw new DomainChangeRejectedException('not_found');
            }

            if ($pointing !== null) {
                // A live domain starts its failing streak here; a lost one keeps the streak that
                // marks it as once live (so the daily sweep never drops it as a never-pointed one).
                $starts = $mine->verified_at !== null && $mine->failing_since === null;
                $mine->forceFill([
                    'checked_at' => now(),
                    'failure_reason' => $pointing,
                    'failing_since' => $starts ? now() : $mine->failing_since,
                ])->save();

                return new DomainCheck($pointing, $txtName, $txtValue, streak: $starts ? 'started' : null);
            }

            $others = $rows->where('site_id', '!==', $siteId);

            if ($mine->verified_at === null && $others->isNotEmpty() && ! $proved) {
                $mine->forceFill(['checked_at' => now(), 'failure_reason' => 'needs_proof'])->save();

                return new DomainCheck('needs_proof', $txtName, $txtValue);
            }

            $evicted = [];
            $ends = $mine->failing_since !== null;

            if ($mine->verified_at === null) {
                foreach ($others as $other) {
                    $other->delete();
                    PrimaryDomain::fallBackToSubdomain($other->site_id);
                    $evicted[] = $other->site_id;
                }
            }

            $mine->forceFill([
                'verified_at' => $mine->verified_at ?? now(),
                'checked_at' => now(),
                'failure_reason' => null,
                'failing_since' => null,
            ])->save();

            return new DomainCheck('verified', $txtName, $txtValue, array_values(array_unique($evicted)), streak: $ends ? 'ended' : null);
        });

        $this->sites->forget($host);

        // A site that lost the domain fell back to another primary: its other hosts must learn it.
        foreach ($check->evictedSiteIds as $evicted) {
            $this->sites->forgetSite($evicted);
        }

        return $check;
    }
}

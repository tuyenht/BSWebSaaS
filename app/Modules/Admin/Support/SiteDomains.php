<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Notifications\DomainHelpRequestNotification;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Actions\ChooseRunningSpelling;
use App\Modules\Tenancy\Actions\EnableHttps;
use App\Modules\Tenancy\Actions\RemoveDomain;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainCheck;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Domains\PointingRecords;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Who may change a site's own domains, and the trail each change leaves (design.md §28) — the one
 * entry point for the site's Domains screen and for the platform console.
 *
 *  - On the site's admin ($site omitted): the member holds `domains.manage` in the current site.
 *    Staff signed in for support can look but never change a domain (design.md v2.33).
 *  - On the platform console ($site given): the staff member holds `platform.sites.manage`.
 *
 * The rules of a domain change itself (names, plan limit, proof of a contested domain, HTTPS
 * before primary) live in the Tenancy actions this calls; a refused change throws their
 * DomainChangeRejectedException and leaves no trail.
 */
final readonly class SiteDomains
{
    public function __construct(
        private Tenancy $tenancy,
        private CurrentSite $current,
        private PlatformAccess $platform,
        private SupportSession $support,
    ) {}

    /**
     * @param  bool  $useWww  run the `www.` spelling; by default the one without `www.` runs
     * @return list<Domain> the rows created — the spelling that runs first
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · reserved · already_attached · limit_reached
     */
    public function attach(User $actor, string $domain, PointingMethod $pointing = PointingMethod::Alias, bool $useWww = false, ?Site $site = null): array
    {
        return $this->within($actor, $site, function (int $siteId) use ($actor, $domain, $pointing, $useWww): array {
            $created = app(AttachDomain::class)->handle($domain, $pointing, $useWww);

            $this->audit($actor, $siteId, 'tenancy.domain.attached', [
                'hostnames' => array_map(fn (Domain $row): string => $row->hostname, $created),
                'runs' => $created[0]->hostname,
                'pointing' => $pointing->value,
            ]);

            return $created;
        });
    }

    /**
     * Does the domain point here? Verifies it when it does. A site that loses a contested domain
     * gets its own trail entry.
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · not_found
     */
    public function check(User $actor, string $domain, ?Site $site = null): DomainCheck
    {
        return $this->within($actor, $site, function (int $siteId) use ($actor, $domain): DomainCheck {
            $check = app(CheckDomain::class)->handle($domain);

            if ($check->twin?->verified() === true) {
                $this->audit($actor, $siteId, 'tenancy.domain.verified', ['hostname' => DomainName::sibling(DomainName::parse($domain)), 'took_over_from' => count($check->twin->evictedSiteIds)]);

                foreach ($check->twin->evictedSiteIds as $evicted) {
                    $this->audit($actor, $evicted, 'tenancy.domain.taken_over', ['hostname' => DomainName::sibling(DomainName::parse($domain))]);
                }
            }

            if ($check->verified()) {
                $host = DomainName::parse($domain);

                $this->audit($actor, $siteId, 'tenancy.domain.verified', [
                    'hostname' => $host,
                    'took_over_from' => count($check->evictedSiteIds),
                ]);

                foreach ($check->evictedSiteIds as $evicted) {
                    $this->audit($actor, $evicted, 'tenancy.domain.taken_over', ['hostname' => $host]);
                }
            }

            return $check;
        });
    }

    /**
     * @return list<string> the names whose certificate was requested now
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · too_many_attempts
     */
    public function enableHttps(User $actor, string $domain, ?Site $site = null): array
    {
        return $this->within($actor, $site, function (int $siteId) use ($actor, $domain): array {
            $requested = app(EnableHttps::class)->handle($domain);

            if ($requested !== []) {
                $this->audit($actor, $siteId, 'tenancy.domain.https_requested', ['hostnames' => $requested]);
            }

            return $requested;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · redirect_only · https_not_on
     */
    public function makePrimary(User $actor, string $domain, ?Site $site = null): Domain
    {
        return $this->within($actor, $site, function (int $siteId) use ($actor, $domain): Domain {
            $row = app(SetPrimaryDomain::class)->handle($domain);

            $this->audit($actor, $siteId, 'tenancy.domain.primary_changed', ['hostname' => $row->hostname]);

            return $row;
        });
    }

    /**
     * Run this spelling of the name (with or without `www.`); the other one redirects to it.
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · https_not_on
     */
    public function chooseRunningSpelling(User $actor, string $domain, ?Site $site = null): Domain
    {
        return $this->within($actor, $site, function (int $siteId) use ($actor, $domain): Domain {
            $row = app(ChooseRunningSpelling::class)->handle($domain);

            $this->audit($actor, $siteId, 'tenancy.domain.spelling_changed', ['runs' => $row->hostname]);

            return $row;
        });
    }

    /**
     * @return list<string> the hostnames removed
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · not_found · platform_subdomain
     */
    public function remove(User $actor, string $domain, ?Site $site = null): array
    {
        return $this->within($actor, $site, function (int $siteId) use ($actor, $domain): array {
            $removed = app(RemoveDomain::class)->handle($domain);

            $this->audit($actor, $siteId, 'tenancy.domain.removed', ['hostnames' => $removed]);

            return $removed;
        });
    }

    /**
     * Ask the support team to point a domain for the site ("Nhờ hỗ trợ trỏ giúp"): a mail to the
     * support mailbox with the rows to add, and a line in the site's trail. A few requests an hour
     * per site; the support team answers by mail.
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException invalid · not_found · too_many_attempts
     */
    public function requestHelp(User $actor, string $domain, ?Site $site = null): void
    {
        $this->within($actor, $site, function (int $siteId) use ($actor, $domain): void {
            $host = DomainName::parse($domain);
            $rows = Domain::query()
                ->where('site_id', $siteId)->where('kind', 'custom')
                ->whereIn('hostname', [$host, DomainName::sibling($host)])
                ->get(['hostname', 'is_redirect']);

            if ($rows->firstWhere('hostname', $host) === null) {
                throw new DomainChangeRejectedException('not_found');
            }

            $limit = Cast::int(config('support.help_requests_per_hour'), 3);
            if (! RateLimiter::attempt('domain-help:'.$siteId, $limit, fn (): bool => true, 3600)) {
                throw new DomainChangeRejectedException('too_many_attempts');
            }

            $runs = $rows->firstWhere('is_redirect', false)->hostname ?? $host;
            $alt = $rows->first(fn (Domain $row): bool => $row->hostname !== $runs)?->hostname;
            $records = array_map(
                fn (array $record): array => ['label' => $record['label'], 'kind' => $record['kind'] === 'ip' ? 'A' : 'CNAME', 'target' => $record['target']],
                PointingRecords::for($runs, $alt),
            );
            $mailbox = Cast::string(config('support.mailbox')) ?: Cast::string(config('mail.from.address'));

            Notification::route('mail', $mailbox)->notify(new DomainHelpRequestNotification(
                Cast::string(Site::query()->whereKey($siteId)->value('name')),
                DomainName::display(DomainName::pairKey($host)),
                Cast::string($actor->name),
                Cast::string($actor->email),
                $records,
            ));

            $this->audit($actor, $siteId, 'tenancy.domain.help_requested', ['hostname' => DomainName::pairKey($host)]);
        });
    }

    /**
     * Authorize, then run the change in the site it is about.
     *
     * @template TReturn
     *
     * @param  Closure(int): TReturn  $change  receives the site id
     * @return TReturn
     *
     * @throws AuthorizationException
     * @throws DomainChangeRejectedException whatever the change itself refuses
     */
    private function within(User $actor, ?Site $site, Closure $change): mixed
    {
        if ($site === null) {
            // Never on someone's behalf: a domain decides where the whole website lives.
            $this->support->forbid();
            Gate::forUser($actor)->authorize('domains.manage');

            return $change($this->current->id());
        }

        if (! $this->platform->isSuperAdmin($actor) && ! $this->platform->can($actor, 'platform.sites.manage')) {
            throw new AuthorizationException;
        }

        $siteId = Cast::int($site->getKey());

        return $this->tenancy->enter($siteId, fn () => $change($siteId));
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(User $actor, int $siteId, string $action, array $meta): void
    {
        Audit::record($action, [
            'site_id' => $siteId,
            'actor_id' => Cast::int($actor->getKey()),
            'actor_label' => $actor->email,
            'meta' => $meta,
        ]);
    }
}

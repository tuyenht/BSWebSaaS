<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Actions;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Jobs\IssueDomainCertificate;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Turn HTTPS on for one of the current site's own domains (design.md §28). HTTPS is a step of its
 * own, after verification — the place a payment gate can sit later:
 *
 *  - only a VERIFIED domain may have HTTPS: the certificate gate refuses any other, and asking the
 *    certificate authority for a name that does not point here would only burn its failure quota;
 *  - the row goes to `pending` — from then on the certificate gate lets the web server request the
 *    certificate — and a queued job makes the first HTTPS request and records `active` or `failed`;
 *  - one attempt per domain every 5 minutes: the certificate authority allows 5 failures an hour;
 *  - a domain whose HTTPS is already on is left alone;
 *  - both spellings of a name (with and without `www.`) get HTTPS from one request.
 *
 * Who may manage a site's domains is the caller's check. Runs inside the tenant context.
 */
final readonly class EnableHttps
{
    private const int RETRY_AFTER_SECONDS = 300;

    public function __construct(
        private CurrentSite $current,
        private SiteResolver $sites,
    ) {}

    /**
     * The name's other spelling (with or without `www.`) gets HTTPS too once it is verified: it
     * needs a certificate to redirect `https://` visitors. It follows quietly — a twin that is not
     * pointed yet, or asked moments ago, never fails the request for the name itself.
     *
     * @return list<string> the names whose certificate was requested now — empty when HTTPS was
     *                      already on for both spellings
     *
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · too_many_attempts
     */
    public function handle(string $input): array
    {
        $host = DomainName::parse($input);
        $siteId = $this->current->id();

        $domain = Domain::query()->where('site_id', $siteId)->where('hostname', $host)->where('kind', 'custom')->lockForUpdate()->first();

        if ($domain === null) {
            throw new DomainChangeRejectedException('not_found');
        }

        if ($domain->verified_at === null) {
            throw new DomainChangeRejectedException('not_verified');
        }

        $twin = Domain::query()
            ->where('site_id', $siteId)->where('hostname', DomainName::sibling($host))->where('kind', 'custom')
            ->whereNotNull('verified_at')
            ->lockForUpdate()->first();

        $requested = [];

        if ($domain->ssl_status !== 'active') {
            if (! $this->request($siteId, $domain)) {
                throw new DomainChangeRejectedException('too_many_attempts');
            }

            $requested[] = $domain->hostname;
        }

        if ($twin !== null && $twin->ssl_status !== 'active' && $this->request($siteId, $twin)) {
            $requested[] = $twin->hostname;
        }

        return $requested;
    }

    /** Ask for the certificate, unless it was asked for moments ago. */
    private function request(int $siteId, Domain $domain): bool
    {
        if (! RateLimiter::attempt('domain-https:'.$domain->hostname, 1, fn (): bool => true, self::RETRY_AFTER_SECONDS)) {
            return false;
        }

        $domain->forceFill(['ssl_status' => 'pending', 'failure_reason' => null])->save();

        // The certificate gate reads the resolver: it must see HTTPS on before the job asks.
        $this->sites->forget($domain->hostname);

        IssueDomainCertificate::dispatch($siteId, $domain->hostname)->afterCommit();

        return true;
    }
}

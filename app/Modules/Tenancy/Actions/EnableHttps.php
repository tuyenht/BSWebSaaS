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
 *  - a domain whose HTTPS is already on is left alone.
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
     * @throws DomainChangeRejectedException invalid · not_found · not_verified · too_many_attempts
     */
    public function handle(string $input): Domain
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

        if ($domain->ssl_status === 'active') {
            return $domain;
        }

        if (! RateLimiter::attempt('domain-https:'.$host, 1, fn (): bool => true, self::RETRY_AFTER_SECONDS)) {
            throw new DomainChangeRejectedException('too_many_attempts');
        }

        $domain->forceFill(['ssl_status' => 'pending', 'failure_reason' => null])->save();

        // The certificate gate reads the resolver: it must see HTTPS on before the job asks.
        $this->sites->forget($host);

        IssueDomainCertificate::dispatch($siteId, $host)->afterCommit();

        return $domain;
    }
}

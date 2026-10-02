<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Shared\Contracts\DomainProvisioner;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gets the certificate for a domain whose owner turned HTTPS on (EnableHttps), off the request:
 * issuing one can take most of a minute. Writes the outcome on the row — `active`, or `failed`
 * with a reason the screens translate — and nothing else changes: a failure never touches the
 * site, which keeps answering on its other domains. One attempt; the owner retries from the screen.
 *
 * `domains` is a GLOBAL table (D6), so this needs no tenant context.
 */
final class IssueDomainCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $siteId, public string $host) {}

    public function handle(DomainProvisioner $provisioner, SiteResolver $sites): void
    {
        $waiting = fn () => Domain::query()
            ->where('site_id', $this->siteId)
            ->where('hostname', $this->host)
            ->whereNotNull('verified_at')
            ->where('ssl_status', 'pending');

        // Removed, taken over or already settled since the request: nothing to do.
        if (! $waiting()->exists()) {
            return;
        }

        $problem = $provisioner->ensureCertificate($this->host);

        $waiting()->update([
            'ssl_status' => $problem === null ? 'active' : 'failed',
            'failure_reason' => $problem,
            'checked_at' => now(),
        ]);

        $sites->forget($this->host);
    }
}

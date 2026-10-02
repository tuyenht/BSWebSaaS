<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Shared\Contracts\DomainProvisioner;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * DomainProvisioner over the web server's on-demand TLS: the server requests a certificate the
 * first time a host is asked for over HTTPS, after its ask-endpoint (/caddy/ask) says the host is
 * a verified domain with HTTPS turned on. So "ensure a certificate" is simply one HTTPS request to
 * the host: it triggers the issuance if there is none, and proves the certificate is valid if
 * there is — the request verifies the certificate like any browser would.
 *
 * Any HTTP answer counts (a redirect to the primary domain, a 404): the TLS handshake is what is
 * being tested, so redirects are not followed. `tls_endpoint` (host:port) sends the request
 * straight to the web server while keeping the customer's host as SNI — for a server that cannot
 * reach its own public address from inside.
 */
final class CaddyOnDemandProvisioner implements DomainProvisioner
{
    #[\Override]
    public function ensureCertificate(string $host): ?string
    {
        $options = ['allow_redirects' => false];
        $endpoint = Cast::string(config('tenancy.custom_domains.tls_endpoint'));

        if ($endpoint !== '') {
            $options['curl'] = [CURLOPT_CONNECT_TO => ["{$host}:443:{$endpoint}"]];
        }

        try {
            Http::withOptions($options)
                ->timeout(max(5, Cast::int(config('tenancy.custom_domains.tls_timeout'), 45)))
                ->get("https://{$host}/");
        } catch (Throwable $e) {
            report($e);

            return 'certificate_not_issued';
        }

        return null;
    }
}

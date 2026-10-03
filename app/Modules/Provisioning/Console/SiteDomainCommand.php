<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Actions\ChooseRunningSpelling;
use App\Modules\Tenancy\Actions\EnableHttps;
use App\Modules\Tenancy\Actions\RemoveDomain;
use App\Modules\Tenancy\Actions\SetPrimaryDomain;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainCheck;
use App\Modules\Tenancy\Domains\DomainName;
use App\Modules\Tenancy\Domains\PointingMethod;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Domain;
use Illuminate\Console\Command;

/**
 * Operator tool for a site's own domains until the Domains screens exist (design.md §28):
 *
 *   site:domain av1.baoson.net                         list the site's domains and their state
 *   site:domain av1.baoson.net attach example.vn       attach example.vn; www.example.vn redirects to it
 *   site:domain av1.baoson.net attach example.vn --www         …the www. spelling runs instead
 *   site:domain av1.baoson.net attach example.vn --a-record   …the customer copied the server IP
 *   site:domain av1.baoson.net run www.example.vn      switch which spelling runs; the other redirects
 *   site:domain av1.baoson.net check example.vn        does it point here? verify it (and its other spelling) if so
 *   site:domain av1.baoson.net https example.vn        turn HTTPS on (the certificate is requested in the background)
 *   site:domain av1.baoson.net primary example.vn      make it the site's primary domain
 *   site:domain av1.baoson.net remove example.vn       remove it (and its www)
 *
 * Every change goes through the Tenancy actions — the single write path — so the name rules, the
 * plan limit and the contested-domain proof hold here as they will on the screens. Every change is
 * audited, on the site that gained or lost the domain.
 */
final class SiteDomainCommand extends Command
{
    #[\Override]
    protected $signature = 'site:domain
        {host : A hostname the site already answers on}
        {action? : attach, check, https, primary, run or remove}
        {domain? : The customer\'s domain}
        {--a-record : The customer pointed the domain with an A record (the server IP) instead of following the target host by name}
        {--www : On attach, run the www. spelling and redirect the bare name to it (default: the bare name runs)}';

    #[\Override]
    protected $description = 'List a site\'s domains; attach, check, secure, promote or remove a customer\'s own domain.';

    public function handle(Tenancy $tenancy): int
    {
        $host = $this->argument('host');
        $action = $this->argument('action');
        $domain = $this->argument('domain');

        if ($action !== null && ! in_array($action, ['attach', 'check', 'https', 'primary', 'run', 'remove'], true)) {
            $this->error('The action is attach, check, https, primary, run or remove.');

            return self::FAILURE;
        }
        if ($action !== null && ($domain === null || $domain === '')) {
            $this->error("Give the domain to {$action}.");

            return self::FAILURE;
        }

        $resolved = app(SiteResolver::class)->resolve($host);
        if ($resolved === null) {
            $this->error("No site found for host '{$host}'.");

            return self::FAILURE;
        }

        return $tenancy->enter($resolved->siteId, function () use ($resolved, $action, $domain): int {
            try {
                // The stored form of what was typed, so the audit trail and the actions agree.
                $name = $action === null ? '' : DomainName::parse((string) $domain);

                match ($action) {
                    'attach' => $this->attach($resolved->siteId, $name),
                    'check' => $this->check($resolved->siteId, $name),
                    'https' => $this->https($resolved->siteId, $name),
                    'primary' => $this->primary($resolved->siteId, $name),
                    'run' => $this->runSpelling($resolved->siteId, $name),
                    'remove' => $this->remove($resolved->siteId, $name),
                    default => null,
                };
            } catch (DomainChangeRejectedException $e) {
                $this->error(match ($e->reason) {
                    'invalid' => 'invalid — that is not a domain name.',
                    'reserved' => 'reserved — that name belongs to the platform.',
                    'already_attached' => 'already_attached — the site already has that domain.',
                    'limit_reached' => 'limit_reached — the site already uses every domain it was granted.',
                    'platform_subdomain' => 'platform_subdomain — the platform subdomain cannot be removed.',
                    'not_verified' => 'not_verified — the domain does not point here yet; run check first.',
                    'redirect_only' => 'redirect_only — this spelling only redirects; run it first (action run), or promote the spelling that runs.',
                    'https_not_on' => 'https_not_on — turn HTTPS on for the domain first, and wait until it is active.',
                    'too_many_attempts' => 'too_many_attempts — HTTPS was requested moments ago; try again in 5 minutes.',
                    default => "{$e->reason} — the site has no such domain.",
                });

                return self::FAILURE;
            }

            $this->report($resolved->siteId);

            return self::SUCCESS;
        });
    }

    private function attach(int $siteId, string $domain): void
    {
        $pointing = $this->option('a-record') ? PointingMethod::ARecord : PointingMethod::Alias;
        $created = app(AttachDomain::class)->handle($domain, $pointing, (bool) $this->option('www'));
        $names = array_map(fn (Domain $row): string => $row->hostname, $created);

        Audit::record('tenancy.domain.attached', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['hostnames' => $names, 'runs' => $names[0], 'pointing' => $pointing->value],
        ]);

        $this->info('Attached, waiting to be pointed at '.Cast::string(config('tenancy.custom_domains.target')).': '.implode(', ', $names).' — '.$names[0].' runs.');
    }

    private function check(int $siteId, string $domain): void
    {
        $check = app(CheckDomain::class)->handle($domain);

        $this->outcome($siteId, $domain, $check);

        if ($check->twin !== null) {
            $this->outcome($siteId, DomainName::sibling($domain), $check->twin);
        }
    }

    private function outcome(int $siteId, string $domain, DomainCheck $check): void
    {
        if (! $check->verified()) {
            $this->warn($domain.': '.match ($check->status) {
                'not_pointing' => 'not_pointing — the domain does not resolve to anything yet.',
                'points_elsewhere' => 'points_elsewhere — the domain resolves, but not to this platform.',
                default => "needs_proof — another site also claims this domain. Publish a TXT record: {$check->txtName} = {$check->txtValue}",
            });

            return;
        }

        Audit::record('tenancy.domain.verified', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['hostname' => $domain, 'took_over_from' => count($check->evictedSiteIds)],
        ]);

        foreach ($check->evictedSiteIds as $evicted) {
            Audit::record('tenancy.domain.taken_over', [
                'site_id' => $evicted,
                'actor_label' => 'console',
                'meta' => ['hostname' => $domain],
            ]);
        }

        $this->info($domain.': Verified: the domain points here and belongs to this site.');
    }

    private function https(int $siteId, string $domain): void
    {
        $requested = app(EnableHttps::class)->handle($domain);

        if ($requested === []) {
            $this->info('HTTPS is already on for this domain (and its other spelling, if verified).');

            return;
        }

        Audit::record('tenancy.domain.https_requested', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['hostnames' => $requested],
        ]);

        $this->info('HTTPS requested for '.implode(', ', $requested).'. The certificate is being issued in the background; list the domains again in a minute.');
    }

    private function runSpelling(int $siteId, string $domain): void
    {
        app(ChooseRunningSpelling::class)->handle($domain);

        Audit::record('tenancy.domain.spelling_changed', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['runs' => $domain],
        ]);

        $this->info($domain.' runs now; '.DomainName::sibling($domain).' redirects to it.');
    }

    private function primary(int $siteId, string $domain): void
    {
        app(SetPrimaryDomain::class)->handle($domain);

        Audit::record('tenancy.domain.primary_changed', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['hostname' => $domain],
        ]);

        $this->info('Primary domain is now '.$domain.'.');
    }

    private function remove(int $siteId, string $domain): void
    {
        $removed = app(RemoveDomain::class)->handle($domain);

        Audit::record('tenancy.domain.removed', [
            'site_id' => $siteId,
            'actor_label' => 'console',
            'meta' => ['hostnames' => $removed],
        ]);

        $this->info('Removed: '.implode(', ', $removed).'. Remind the owner to delete the DNS record.');
    }

    private function report(int $siteId): void
    {
        $rows = Domain::query()->where('site_id', $siteId)->orderBy('id')->get();

        $this->table(
            ['Domain', 'Kind', 'Primary', 'Runs', 'Verified', 'HTTPS', 'Pointed by', 'Problem'],
            $rows->map(fn (Domain $row): array => [
                $row->hostname,
                $row->kind,
                $row->is_primary ? 'yes' : '',
                $row->is_redirect ? '→ '.DomainName::sibling($row->hostname) : 'yes',
                $row->verified_at !== null ? 'yes' : 'waiting',
                $row->ssl_status ?? ($row->kind === 'subdomain' ? 'on' : 'off'),
                $row->pointing_method ?? '',
                $row->failure_reason ?? '',
            ])->all(),
        );
    }
}

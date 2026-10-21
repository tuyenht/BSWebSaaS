<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\HostnameNotPlatformSubdomainException;
use App\Modules\Provisioning\Exceptions\HostnameOwnedByAnotherTenantException;
use App\Modules\Provisioning\Exceptions\SiteLimitReachedException;
use App\Modules\Shared\Support\Cast;
use Illuminate\Console\Command;

/**
 * Provision a site end-to-end (idempotent). The owner password is typed at a secret
 * prompt — never an argument.
 */
final class ProvisionSiteCommand extends Command
{
    #[\Override]
    protected $signature = 'site:provision
        {--name= : Site name}
        {--host= : Platform subdomain, e.g. shop.example.net (attach a customer domain afterwards with site:domain)}
        {--tenant= : Tenant name (defaults to the site name)}
        {--owner-email= : Owner email}
        {--owner-name=Owner : Owner display name}
        {--locale=vi : Default locale}';

    #[\Override]
    protected $description = 'Provision a site end-to-end (idempotent).';

    public function handle(ProvisionSite $action): int
    {
        $name = $this->stringOption('name');
        $host = $this->stringOption('host');
        $ownerEmail = $this->stringOption('owner-email');

        if ($name === '' || $host === '' || $ownerEmail === '') {
            $this->error('--name, --host and --owner-email are required.');

            return self::FAILURE;
        }

        $tenant = $this->stringOption('tenant');
        $secret = $this->secret('Owner password (leave blank to auto-generate)');

        try {
            $site = $action->handle(new ProvisionSiteData(
                tenantName: $tenant !== '' ? $tenant : $name,
                siteName: $name,
                hostname: $host,
                ownerEmail: $ownerEmail,
                ownerName: $this->stringOption('owner-name') ?: 'Owner',
                ownerPassword: is_string($secret) && $secret !== '' ? $secret : null,
                defaultLocale: $this->stringOption('locale') ?: 'vi',
            ));
        } catch (HostnameNotPlatformSubdomainException $e) {
            $this->error(Cast::string(__('provisioning.hostname_not_platform_subdomain', [
                'host' => $e->hostname,
                'base' => Cast::string(config('tenancy.primary_base')),
            ])));

            return self::FAILURE;
        } catch (HostnameOwnedByAnotherTenantException $e) {
            $this->error(Cast::string(__('provisioning.hostname_owned_by_another_tenant', ['host' => $e->hostname])));

            return self::FAILURE;
        } catch (SiteLimitReachedException $e) {
            $this->error(Cast::string(__('provisioning.sites_limit_reached', ['count' => $e->count, 'limit' => $e->limit])));

            return self::FAILURE;
        }

        $this->info("Provisioned site #{$site->id} ({$host}) — status {$site->status}.");

        return self::SUCCESS;
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}

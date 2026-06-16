<?php

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use Illuminate\Console\Command;

/**
 * Provision a site end-to-end (idempotent). The owner password is typed at a secret
 * prompt — never an argument.
 */
final class ProvisionSiteCommand extends Command
{
    protected $signature = 'site:provision
        {--name= : Site name}
        {--host= : Primary hostname (e.g. anvinh.test)}
        {--tenant= : Tenant name (defaults to the site name)}
        {--owner-email= : Owner email}
        {--owner-name=Owner : Owner display name}
        {--locale=vi : Default locale}';

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

        $site = $action->handle(new ProvisionSiteData(
            tenantName: $tenant !== '' ? $tenant : $name,
            siteName: $name,
            hostname: $host,
            ownerEmail: $ownerEmail,
            ownerName: $this->stringOption('owner-name') ?: 'Owner',
            ownerPassword: is_string($secret) && $secret !== '' ? $secret : null,
            defaultLocale: $this->stringOption('locale') ?: 'vi',
        ));

        $this->info("Provisioned site #{$site->id} ({$host}) — status {$site->status}.");

        return self::SUCCESS;
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}

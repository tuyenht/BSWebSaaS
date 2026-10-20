<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Provisioning\Actions\FindTenant;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Provisioning\Exceptions\SiteLimitReachedException;
use App\Modules\Provisioning\Exceptions\TenantSelectionException;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Provision a site end-to-end (idempotent). The owner password is typed at a secret
 * prompt — never an argument.
 *
 * A NEW website names its customer explicitly: `--tenant` (ulid, or exact name) for one that
 * exists, `--new-tenant` to create one — never by default, so a typo or a missing flag cannot mint
 * a customer with a fresh plan quota. A re-run for an existing hostname needs neither: the website
 * stays with its own customer.
 */
final class ProvisionSiteCommand extends Command
{
    #[\Override]
    protected $signature = 'site:provision
        {--name= : Site name}
        {--host= : Primary hostname (e.g. anvinh.test)}
        {--tenant= : Existing customer, by ulid or exact name (required for a new website unless --new-tenant)}
        {--new-tenant= : Create a new customer with this name for the website (prints its ulid)}
        {--owner-email= : Owner email}
        {--owner-name=Owner : Owner display name}
        {--locale=vi : Default locale}';

    #[\Override]
    protected $description = 'Provision a site end-to-end (idempotent).';

    /** `tenants.name` is a varchar(255). */
    private const int TENANT_NAME_MAX = 255;

    public function handle(ProvisionSite $action, FindTenant $find): int
    {
        $name = $this->stringOption('name');
        $rawHost = trim($this->stringOption('host'));
        $ownerEmail = $this->stringOption('owner-email');

        if ($name === '' || $rawHost === '' || $ownerEmail === '') {
            $this->error('--name, --host and --owner-email are required.');

            return self::FAILURE;
        }

        // The spelling SiteResolver matches (lower-case, no trailing dot), so `A1.test.` is the same
        // website as `a1.test` rather than a second one.
        $host = SiteResolver::normalize($rawHost);
        if ($host === null) {
            $this->error(Cast::string(__('provisioning.host_invalid', ['host' => $rawHost])));

            return self::FAILURE;
        }

        try {
            $customer = $this->customer($host, $find);
        } catch (TenantSelectionException $e) {
            return $this->refuse($e);
        }

        $secret = $this->secret('Owner password (leave blank to auto-generate)');

        try {
            $site = $action->handle(new ProvisionSiteData(
                tenantName: $customer['name'],
                tenantId: $customer['id'],
                newTenantName: $customer['new'],
                siteName: $name,
                hostname: $host,
                ownerEmail: $ownerEmail,
                ownerName: $this->stringOption('owner-name') ?: 'Owner',
                ownerPassword: is_string($secret) && $secret !== '' ? $secret : null,
                defaultLocale: $this->stringOption('locale') ?: 'vi',
            ));
        } catch (SiteLimitReachedException $e) {
            $this->error(Cast::string(__('provisioning.sites_limit_reached', ['count' => $e->count, 'limit' => $e->limit])));

            return self::FAILURE;
        } catch (TenantSelectionException $e) {
            return $this->refuse($e);
        }

        $tenantUlid = Cast::string(Tenant::query()->whereKey($site->tenant_id)->value('ulid'));
        if ($customer['new'] !== null) {
            $this->info("Created customer {$tenantUlid} ({$customer['new']}). Use --tenant={$tenantUlid} for their next website.");
        }
        $this->info("Provisioned site #{$site->id} ({$host}) for customer {$tenantUlid} — status {$site->status}.");

        return self::SUCCESS;
    }

    /**
     * Which customer the website goes to, from the flags — before the password prompt, so a wrong
     * choice fails fast. An existing hostname keeps its own customer: a flag naming another one is
     * refused here, and again by ProvisionSite under its lock (the authority; this is the early answer).
     *
     * @return array{id: int|null, name: string, new: string|null}
     */
    private function customer(string $host, FindTenant $find): array
    {
        $reference = trim($this->stringOption('tenant'));
        $newName = trim($this->stringOption('new-tenant'));

        if ($reference !== '' && $newName !== '') {
            throw TenantSelectionException::bothGiven();
        }

        $owner = $this->ownerOf($host);

        if ($newName !== '') {
            if ($owner !== null) {
                throw TenantSelectionException::hostTaken($host, $owner->ulid);
            }
            if (mb_strlen($newName) > self::TENANT_NAME_MAX) {
                throw TenantSelectionException::nameTooLong(self::TENANT_NAME_MAX);
            }

            return ['id' => null, 'name' => $newName, 'new' => $newName];
        }

        if ($reference !== '') {
            $tenant = $find->handle($reference);
            if ($owner !== null && $owner->id !== $tenant->id) {
                throw TenantSelectionException::mismatch($host, $owner->ulid);
            }

            return ['id' => $tenant->id, 'name' => $tenant->name, 'new' => null];
        }

        if ($owner === null) {
            throw TenantSelectionException::required();
        }

        return ['id' => $owner->id, 'name' => $owner->name, 'new' => null];
    }

    /**
     * The customer whose website already runs on $host, if any. Hostname → site only through
     * SiteResolver (design.md §5, §28); a stale miss just asks the operator to name the customer.
     */
    private function ownerOf(string $host): ?Tenant
    {
        $resolved = app(SiteResolver::class)->resolve($host);
        $site = $resolved === null ? null : Site::query()->find($resolved->siteId, ['id', 'tenant_id']);

        return $site === null ? null : Tenant::query()->find($site->tenant_id, ['id', 'ulid', 'name']);
    }

    private function refuse(TenantSelectionException $e): int
    {
        $this->error(Cast::string(__($e->translationKey(), $e->replace)));

        return self::FAILURE;
    }

    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }
}

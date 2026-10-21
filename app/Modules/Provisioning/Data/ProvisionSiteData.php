<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Data;

/**
 * Inputs to provision one site end-to-end. The same DTO drives An Vinh and every
 * future site (this is the SaaS control-plane primitive, not a one-off seeder).
 */
final readonly class ProvisionSiteData
{
    /**
     * @param  list<string>  $locales  enabled locales (the default must be included)
     * @param  list<string>  $modules  entitled module keys
     * @param  array<string, mixed>  $profile  optional site_profiles column values
     * @param  ?int  $tenantId  the existing customer a NEW website goes to (wins over $tenantName)
     * @param  ?string  $newTenantName  create a new customer with this name for a NEW website
     *                                  (wins over both; refused when the hostname already has a website)
     * @param  bool  $allowSameName  with $newTenantName: create it even if a customer already has that name
     *
     * $tenantName alone (find-or-create by name) is kept for seeders and tests: names are not unique,
     * so operator-facing callers pass $tenantId or $newTenantName (see site:provision).
     */
    public function __construct(
        public string $tenantName,
        public string $siteName,
        public string $hostname,
        public string $ownerEmail,
        public string $ownerName,
        public ?string $ownerPassword = null,
        public string $defaultLocale = 'vi',
        public array $locales = ['vi'],
        public array $modules = [],
        public ?int $tenantId = null,
        public array $profile = [],
        public ?string $newTenantName = null,
        public bool $allowSameName = false,
    ) {}

    /** The same inputs under another hostname (every other field carried over by name). */
    public function withHostname(string $hostname): self
    {
        return new self(...['hostname' => $hostname] + get_object_vars($this));
    }
}

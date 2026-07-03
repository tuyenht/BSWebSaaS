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
    ) {}
}

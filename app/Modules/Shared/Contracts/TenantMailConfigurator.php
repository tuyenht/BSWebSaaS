<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

/**
 * Points the live mailer at the current site's effective mail settings before a send. Lives in
 * Shared\Contracts so a feature module (Forms' lead notice) can send tenant mail without depending
 * on the Admin module that owns the settings, secrets and OAuth transport (acyclic boundary).
 */
interface TenantMailConfigurator
{
    /**
     * Apply the site's mail settings (tenant override → platform default). With none configured the
     * log mailer is forced, so a send never reaches another tenant's transport. Returns whether a
     * real transport was configured.
     */
    public function apply(): bool;
}

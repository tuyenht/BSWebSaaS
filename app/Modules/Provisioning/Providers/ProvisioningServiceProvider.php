<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Providers;

use App\Modules\Provisioning\Console\ImportAdminUnitsCommand;
use App\Modules\Provisioning\Console\PlatformPlanCommand;
use App\Modules\Provisioning\Console\PromoteAnVinhProductPostsToCatalogCommand;
use App\Modules\Provisioning\Console\ProvisionSiteCommand;
use App\Modules\Provisioning\Console\RecheckDomainsCommand;
use App\Modules\Provisioning\Console\RecheckWaitingDomainsCommand;
use App\Modules\Provisioning\Console\ReflowSitePermalinksCommand;
use App\Modules\Provisioning\Console\SeedAnVinhContentCommand;
use App\Modules\Provisioning\Console\SiteDomainCommand;
use App\Modules\Provisioning\Console\SiteFeatureCommand;
use App\Modules\Provisioning\Console\SiteLocaleCommand;
use App\Modules\Provisioning\Console\SyncProductRoutesCommand;
use App\Modules\Provisioning\Console\SyncSiteProfilesFromBrandCommand;
use Illuminate\Support\ServiceProvider;

final class ProvisioningServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ProvisionSiteCommand::class,
                SeedAnVinhContentCommand::class,
                PromoteAnVinhProductPostsToCatalogCommand::class,
                ReflowSitePermalinksCommand::class,
                SyncSiteProfilesFromBrandCommand::class,
                ImportAdminUnitsCommand::class,
                SyncProductRoutesCommand::class,
                SiteLocaleCommand::class,
                SiteFeatureCommand::class,
                PlatformPlanCommand::class,
                SiteDomainCommand::class,
                RecheckDomainsCommand::class,
                RecheckWaitingDomainsCommand::class,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Providers;

use App\Modules\Provisioning\Console\PromoteAnVinhProductPostsToCatalogCommand;
use App\Modules\Provisioning\Console\ProvisionSiteCommand;
use App\Modules\Provisioning\Console\ReflowSitePermalinksCommand;
use App\Modules\Provisioning\Console\SeedAnVinhContentCommand;
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
            ]);
        }
    }
}

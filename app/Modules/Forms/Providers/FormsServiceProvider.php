<?php

namespace App\Modules\Forms\Providers;

use App\Modules\Forms\Components\FormComponent;
use App\Modules\Forms\Console\SyncContactFieldsCommand;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Forms module's data-bound page component ('form') into the shared Section Library,
 * so a Page-JSON page can embed a lead-capture form alongside the theme's static blocks (e.g. the
 * contact form + NAP block on /lien-he).
 */
final class FormsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ComponentRegistry::class)->register('form', new FormComponent);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncContactFieldsCommand::class]);
        }
    }
}

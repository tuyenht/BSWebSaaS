<?php

namespace App\Modules\Forms\Providers;

use App\Modules\Forms\Components\FormComponent;
use App\Modules\Forms\Console\PurgeOldLeads;
use App\Modules\Forms\Console\PurgeTrashedLeads;
use App\Modules\Forms\Console\RebuildLeadSearchTermsCommand;
use App\Modules\Forms\Console\ResumeFormDeletesCommand;
use App\Modules\Forms\Console\SyncContactFieldsCommand;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Policies\FormPolicy;
use App\Modules\Forms\Policies\LeadPolicy;
use App\Modules\Theme\Components\ComponentRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Forms module's data-bound page component ('form') into the shared Section Library,
 * so a Page-JSON page can embed a lead-capture form alongside the theme's static blocks (e.g. the
 * contact form + NAP block on /lien-he), the policy every form write on the «Biểu mẫu» screen asks,
 * and the one erasing a lead asks.
 */
final class FormsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(ComponentRegistry::class)->register('form', new FormComponent);
        Gate::policy(Form::class, FormPolicy::class);
        Gate::policy(FormSubmission::class, LeadPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncContactFieldsCommand::class, ResumeFormDeletesCommand::class, RebuildLeadSearchTermsCommand::class, PurgeTrashedLeads::class, PurgeOldLeads::class]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Media\Console\PurgeTrashedMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Observers\MediaObserver;
use App\Modules\Media\Policies\MediaPolicy;
use App\Modules\Media\Support\MediaStorageReconciler;
use App\Modules\Media\Support\PurgedObjects;
use App\Modules\Shared\Contracts\CounterReconciler;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the media authorization policy (defense layer #2) + the force-purge observer. The library UI
 * + upload action all authorize through the policy; the observer cleans the R2 object + attachments
 * when a master is force-deleted. Also registers the trash-retention command (module commands are
 * not auto-discovered like app/Console/Commands), and hands `counters:reconcile` its recount of the
 * library's storage totals.
 */
final class MediaServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->tag([MediaStorageReconciler::class], CounterReconciler::TAG);
        $this->app->scoped(PurgedObjects::class); // a purge's open batch, never shared across requests
    }

    public function boot(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);
        Media::observe(MediaObserver::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PurgeTrashedMedia::class]);
        }
    }
}

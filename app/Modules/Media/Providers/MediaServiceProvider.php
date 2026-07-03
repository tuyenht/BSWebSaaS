<?php

declare(strict_types=1);

namespace App\Modules\Media\Providers;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Observers\MediaObserver;
use App\Modules\Media\Policies\MediaPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the media authorization policy (defense layer #2) + the force-purge observer. The library UI
 * + upload action all authorize through the policy; the observer cleans the R2 object + attachments
 * when a master is force-deleted.
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);
        Media::observe(MediaObserver::class);
    }
}

<?php

namespace App\Modules\Media\Providers;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Policies\MediaPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the media authorization policy (defense layer #2). The library UI + upload action all
 * authorize through it.
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Media::class, MediaPolicy::class);
    }
}

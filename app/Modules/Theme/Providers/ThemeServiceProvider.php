<?php

namespace App\Modules\Theme\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the active theme's Blade views under the `theme::` namespace. GĐ1 ships
 * a single bundled "default" theme; per-site theme selection (sites.theme_id /
 * site_theme) resolves the path here later without touching call sites.
 */
final class ThemeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        View::addNamespace('theme', $this->activeThemePath());
    }

    private function activeThemePath(): string
    {
        return $this->app->basePath('app/Modules/Theme/themes/default');
    }
}

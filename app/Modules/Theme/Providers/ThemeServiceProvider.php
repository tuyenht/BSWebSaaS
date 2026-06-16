<?php

namespace App\Modules\Theme\Providers;

use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SectionComponent;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Section Library (component registry + the theme's static
 * components) and the active theme's Blade views under `theme::`. GĐ1 ships a
 * single bundled "default" theme; modules add data-bound components to the same
 * registry (e.g. CmsServiceProvider registers latest_posts).
 */
final class ThemeServiceProvider extends ServiceProvider
{
    /** Static, content-only components shipped by the theme. */
    private const STATIC_SECTIONS = ['hero', 'richtext', 'features', 'cta'];

    public function register(): void
    {
        $this->app->singleton(ComponentRegistry::class, function (): ComponentRegistry {
            $registry = new ComponentRegistry;

            foreach (self::STATIC_SECTIONS as $type) {
                $registry->register($type, new SectionComponent("theme::sections.{$type}"));
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        View::addNamespace('theme', $this->activeThemePath());
    }

    private function activeThemePath(): string
    {
        return $this->app->basePath('app/Modules/Theme/themes/default');
    }
}

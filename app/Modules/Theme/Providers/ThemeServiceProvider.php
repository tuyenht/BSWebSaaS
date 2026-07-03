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
    /**
     * Static, content-only components shipped by the theme, each with its §E7 prop
     * schema (typed props → drives the editor, repeater, defaults and validation).
     * The keys are the section types; adding one here extends render + edit + validate.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const array SECTION_SCHEMAS = [
        'hero' => [
            'heading' => ['type' => 'text'],
            'subheading' => ['type' => 'text'],
            'image_url' => ['type' => 'url'],
            'cta_label' => ['type' => 'text'],
            'cta_url' => ['type' => 'url'],
            'cta2_label' => ['type' => 'text'],
            'cta2_url' => ['type' => 'url'],
        ],
        'richtext' => [
            'heading' => ['type' => 'text'],
            'paragraphs' => ['type' => 'list', 'of' => ['type' => 'textarea']],
        ],
        'features' => [
            'heading' => ['type' => 'text'],
            'items' => ['type' => 'list', 'of' => [
                'title' => ['type' => 'text'],
                'text' => ['type' => 'textarea'],
            ]],
        ],
        'about' => [
            'heading' => ['type' => 'text'],
            'paragraphs' => ['type' => 'list', 'of' => ['type' => 'textarea']],
            'image_url' => ['type' => 'url'],
            'cta_label' => ['type' => 'text'],
            'cta_url' => ['type' => 'url'],
        ],
        'partners' => [
            'heading' => ['type' => 'text'],
            'logos' => ['type' => 'list', 'of' => [
                'image_url' => ['type' => 'url'],
                'name' => ['type' => 'text'],
                'url' => ['type' => 'url'],
            ]],
        ],
        'cta' => [
            'heading' => ['type' => 'text'],
            'button_label' => ['type' => 'text'],
            'button_url' => ['type' => 'url'],
        ],
        'contact' => [
            'heading' => ['type' => 'text'],
            'address' => ['type' => 'textarea'],
            'phone' => ['type' => 'url'],
            'email' => ['type' => 'url'],
            'map_embed' => ['type' => 'url'],
        ],
    ];

    #[\Override]
    public function register(): void
    {
        $this->app->singleton(ComponentRegistry::class, function (): ComponentRegistry {
            $registry = new ComponentRegistry;

            foreach (self::SECTION_SCHEMAS as $type => $schema) {
                $registry->register($type, new SectionComponent("theme::sections.{$type}", $schema));
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
        // Bundled theme assets live at the repo-root `themes/<name>/` (presentation, not
        // PSR-4 code); the Theme module here is the engine that renders them via `theme::`.
        return $this->app->basePath('themes/default');
    }
}

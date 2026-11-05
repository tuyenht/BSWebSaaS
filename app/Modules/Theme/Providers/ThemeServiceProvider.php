<?php

namespace App\Modules\Theme\Providers;

use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SectionComponent;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Theming\SiteSeo;
use App\Modules\Theme\Theming\StorefrontSettings;
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
     * The keys are the section types; adding one here extends render + edit + validate. A url prop
     * with `action` is a button link (ButtonLink): its value names the prop holding the button's label.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const array SECTION_SCHEMAS = [
        'hero' => [
            'heading' => ['type' => 'text'],
            'subheading' => ['type' => 'text'],
            'image_url' => ['type' => 'image'],
            // A background video from the site's library (a media_id, like an image prop); image_url
            // is then its poster. Played muted and looped, only when the visitor's settings allow it.
            'bg_video' => ['type' => 'video'],
            'cta_label' => ['type' => 'text'],
            'cta_url' => ['type' => 'url', 'action' => 'cta_label'],
            'cta2_label' => ['type' => 'text'],
            'cta2_url' => ['type' => 'url', 'action' => 'cta2_label'],
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
                // A glyph key of theme::partials.icon (unknown → no icon); shown by the top-rule cards.
                'icon' => ['type' => 'text'],
            ]],
            'variant' => ['type' => 'enum', 'options' => SectionProps::FEATURES_VARIANTS, 'default' => 'default'],
            'description' => ['type' => 'textarea'],
        ],
        'about' => [
            'heading' => ['type' => 'text'],
            'paragraphs' => ['type' => 'list', 'of' => ['type' => 'textarea']],
            'image_url' => ['type' => 'image'],
            'cta_label' => ['type' => 'text'],
            'cta_url' => ['type' => 'url', 'action' => 'cta_label'],
        ],
        'partners' => [
            'heading' => ['type' => 'text'],
            'logos' => ['type' => 'list', 'of' => [
                'image_url' => ['type' => 'image'],
                'name' => ['type' => 'text'],
                'url' => ['type' => 'url', 'action' => 'name'],
            ]],
        ],
        'cta' => [
            'heading' => ['type' => 'text'],
            'button_label' => ['type' => 'text'],
            'button_url' => ['type' => 'url', 'action' => 'button_label'],
            'text' => ['type' => 'textarea'],
            'variant' => ['type' => 'enum', 'options' => SectionProps::CTA_VARIANTS, 'default' => 'default'],
        ],
        'contact' => [
            'heading' => ['type' => 'text'],
            'address' => ['type' => 'textarea'],
            'phone' => ['type' => 'url'],
            'email' => ['type' => 'url'],
            'map_embed' => ['type' => 'url'],
            'variant' => ['type' => 'enum', 'options' => SectionProps::CONTACT_VARIANTS, 'default' => 'list'],
            'phone_label' => ['type' => 'text'],
            'email_label' => ['type' => 'text'],
            'address_label' => ['type' => 'text'],
        ],
        // The title band of an inner page: it carries the page's single h1 (the layout skips its
        // own when this block leads the page). Title defaults to the page title; overlay is the
        // darkening over the photo in percent, kept within 55–80 so white text stays readable.
        'page_header' => [
            'title' => ['type' => 'text'],
            'subtitle' => ['type' => 'text'],
            'image_url' => ['type' => 'image'],
            'overlay' => ['type' => 'int', 'default' => 60],
        ],
        // The home slider: up to five photos with a heading and a line each, and the same two buttons
        // on every slide. autoplay is seconds per slide (0 = off, else 5–15); overlay is the darkening
        // over the photos in percent (55–80, so white text stays readable on any picture).
        'hero_slider' => [
            'slides' => ['type' => 'list', 'of' => [
                'image_url' => ['type' => 'image'],
                'heading' => ['type' => 'text'],
                'subheading' => ['type' => 'textarea'],
            ]],
            'cta_label' => ['type' => 'text'],
            'cta_url' => ['type' => 'url', 'action' => 'cta_label'],
            'cta2_label' => ['type' => 'text'],
            'cta2_url' => ['type' => 'url', 'action' => 'cta2_label'],
            'autoplay' => ['type' => 'int', 'default' => 0],
            'overlay' => ['type' => 'int', 'default' => 60],
        ],
        // A click-to-load video: nothing loads from YouTube/Vimeo until the visitor presses play.
        // `video` makes ValidPageJson accept only an address VideoEmbed recognises (blank = unset);
        // the poster is the site's own picture, never the host's thumbnail.
        'video' => [
            'heading' => ['type' => 'text'],
            'url' => ['type' => 'url', 'video' => true],
            'poster' => ['type' => 'image'],
        ],
        // Static blocks drawn by the page builder design. Every list carries its own `max` (and `min`
        // where a block needs a few items to make sense, counting only the items whose `min_of` field is
        // filled); ValidPageJson refuses a list outside them.
        'quick_links' => [
            'heading' => ['type' => 'text'],
            'items' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'icon' => ['type' => 'enum', 'options' => SectionProps::QUICK_LINK_ICONS, 'default' => 'link'],
                'label' => ['type' => 'text'],
                'url' => ['type' => 'url', 'action' => 'label'],
            ]],
            'variant' => ['type' => 'enum', 'options' => SectionProps::QUICK_LINK_VARIANTS, 'default' => 'grid-2x2'],
        ],
        'gallery' => [
            'heading' => ['type' => 'text'],
            'images' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'image' => ['type' => 'image'],
                'caption' => ['type' => 'text'],
            ]],
            'columns' => ['type' => 'enum', 'options' => SectionProps::GALLERY_COLUMNS, 'default' => '3'],
        ],
        'faq' => [
            'heading' => ['type' => 'text'],
            'items' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'q' => ['type' => 'text'],
                'a' => ['type' => 'textarea'],
            ]],
            'columns' => ['type' => 'enum', 'options' => SectionProps::FAQ_COLUMNS, 'default' => '1'],
        ],
        'testimonial' => [
            'heading' => ['type' => 'text'],
            'items' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'quote' => ['type' => 'textarea'],
                'name' => ['type' => 'text'],
                'role' => ['type' => 'text'],
                'avatar' => ['type' => 'image'],
            ]],
            'variant' => ['type' => 'enum', 'options' => SectionProps::TESTIMONIAL_VARIANTS, 'default' => 'grid-2'],
        ],
        'team' => [
            'heading' => ['type' => 'text'],
            'members' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'name' => ['type' => 'text'],
                'role' => ['type' => 'text'],
                'photo' => ['type' => 'image'],
            ]],
            'variant' => ['type' => 'enum', 'options' => SectionProps::TEAM_VARIANTS, 'default' => 'grid-3'],
        ],
        'process' => [
            'heading' => ['type' => 'text'],
            'steps' => ['type' => 'list', 'min' => 3, 'min_of' => 'title', 'max' => 6, 'of' => [
                'title' => ['type' => 'text'],
                'text' => ['type' => 'textarea'],
            ]],
        ],
        // price is whole dong; empty or 0 shows "Liên hệ". features is one benefit per line.
        'pricing' => [
            'heading' => ['type' => 'text'],
            'plans' => ['type' => 'list', 'min' => 2, 'min_of' => 'name', 'max' => 4, 'of' => [
                'name' => ['type' => 'text'],
                'price' => ['type' => 'int', 'min' => 0, 'max' => 999_999_999_999],
                'unit' => ['type' => 'text'],
                'features' => ['type' => 'textarea', 'max_lines' => SectionProps::MAX_ITEMS],
                'cta_label' => ['type' => 'text'],
                'cta_url' => ['type' => 'url', 'action' => 'cta_label'],
            ]],
        ],
        // value is a whole number counted up on scroll; the suffix ("+", "%") is trimmed to 8 characters at render.
        'stats' => [
            'heading' => ['type' => 'text'],
            'items' => ['type' => 'list', 'max' => SectionProps::STATS_MAX_ITEMS, 'of' => [
                'value' => ['type' => 'int', 'min' => 0, 'max' => 999_999_999_999],
                'suffix' => ['type' => 'text'],
                'label' => ['type' => 'text'],
            ]],
        ],
        // Formatted text ("Văn bản có định dạng", ADR-19): `html` is the one prop type printed without
        // escaping, allowed on this block only. Every write runs it through RichTextSanitizer::cms (the
        // body casts); images name a library picture by id and get their address when the page is drawn.
        'richtext_html' => [
            'html' => ['type' => 'html'],
        ],
    ];

    /** Types that render and validate but stay out of the builder's add list until their editor field exists. */
    private const array UNLISTED = ['richtext_html'];

    #[\Override]
    public function register(): void
    {
        $this->app->singleton(ComponentRegistry::class, function (): ComponentRegistry {
            $registry = new ComponentRegistry;

            foreach (self::SECTION_SCHEMAS as $type => $schema) {
                $registry->register($type, new SectionComponent("theme::sections.{$type}", $schema), ! in_array($type, self::UNLISTED, true));
            }

            return $registry;
        });

        // Per request (Octane-safe): one settings read shared by the tokens, header and footer.
        $this->app->scoped(StorefrontSettings::class);
        $this->app->scoped(SiteSeo::class);
    }

    public function boot(): void
    {
        View::addNamespace('theme', $this->activeThemePath());

        // A setting written in this request is read fresh by the next render in it.
        // app(), not $this->app: under Octane each request runs in a clone of the booted app.
        // The search settings are also kept in the shared cache (SiteSeo): a write drops that copy.
        $forget = function (SiteSetting $setting): void {
            if (app()->resolved(StorefrontSettings::class)) {
                app(StorefrontSettings::class)->forget();
            }
            if ($setting->group_name === 'seo') {
                app(SiteSeo::class)->forget((int) $setting->site_id);
            }
        };
        SiteSetting::saved($forget);
        SiteSetting::deleted($forget);
    }

    private function activeThemePath(): string
    {
        // Bundled theme assets live at the repo-root `themes/<name>/` (presentation, not
        // PSR-4 code); the Theme module here is the engine that renders them via `theme::`.
        return $this->app->basePath('themes/default');
    }
}

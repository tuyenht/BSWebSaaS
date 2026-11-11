<?php

namespace App\Modules\Theme\Providers;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\BranchesComponent;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SectionComponent;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Jobs\CheckAiCrawlers;
use App\Modules\Theme\Theming\HeadingDecor;
use App\Modules\Theme\Theming\SiteSeo;
use App\Modules\Theme\Theming\StorefrontSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
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
            'variant' => ['type' => 'enum', 'options' => SectionProps::ABOUT_VARIANTS, 'default' => 'default'],
            'image_focus' => ['type' => 'enum', 'options' => SectionProps::ABOUT_IMAGE_FOCUS, 'default' => 'center'],
            // Shortcut tiles under the text, typed buttons like the quick links.
            'links' => ['type' => 'list', 'max' => SectionProps::ABOUT_LINKS_MAX_ITEMS, 'of' => [
                'icon' => ['type' => 'enum', 'options' => SectionProps::QUICK_LINK_ICONS, 'default' => 'link'],
                'label' => ['type' => 'text'],
                'url' => ['type' => 'url', 'action' => 'label'],
            ]],
        ],
        'partners' => [
            'heading' => ['type' => 'text'],
            // The line under the heading in the shared section head.
            'description' => ['type' => 'textarea'],
            // A small line under the logos (where the logos come from, which ones are names for now).
            'note' => ['type' => 'textarea'],
            'logos' => ['type' => 'list', 'of' => [
                'image_url' => ['type' => 'image'],
                'name' => ['type' => 'text'],
                'url' => ['type' => 'url', 'action' => 'name'],
            ]],
        ] + SectionProps::LIST_DISPLAY,
        'cta' => [
            'heading' => ['type' => 'text'],
            'button_label' => ['type' => 'text'],
            'button_url' => ['type' => 'url', 'action' => 'button_label'],
            'text' => ['type' => 'textarea'],
            'variant' => ['type' => 'enum', 'options' => SectionProps::CTA_VARIANTS, 'default' => 'default'],
            'button2_label' => ['type' => 'text'],
            'button2_url' => ['type' => 'url', 'action' => 'button2_label'],
            // The card look's second button: an outline (default) or the Zalo brand blue.
            'button2_tone' => ['type' => 'enum', 'options' => SectionProps::CTA_BUTTON2_TONES, 'default' => 'outline'],
            // Up to three stats beside the text, with the default look only (the rounded and split bands ignore them).
            'side' => ['type' => 'enum', 'options' => SectionProps::CTA_SIDES, 'default' => 'none'],
            'side_items' => ['type' => 'list', 'max' => SectionProps::CTA_SIDE_MAX_ITEMS, 'of' => SectionProps::STAT_ITEM],
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
            // A phone number or a zalo.me link (the floating Zalo button's own rule); the cell is titled "Zalo".
            'zalo' => ['type' => 'url', 'zalo' => true],
            'zalo_label' => ['type' => 'text'],
            // A small line under the address of the card look, e.g. the working days and hours.
            'hours' => ['type' => 'text'],
        ],
        // Block-slot blocks (LayoutSlots): they draw data of the page around them, so only a slot holds them.
        'recent_posts' => [
            'heading' => ['type' => 'text'],
        ],
        // `source`: the categories of the page's branch (the default) or the posts of its own category (the post
        // being read marked); `counts` = show each category's post count after its name.
        'category_list' => [
            'heading' => ['type' => 'text'],
            'source' => ['type' => 'enum', 'options' => SectionProps::CATEGORY_LIST_SOURCES, 'default' => 'categories'],
            'counts' => ['type' => 'enum', 'options' => SectionProps::CATEGORY_LIST_COUNTS, 'default' => 'hide'],
        ],
        // A document card of a side column: a small badge (PDF), a title and a line under it, linking to the
        // page or file the editor names.
        'file_card' => [
            'badge' => ['type' => 'text'],
            'title' => ['type' => 'text'],
            'text' => ['type' => 'text'],
            'url' => ['type' => 'url', 'action' => 'title'],
        ],
        // The consult box of a side column: a small line, a heading, a line of text and one button; the hotline and
        // the email come from the site's contact settings.
        'consult' => [
            'eyebrow' => ['type' => 'text'],
            'heading' => ['type' => 'text'],
            'text' => ['type' => 'textarea'],
            'button_label' => ['type' => 'text'],
            'button_url' => ['type' => 'url', 'action' => 'button_label'],
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
            // The player's width, and a line under it (a source or a licence).
            'player_width' => ['type' => 'enum', 'options' => SectionProps::VIDEO_WIDTHS, 'default' => 'narrow'],
            'caption' => ['type' => 'text'],
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
            'caption_style' => ['type' => 'enum', 'options' => SectionProps::GALLERY_CAPTIONS, 'default' => 'below'],
        ],
        'faq' => [
            'heading' => ['type' => 'text'],
            'items' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'q' => ['type' => 'text'],
                'a' => ['type' => 'textarea'],
            ]],
            'columns' => ['type' => 'enum', 'options' => SectionProps::FAQ_COLUMNS, 'default' => '1'],
            // "open": every question is a heading with its answer under it (as an article prints them), no folding.
            'style' => ['type' => 'enum', 'options' => SectionProps::FAQ_STYLES, 'default' => 'accordion'],
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
        ] + SectionProps::LIST_DISPLAY,
        'team' => [
            'heading' => ['type' => 'text'],
            'members' => ['type' => 'list', 'max' => SectionProps::MAX_ITEMS, 'of' => [
                'name' => ['type' => 'text'],
                'role' => ['type' => 'text'],
                'photo' => ['type' => 'image'],
            ]],
            'variant' => ['type' => 'enum', 'options' => SectionProps::TEAM_VARIANTS, 'default' => 'grid-3'],
        ] + SectionProps::LIST_DISPLAY,
        'process' => [
            'heading' => ['type' => 'text'],
            // The line under the heading in the shared section head.
            'description' => ['type' => 'textarea'],
            'variant' => ['type' => 'enum', 'options' => SectionProps::PROCESS_VARIANTS, 'default' => 'default'],
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
            'items' => ['type' => 'list', 'max' => SectionProps::STATS_MAX_ITEMS, 'of' => SectionProps::STAT_ITEM],
            'variant' => ['type' => 'enum', 'options' => SectionProps::STATS_VARIANTS, 'default' => 'default'],
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

    /** Types only a block slot may hold (a page body is refused them): they draw data of the page they sit on. */
    private const array SLOT_ONLY = ['recent_posts', 'category_list', 'consult', 'file_card'];

    /** Types whose view draws a section heading (theme::partials.sf-section-head): they take `heading_decor`. */
    private const array HEADED = ['features', 'partners', 'process', 'pricing', 'testimonial', 'stats', 'gallery', 'quick_links', 'team', 'video', 'faq'];

    #[\Override]
    public function register(): void
    {
        $this->app->singleton(ComponentRegistry::class, function (): ComponentRegistry {
            $registry = new ComponentRegistry;

            foreach (self::SECTION_SCHEMAS as $type => $schema) {
                $registry->register($type, new SectionComponent("theme::sections.{$type}", $schema), ! in_array($type, self::UNLISTED, true) && ! in_array($type, self::SLOT_ONLY, true), in_array($type, self::HEADED, true), in_array($type, self::SLOT_ONLY, true));
            }

            // The branches list reads the site's settings, so it is data-bound like the product grid.
            $registry->register('branches', new BranchesComponent, headed: true);

            return $registry;
        });

        // Per request (Octane-safe): one settings read shared by the tokens, header and footer.
        $this->app->scoped(StorefrontSettings::class);
        $this->app->scoped(HeadingDecor::class);
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

        // The AI crawler check: at most this many runs a minute across the whole platform (each is
        // six outbound requests); the rest wait in the queue.
        RateLimiter::for(CheckAiCrawlers::LIMITER, static fn (): Limit => Limit::perMinute(max(1, Cast::int(config('seo.ai_crawlers.platform_per_minute'), 30))));
    }

    private function activeThemePath(): string
    {
        // Bundled theme assets live at the repo-root `themes/<name>/` (presentation, not
        // PSR-4 code); the Theme module here is the engine that renders them via `theme::`.
        return $this->app->basePath('themes/default');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Per-site customization over the SHARED master template (design.md §19): every site
 * uses the same theme Blade, but overrides design tokens (colors/fonts/…) to fit its
 * brand. No customer code runs (ADR-04) — overrides are CONFIG, applied as CSS
 * variables. Tokens are sanitized (CSS context) to prevent style breakout.
 *
 * Octane-safe: reads the current site's settings per call (RLS-scoped); no site
 * context → safe defaults only.
 */
final class ThemeResolver
{
    /**
     * storefront.css paints the page as color-mix(in srgb, var(--surface) 97%, var(--ink)); brand
     * text sits both on cards (`surface`) and straight on that page (breadcrumb, contact details).
     * ThemeResolverTest pins the stylesheet to this share.
     */
    public const float PAGE_SURFACE_SHARE = 0.97;

    /** @var array<string, string> */
    private const array DEFAULTS = [
        // #3b82f6, not the more obvious #0a66c2: storefront.css puts `ink` as TEXT on
        // top of `primary` (buttons, CTAs) as well as using it for headings — a site
        // that never configures a theme gets this pairing for free, and #0a66c2 only
        // clears ~3.3:1 against #111111 (WCAG2AA needs 4.5:1 for normal text; axe
        // caught it live in the `cwv` CI job, 23/09). #3b82f6 clears ~5.1:1.
        'primary' => '#3b82f6',
        'surface' => '#ffffff',
        'text' => '#111111',
        'font' => 'system-ui, sans-serif',
        // Storefront seeds (docs/notes/section-style-system.md §1 — persisted per site, so
        // renaming one means migrating every tenant's data; CSS roles derive from these): `ink` is the
        // near-black used for headings, dark-surface fills (header/footer), and text
        // on top of `primary` — one brand decision, reused across those three
        // contexts. `body` is long-form copy, deliberately softer than `ink` for
        // readability over a full article.
        'ink' => '#111111',
        'body' => '#333333',
    ];

    /**
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $setting = SiteSetting::query()
            ->where('group_name', 'theme')
            ->where('key', 'tokens')
            ->first();

        $custom = is_array($setting?->value) ? $setting->value : [];
        $tokens = self::DEFAULTS;

        foreach ($custom as $key => $value) {
            if (is_string($value) && $this->safeKey((string) $key) && $this->safeValue($value)) {
                $tokens[(string) $key] = $value;
            }
        }

        return $tokens;
    }

    /**
     * What the storefront prints into :root — the seeds plus the roles CSS cannot derive by itself
     * (docs/notes/section-style-system.md §1). `primary-text` is the brand color made readable
     * (WCAG AA) on `surface` AND on the page background, for brand-colored TEXT on light surfaces;
     * fills and borders keep `primary` exactly as the site chose it. Always derived, never read
     * from settings; a seed that is not a hex color (a name, rgb()) cannot be measured, so the role
     * falls back to the ink color. `brand-50` … `brand-900` are the brand palette (BrandPalette).
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $variables = $this->tokens();
        $page = ReadableColor::mix($variables['surface'], $variables['ink'], self::PAGE_SURFACE_SHARE);
        $backgrounds = $page === null ? [$variables['surface']] : [$variables['surface'], $page];
        $variables['primary-text'] = ReadableColor::onAll($variables['primary'], $backgrounds) ?? 'var(--ink)';
        // Brand color made readable as TEXT on the dark surface (--ink): footer NAP link + hover,
        // sub-menu, secondary hero CTA. Lightened (hue kept) until >=4.5 on --ink — a light brand
        // (amber) is unchanged, a dark brand is lifted. Companion to --primary-text (light bg).
        $variables['primary-on-ink'] = ReadableColor::onAll($variables['primary'], [$variables['ink']]) ?? 'var(--surface)';

        // Tints, fills and dark accents of the brand (--brand-50 … --brand-900), derived — like the
        // roles above, a same-named key in settings never wins. A non-hex brand yields no palette.
        foreach (array_keys($variables) as $key) {
            if (str_starts_with($key, 'brand-')) {
                unset($variables[$key]);
            }
        }
        foreach (BrandPalette::from($variables['primary']) ?? [] as $step => $color) {
            $variables["brand-{$step}"] = $color;
        }

        return $variables;
    }

    private function safeKey(string $key): bool
    {
        return preg_match('/^[a-z0-9-]{1,40}$/', $key) === 1;
    }

    private function safeValue(string $value): bool
    {
        // CSS-value-safe characters only: no ; { } : < > " — prevents style breakout.
        return preg_match('/^[a-zA-Z0-9#,.%()\s_-]{1,80}$/', $value) === 1;
    }
}

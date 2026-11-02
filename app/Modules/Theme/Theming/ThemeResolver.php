<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * Per-site customization over the SHARED master template (design.md §19): every site
 * uses the same theme Blade, but overrides design tokens (colors/fonts/…) to fit its
 * brand. No customer code runs (ADR-04) — overrides are CONFIG, applied as CSS
 * variables. Tokens are sanitized (CSS context) to prevent style breakout.
 *
 * Octane-safe: reads the current site's settings through the request-scoped
 * StorefrontSettings (RLS-scoped, one query shared with the header and footer); no site
 * context → safe defaults only.
 */
final readonly class ThemeResolver
{
    /**
     * storefront.css paints the page as color-mix(in srgb, var(--color-surface) 97%, var(--color-dark)); brand
     * text sits both on cards (`surface`) and straight on that page (breadcrumb, contact details).
     * ThemeResolverTest pins the stylesheet to this share.
     */
    public const float PAGE_SURFACE_SHARE = 0.97;

    /** Every spacing step is a multiple of this many px, whatever the density. */
    public const int SPACE_GRID = 4;

    /** WCAG 2.2 non-text contrast: a button fill against the band it sits on. */
    private const float FILL_CONTRAST = 3.0;

    /** Body text on the dark set: the surface colour, this share of it, the rest the ink. */
    private const float ON_DARK_SHARE = 0.78;

    /** The spacing scale at normal density, in px. */
    private const array SPACE_STEPS = ['s' => 8, 'm' => 16, 'l' => 32, 'xl' => 64];

    /** Density seed values and the factor each applies to SPACE_STEPS. */
    private const array DENSITY = ['compact' => 0.85, 'normal' => 1.0, 'airy' => 1.2];

    /** @var array<string, string> */
    private const array DEFAULTS = [
        // #3b82f6, not the more obvious #0a66c2: storefront.css puts `ink` as TEXT on
        // top of `primary` (buttons, CTAs) as well as using it for headings — a site
        // that never configures a theme gets this pairing for free, and #0a66c2 only
        // clears ~3.3:1 against #111111 (WCAG2AA needs 4.5:1 for normal text; axe
        // caught it live in the `cwv` CI job, 23/09). #3b82f6 clears ~5.1:1.
        'primary' => '#3b82f6',
        'surface' => '#ffffff',
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

    public function __construct(private StorefrontSettings $settings) {}

    /**
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $stored = $this->settings->get('theme', 'tokens');
        $custom = is_array($stored) ? $stored : [];
        $tokens = self::DEFAULTS;

        foreach ($custom as $key => $value) {
            if (self::acceptsToken((string) $key, $value)) {
                $tokens[(string) $key] = $value;
            }
        }

        return $tokens;
    }

    /**
     * What the storefront prints into :root — the seeds plus the roles CSS cannot derive by itself
     * (docs/notes/section-style-system.md §1). `primary-text` is the brand color made readable
     * (WCAG AA) on `surface` AND on the page background, for brand-colored TEXT on light surfaces;
     * fills and borders keep `primary` exactly as the site chose it. The page background is the
     * site's `page` token when it sets one, else the surface/ink mix storefront.css paints by
     * default. Always derived, never read from settings; a seed that is not a hex color (a name,
     * rgb()) cannot be measured, so the role falls back to the ink color. `brand-50` … `brand-900` are the brand palette (BrandPalette).
     *
     * The standard names (docs/notes/design-tokens.md §7) are printed next to the old ones that
     * storefront.css still reads, with the same values: `color-primary`, `color-primary-text`,
     * `color-primary-on-dark`, `color-surface`, `color-text`, `color-text-strong`, `color-dark`,
     * `font-body`, `color-brand-*`, the scales (`space-*`, `radius-*`, `shadow-*`, `gradient-*`, `motion-*`), plus
     * `color-on-primary` — white or the ink color on the brand fill, whichever reads better. A new
     * name never points back at an old one, so a fallback is the color itself, not `var(--ink)`.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $variables = $this->tokens();
        $page = $variables['page'] ?? ReadableColor::mix($variables['surface'], $variables['ink'], self::PAGE_SURFACE_SHARE);
        $backgrounds = $page === null ? [$variables['surface']] : [$variables['surface'], $page];
        $primaryText = ReadableColor::onAll($variables['primary'], $backgrounds);
        $variables['primary-text'] = $primaryText ?? 'var(--ink)';
        // Brand color made readable as TEXT on the dark surface (--ink): footer NAP link + hover,
        // sub-menu, secondary hero CTA. Lightened (hue kept) until >=4.5 on --ink — a light brand
        // (amber) is unchanged, a dark brand is lifted. Companion to --primary-text (light bg).
        $primaryOnDark = ReadableColor::onAll($variables['primary'], [$variables['ink']]);
        $variables['primary-on-ink'] = $primaryOnDark ?? 'var(--surface)';

        $variables['color-primary'] = $variables['primary'];
        $variables['color-on-primary'] = $this->textOnFill($variables['primary'], $variables['ink']);
        $variables['color-primary-text'] = $primaryText ?? $variables['ink'];
        $variables['color-primary-on-dark'] = $primaryOnDark ?? $variables['surface'];
        $variables['color-surface'] = $variables['surface'];
        $variables['color-text'] = $variables['body'];
        $variables['color-text-strong'] = $variables['ink'];
        $variables['color-dark'] = $variables['ink'];
        $variables['font-body'] = $variables['font'];

        // Tints, fills and dark accents of the brand (--brand-50 … --brand-900), derived — like the
        // roles above, a same-named key in settings never wins. A non-hex brand yields no palette.
        // The colour-set roles (sch-*) are derived too: a stored one would recolour every block.
        foreach (array_keys($variables) as $key) {
            if (str_starts_with($key, 'brand-') || str_starts_with($key, 'color-brand-') || str_starts_with($key, 'sch-')) {
                unset($variables[$key]);
            }
        }
        foreach (BrandPalette::from($variables['primary']) ?? [] as $step => $color) {
            $variables["brand-{$step}"] = $color;
            $variables["color-brand-{$step}"] = $color;
        }

        return array_merge($variables, $this->scales($variables['density'] ?? 'normal'), $this->schemes($variables));
    }

    /**
     * The theme scales (docs/notes/section-style-system.md §1), derived: spacing × density, radius, shadow,
     * gradient and motion. Like the roles above, a same-named key in settings never wins (they are merged over the stored values). Spacing is
     * rounded to the 4px grid after the density factor; an unknown density means normal. Gradients and
     * shadows are built from the roles with color-mix(), so they work for a brand that is not hex.
     *
     * @return array<string, string>
     */
    private function scales(string $density): array
    {
        $unit = self::DENSITY[$density] ?? self::DENSITY['normal'];
        $scales = ['space-unit' => rtrim(rtrim(number_format($unit, 2, '.', ''), '0'), '.')];
        foreach (self::SPACE_STEPS as $step => $px) {
            $scales["space-{$step}"] = (int) (round($px * $unit / self::SPACE_GRID) * self::SPACE_GRID).'px';
        }

        return $scales + [
            'radius-soft' => '16px',
            'radius-round' => '9999px',
            'shadow-soft' => '0 16px 36px -18px color-mix(in srgb, var(--color-dark) 35%, transparent)',
            'shadow-medium' => '0 6px 16px color-mix(in srgb, var(--color-dark) 16%, transparent)',
            'gradient-1' => 'linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, var(--color-dark)))',
            'gradient-2' => 'linear-gradient(135deg, var(--color-primary), color-mix(in srgb, var(--color-primary) 55%, white))',
            'gradient-3' => 'linear-gradient(135deg, var(--color-dark), var(--color-primary))',
            'motion-duration' => '250ms',
            'motion-distance' => '24px',
        ];
    }

    /**
     * The colour sets a section can pick (StyleProps::SCHEMES; "Bộ màu" in the editor), as roles
     * `sch-{set}-{role}`: bg, head, body, link, pri-bg / pri-fg (the main button), sec-fg (the
     * outlined button). Text is fitted to 4.5:1 on its background and the button fill to 3:1
     * (ReadableColor), so any brand reads well; a fill no text reaches 4.5:1 on (a mid-tone brand
     * on the brand set or a button) is shifted until it does. `light` is the page itself and uses the roles
     * above, so it prints nothing. A seed that is not hex cannot be measured and is used as it is.
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function schemes(array $variables): array
    {
        $ink = $variables['ink'];
        $primary = $variables['primary'];
        $white = '#ffffff';
        $fit = static fn (string $color, string $background, float $minimum = ReadableColor::AA_NORMAL_TEXT): string => ReadableColor::on($color, $background, $minimum) ?? $color;

        $soft = $variables['brand-50'] ?? $variables['surface'];
        [$brand, $onBrand] = $this->fillWithText($primary, $ink);
        $brandButton = (ReadableColor::ratio($white, $brand) ?? 0.0) >= self::FILL_CONTRAST ? $white : $ink;
        $darkBody = ReadableColor::mix($variables['surface'], $ink, self::ON_DARK_SHARE) ?? $variables['surface'];

        $sets = [
            'soft' => [
                'bg' => $soft,
                'head' => $fit($ink, $soft),
                'body' => $fit($variables['body'], $soft),
                'link' => $fit($primary, $soft),
                'pri-bg' => $fit($primary, $soft, self::FILL_CONTRAST),
            ],
            'brand' => [
                'bg' => $brand,
                'head' => $onBrand,
                'body' => $onBrand,
                'link' => $onBrand,
                'pri-bg' => $brandButton,
                'pri-fg' => $brandButton === $white ? $fit($brand, $white) : $this->textOnFill($ink, $ink),
            ],
            'dark' => [
                'bg' => $ink,
                'head' => $fit($variables['surface'], $ink),
                'body' => $fit($darkBody, $ink),
                'link' => $fit($primary, $ink),
                'pri-bg' => $fit($primary, $ink, self::FILL_CONTRAST),
            ],
        ];

        $roles = [];
        foreach ($sets as $set => $role) {
            if (! isset($role['pri-fg'])) {
                [$role['pri-bg'], $role['pri-fg']] = $this->fillWithText($role['pri-bg'], $ink, $role['bg']);
            }
            $role['sec-fg'] = $role['head'];
            foreach ($role as $name => $value) {
                $roles["sch-{$set}-{$name}"] = $value;
            }
        }

        return $roles;
    }

    /**
     * A fill and the text on it (textOnFill) at 4.5:1: when neither white nor the ink reaches it,
     * the fill is moved away from the text (darker under white, lighter under the ink), hue kept.
     * A button fill also keeps 3:1 on its $band: when moving it one way loses that, the other text
     * (and so the other way) is used.
     *
     * @return array{0: string, 1: string}
     */
    private function fillWithText(string $fill, string $ink, ?string $band = null): array
    {
        $text = $this->textOnFill($fill, $ink);
        $first = [ReadableColor::on($fill, $text) ?? $fill, $text];
        if ($band === null || (ReadableColor::ratio($first[0], $band) ?? self::FILL_CONTRAST) >= self::FILL_CONTRAST) {
            return $first;
        }

        $other = $text === '#ffffff' ? $ink : '#ffffff';
        $second = [ReadableColor::on($fill, $other) ?? $fill, $other];

        return (ReadableColor::ratio($second[0], $band) ?? 0.0) >= self::FILL_CONTRAST ? $second : $first;
    }

    /**
     * White or the ink color as text on the brand fill — whichever contrasts more (a tie keeps the
     * ink, the pairing storefront.css has always used). A seed that cannot be measured (not hex)
     * keeps the ink unless white is the only one that can be measured.
     */
    private function textOnFill(string $fill, string $ink): string
    {
        $white = ReadableColor::ratio('#ffffff', $fill);
        $dark = ReadableColor::ratio($ink, $fill);

        return $white !== null && ($dark === null || $white > $dark) ? '#ffffff' : $ink;
    }

    /**
     * Whether a stored token survives tokens() — the one rule, so a writer (the content-pack loader)
     * can refuse a value the storefront would silently drop. Keys are `[a-z0-9-]`; values hold
     * CSS-value-safe characters only: no ; { } : < > " — prevents style breakout.
     *
     * @phpstan-assert-if-true string $value
     */
    public static function acceptsToken(string $key, mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-z0-9-]{1,40}$/', $key) === 1
            && preg_match('/^[a-zA-Z0-9#,.%()\s_-]{1,80}$/', $value) === 1;
    }
}

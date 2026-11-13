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

    /** The tinted band: the surface with this share, the rest ink (storefront.css `.sf-band--tint`). */
    public const float TINT_SURFACE_SHARE = 0.94;

    /** Secondary text starts as the body color with this share, the rest white. */
    public const float SECONDARY_TEXT_SHARE = 0.8;

    /** Every spacing step is a multiple of this many px, whatever the density. */
    public const int SPACE_GRID = 4;

    /** WCAG 2.2 non-text contrast: a button fill against the band it sits on. */
    private const float FILL_CONTRAST = 3.0;

    /** Body text on the dark set: the surface colour, this share of it, the rest the ink. */
    private const float ON_DARK_SHARE = 0.78;

    /** A fill on hover: this share of it, the rest black or white (away from its text). */
    private const float HOVER_SHARE = 0.85;

    /** Below this contrast against its resting fill a hover colour is not seen as a change. */
    private const float HOVER_VISIBLE = 1.1;

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

    public function __construct(private StorefrontSettings $settings, private HeadingDecor $headingDecor) {}

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
     * `color-on-primary` — white on the brand fill when it reads 4.5:1, otherwise a deep same-hue
     * shade that does (the ink when none can be derived), and `color-primary-hover` — the fill a hovered brand button
     * takes, moved away from that text so it reads at least as well. A new
     * name never points back at an old one, so a fallback is the color itself, not `var(--ink)`.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        // HeadingDecor is registered scoped: this puts the instance the tokens just used in its place, so the headings drawn next share its settings read and are dropped with the request.
        app()->instance(HeadingDecor::class, $this->headingDecor);
        $variables = $this->tokens();
        $page = $variables['page'] ?? ReadableColor::mix($variables['surface'], $variables['ink'], self::PAGE_SURFACE_SHARE);
        $backgrounds = $page === null ? [$variables['surface']] : [$variables['surface'], $page];
        $primaryText = ReadableColor::onAll($variables['primary'], $backgrounds);
        $variables['primary-text'] = $primaryText ?? 'var(--ink)';
        // Brand color made readable as TEXT on the dark surfaces (--ink and the footer's raised card):
        // footer links + hover, factory card area and map hover, sub-menu, secondary hero
        // CTA. Lightened (hue kept) until >=4.5 on each — a light brand (amber) is unchanged, a dark brand
        // is lifted. Companion to --primary-text (light bg).
        $primaryOnDark = ReadableColor::onAll($variables['primary'], $this->darkSurfaces($variables));
        $variables['primary-on-ink'] = $primaryOnDark ?? 'var(--surface)';

        $variables['color-primary'] = $variables['primary'];
        $variables['color-on-primary'] = $this->textOnBrand($variables['primary'], $variables['ink']);
        $variables['color-primary-hover'] = $this->hoverFill($variables['primary'], $variables['color-on-primary']);
        $variables['color-primary-text'] = $primaryText ?? $variables['ink'];
        $variables['color-primary-on-dark'] = $primaryOnDark ?? $variables['surface'];
        $variables['color-surface'] = $variables['surface'];
        $variables['color-text'] = $variables['body'];
        // Secondary text (lead paragraphs, dates, captions, counts): the body color a fifth lighter,
        // darkened back (hue kept) until it reads 4.5:1 on the surface, the page and the tinted band.
        // A light body color (#4b5563) lightened a fifth reads only 4.3:1 on the page.
        $variables['color-text-secondary'] = $this->secondaryText($variables, $backgrounds);
        $variables['color-text-strong'] = $variables['ink'];
        $variables['color-dark'] = $variables['ink'];
        $variables['font-body'] = $variables['font'];

        // Tints, fills and dark accents of the brand (--brand-50 … --brand-900), derived — like the
        // roles above, a same-named key in settings never wins. A non-hex brand yields no palette.
        // The colour-set roles (sch-*) are derived too: a stored one would recolour every block.
        foreach (array_keys($variables) as $key) {
            if (str_starts_with($key, 'brand-') || str_starts_with($key, 'color-brand-') || str_starts_with($key, 'sch-') || str_starts_with($key, 'sf-decor-')) {
                unset($variables[$key]);
            }
        }
        foreach (BrandPalette::from($variables['primary']) ?? [] as $step => $color) {
            $variables["brand-{$step}"] = $color;
            $variables["color-brand-{$step}"] = $color;
        }

        // The heading decoration's own variables (colour, mask picture) come from its setting, never from a token.
        return array_merge($variables, $this->scales($variables['density'] ?? 'normal'), $this->schemes($variables), $this->headingDecor->variables());
    }

    /**
     * The secondary text color: the body color mixed a fifth toward white (what the stylesheet used to
     * paint), made readable on every light background a block sits on. The body color itself when it
     * is not a hex color (the stylesheet cannot be checked then).
     *
     * @param  array<string, string>  $variables
     * @param  list<string>  $backgrounds  the surface and the page
     */
    private function secondaryText(array $variables, array $backgrounds): string
    {
        $tint = $variables['color-surface-tint'] ?? ReadableColor::mix($variables['surface'], $variables['ink'], self::TINT_SURFACE_SHARE);
        $lighter = ReadableColor::mix($variables['body'], '#ffffff', self::SECONDARY_TEXT_SHARE);
        if ($lighter === null || $tint === null) {
            return $variables['body'];
        }

        return ReadableColor::onAll($lighter, [...$backgrounds, $tint]) ?? $variables['body'];
    }

    /**
     * The dark fills brand-colored text sits on: --ink and the footer's raised card (`color-dark-raised`,
     * else what storefront.css falls back to: 88% ink + white). A site token that is not a hex color is
     * left out: the stylesheet's own fallback is not used then, and the contrast of a value it cannot
     * read is unknown.
     *
     * @param  array<string, string>  $variables
     * @return list<string>
     */
    private function darkSurfaces(array $variables): array
    {
        $ink = $variables['ink'];
        $surfaces = [$ink];
        foreach (['color-dark-raised' => 0.88] as $token => $inkShare) {
            $surface = $variables[$token] ?? ReadableColor::mix($ink, '#ffffff', $inkShare);
            if ($surface !== null && ReadableColor::ratio($surface, $surface) !== null) {
                $surfaces[] = $surface;
            }
        }

        return $surfaces;
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
     * Whether the brand colour set ("Thương hiệu") carries white text, i.e. reads as a dark band — the
     * same fill/text pairing schemes() prints, so what sits on the band (the hero video's control)
     * takes the look that matches it. No query beyond the memoized settings.
     */
    public function brandSetIsDark(): bool
    {
        $tokens = $this->tokens();

        return $this->fillWithText($tokens['primary'], $tokens['ink'])[1] === '#ffffff';
    }

    /**
     * The colour sets a section can pick (StyleProps::SCHEMES; "Bộ màu" in the editor), as roles
     * `sch-{set}-{role}`: bg, head, body, link, pri-bg / pri-fg / pri-hover (the main button), sec-fg (the
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
            $role['pri-hover'] = $this->hoverFill($role['pri-bg'], $role['pri-fg']);
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
     * Text on the brand fill as the site chose it (the fill is never moved): white when it reads
     * 4.5:1, otherwise a deep same-hue shade that does (an amber fill gets a deep brown), and
     * the ink (moved toward black when needed) only when no shade can be measured or reaches it.
     */
    private function textOnBrand(string $fill, string $ink): string
    {
        if ((ReadableColor::ratio('#ffffff', $fill) ?? 0.0) >= ReadableColor::AA_NORMAL_TEXT) {
            return '#ffffff';
        }

        $shade = ReadableColor::darkShade($fill);
        if ($shade !== null) {
            return $shade;
        }

        $text = $this->textOnFill($fill, $ink);
        if ((ReadableColor::ratio($text, $fill) ?? ReadableColor::AA_NORMAL_TEXT) >= ReadableColor::AA_NORMAL_TEXT) {
            return $text;
        }

        return ReadableColor::on($ink, $fill) ?? $text;
    }

    /**
     * A button fill on hover: moved toward black under lighter text, toward white under darker text, so
     * the text reads at least as well as at rest. A fill already at that end (a white button) moves
     * the other way instead, only as far as its text keeps 4.5:1 (a faint tint, or none). A fill that is not hex keeps the
     * darker mix the stylesheet always painted.
     */
    private function hoverFill(string $fill, string $text): string
    {
        // Text darker than its fill (contrast with black is a luminance order) → the fill lightens.
        $darkText = (ReadableColor::ratio($text, '#000000') ?? 0.0) < (ReadableColor::ratio($fill, '#000000') ?? 0.0);
        $away = ReadableColor::mix($fill, $darkText ? '#ffffff' : '#000000', self::HOVER_SHARE);
        if ($away === null) {
            return "color-mix(in srgb, {$fill} 85%, black)";
        }
        if ((ReadableColor::ratio($away, $fill) ?? 1.0) >= self::HOVER_VISIBLE) {
            return $away;
        }

        for ($share = self::HOVER_SHARE; $share < 1.0; $share += 0.03) {
            $toward = ReadableColor::mix($fill, $darkText ? '#000000' : '#ffffff', $share);
            if ($toward !== null && (ReadableColor::ratio($text, $toward) ?? 0.0) >= ReadableColor::AA_NORMAL_TEXT) {
                return $toward;
            }
        }

        return $away;
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

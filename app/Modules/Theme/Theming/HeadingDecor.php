<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Tenancy\Context\CurrentSite;

/**
 * The decoration under a section's heading: the site-wide setting `theme.heading_decor` plus the
 * choice a block makes with its `heading_decor` prop (inherit · none · one of STYLES).
 *
 * Nothing stored reaches the markup as text. The drawn shape is a class picked from the fixed STYLES /
 * WIDTHS / WEIGHTS lists (storefront.css paints it, shapes included); a colour is printed only as a CSS
 * variable inside the head's `:root{}` after it matched a hex pattern or a palette step; the mask
 * picture is a media key resolved through the site's own library (a PNG of this site, not in the
 * trash) to an address of fixed shape. A stored value that is not listed falls back to the default,
 * so a setting nobody saved draws exactly what a heading drew before.
 *
 * @phpstan-type Settings array{style: string, color: string, custom_color: string, gradient: bool, custom_color_2: string, align: string, width: string, weight: string, mask_media: string, mask_keep_color: bool}
 */
final class HeadingDecor
{
    /** The platform's own shapes, in the order the editor lists them. Append-only (a page stores the key). */
    public const array STYLES = [
        'flourish', 'laurel', 'diamonds', 'star', 'linedot',
        'gear', 'hexnut', 'ruler', 'chevron', 'arrows',
        'pill', 'dashdot', 'circle', 'brackets', 'zigzag',
        'bar', 'double', 'dots', 'stitch', 'wave',
    ];

    /** What a block's `heading_decor` may hold: the site's choice, no decoration, or one shape. */
    public const array BLOCK_CHOICES = ['inherit', 'none', ...self::STYLES];

    /** The site-wide `style`: a shape, or none at all. Empty means "keep the rule headings always had". */
    public const array SITE_STYLES = ['none', ...self::STYLES];

    /** Palette steps of the brand colour (BrandPalette); the site picks one as `color`. */
    public const array PALETTE = ['50', '100', '200', '300', '400', '500', '600', '700', '800', '900'];

    /** The `color` values: the theme's accent, a palette step or the site's own colour. */
    public const array COLORS = ['accent', ...self::PALETTE, 'custom'];

    /**
     * Where the decoration sits: with the title, or always in the middle. Every section head is centred
     * today, so both draw the same; the setting is kept so a head that aligns left later can follow it.
     */
    public const array ALIGNS = ['title', 'center'];

    public const array WIDTHS = ['narrow', 'medium', 'wide'];

    public const array WEIGHTS = ['light', 'medium', 'bold'];

    public const string HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D';

    /** The mask picture must be a PNG with a transparent background (the only type the library takes for it). */
    public const array MASK_MIMES = ['image/png'];

    /** The shape of an address that may be printed in `url()` without quotes. */
    private const string URL_SAFE = '#^https?://[A-Za-z0-9._~:/%@+-]{1,500}$#D';

    /** @var array{scope: string, url: string|null}|null the last mask lookup, for the site and key it was made for */
    private ?array $mask = null;

    public function __construct(
        private readonly StorefrontSettings $settings,
        private readonly MediaLibraryUrls $media,
        private readonly CurrentSite $current,
    ) {}

    /**
     * The setting as the storefront reads it: every field checked, an unlisted or malformed one left
     * at its default. An empty array when nothing usable is stored.
     *
     * @return Settings
     */
    public static function clean(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $text = static fn (string $key): string => is_string($raw[$key] ?? null) ? $raw[$key] : '';
        $pick = static fn (string $key, array $options, string $default): string => in_array($text($key), $options, true) ? $text($key) : $default;
        $hex = static fn (string $key): string => preg_match(self::HEX, $text($key)) === 1 ? strtolower($text($key)) : '';

        $color = $pick('color', self::COLORS, 'accent');
        $custom = $hex('custom_color');
        if ($color === 'custom' && $custom === '') {
            $color = 'accent';
        }
        $mask = $text('mask_media');

        return [
            'style' => $pick('style', self::SITE_STYLES, ''),
            'color' => $color,
            'custom_color' => $custom,
            'gradient' => ($raw['gradient'] ?? false) === true,
            'custom_color_2' => $hex('custom_color_2'),
            'align' => $pick('align', self::ALIGNS, 'title'),
            'width' => $pick('width', self::WIDTHS, 'medium'),
            'weight' => $pick('weight', self::WEIGHTS, 'medium'),
            'mask_media' => preg_match('~^[A-Za-z0-9._/-]{1,255}$~D', $mask) === 1 ? $mask : '',
            'mask_keep_color' => ($raw['mask_keep_color'] ?? false) === true,
        ];
    }

    /**
     * The site's setting, cleaned. Read from the memoized settings: no query of its own.
     *
     * @return Settings
     */
    public function site(): array
    {
        return self::clean($this->settings->get('theme', 'heading_decor'));
    }

    /**
     * The CSS variables the setting adds to the head's `:root{}`: the colours (when not the accent),
     * and the mask picture's address. Nothing when the site saved no setting.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $site = $this->site();
        $variables = [];

        $first = match (true) {
            $site['color'] === 'custom' => $site['custom_color'],
            $site['color'] !== 'accent' => 'var(--color-brand-'.$site['color'].')',
            default => null,
        };
        if ($first !== null) {
            $variables['sf-decor-c'] = $first;
        }
        if ($site['gradient'] && $site['custom_color_2'] !== '') {
            $variables['sf-decor-c2'] = $site['custom_color_2'];
        }

        $url = $this->maskUrl($site);
        if ($url !== null) {
            $variables['sf-decor-mask'] = 'url('.$url.')';
        }

        return $variables;
    }

    /**
     * What a heading draws, for a block's `heading_decor` choice (null: the page's own, i.e. inherit).
     *
     * @return string|null null: draw the rule headings always had · '': draw nothing · otherwise the class list of the decoration
     */
    public function classes(mixed $choice): ?string
    {
        $site = $this->site();
        $pick = is_string($choice) && in_array($choice, self::BLOCK_CHOICES, true) ? $choice : 'inherit';
        $maskUrl = $pick === 'inherit' ? $this->maskUrl($site) : null;

        $style = match (true) {
            $pick === 'none' => 'none',
            $pick !== 'inherit' => $pick,
            $maskUrl !== null => 'mask',
            default => $site['style'],
        };
        if ($style === '') {
            return null;
        }
        if ($style === 'none') {
            return '';
        }

        $classes = ['sf-decor', $style === 'mask' ? ($site['mask_keep_color'] ? 'sf-decor--image' : 'sf-decor--mask') : 'sf-decor--'.$style];
        if ($site['width'] !== 'medium') {
            $classes[] = 'sf-decor--'.$site['width'];
        }
        if ($site['weight'] !== 'medium') {
            $classes[] = 'sf-decor--'.$site['weight'];
        }
        if ($site['gradient'] && ! ($style === 'mask' && $site['mask_keep_color'])) {
            $classes[] = 'sf-decor--grad';
        }

        return implode(' ', $classes);
    }

    /**
     * The address of the site's mask picture, or null when none is set, the key is not a PNG of this
     * site (another site's, in the trash, a different type) or the address is not of the plain shape
     * printed unquoted. One query, only when a key is set, and once per request.
     *
     * @param  Settings  $site
     */
    private function maskUrl(array $site): ?string
    {
        if ($site['mask_media'] === '') {
            return null;
        }
        if (! $this->current->hasId()) {
            return null;
        }
        $scope = $this->current->id().'|'.$site['mask_media'];
        if (($this->mask['scope'] ?? null) !== $scope) {
            $url = $this->media->fileUrls([$site['mask_media']], self::MASK_MIMES)[$site['mask_media']] ?? null;
            $this->mask = ['scope' => $scope, 'url' => is_string($url) && preg_match(self::URL_SAFE, $url) === 1 ? $url : null];
        }

        return $this->mask['url'];
    }
}

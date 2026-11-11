<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

use App\Modules\Theme\Theming\HeadingDecor;

/**
 * The look of a section — the "Trình bày" props every block type shares (docs/notes/section-style-system.md
 * §2, v2.16). They are merged into each type's schema at ComponentRegistry::schemaFor(), so ValidPageJson
 * checks them and BlockRenderer reads them with no per-type code.
 *
 * Rendering reads them through fixed lookup tables only: a stored value that is missing or not listed
 * gives no class, so a stored string never reaches `class`, `style` or `id` except `anchor_id`, which
 * must match ANCHOR_PATTERN (refused at save, skipped at render) and is escaped by Blade.
 */
final class StyleProps
{
    /** Colour sets of a section (Sáng · Nhạt · Thương hiệu · Tối); ThemeResolver prints each one's roles. */
    public const array SCHEMES = ['light', 'soft', 'brand', 'dark'];

    /**
     * The band behind a section. `scheme` paints the chosen colour set, `image` a photo under an overlay.
     * `surface`, `tint` and `dark` are the older values a few block views still paint themselves
     * (SectionProps::BG); they stay valid so stored pages keep their look.
     */
    public const array BG = ['none', 'scheme', 'image', 'surface', 'tint', 'dark'];

    /** How dark the overlay on a photo is: 0 is the least that keeps white text at 4.5:1 on any photo. */
    public const array OVERLAY = ['0', '75', '90'];

    public const array SPACING = ['none', 's', 'm', 'l', 'xl'];

    public const array WIDTH = ['contained', 'narrow', 'full'];

    public const array ALIGN = ['left', 'center'];

    public const array CORNER = ['none', 'soft'];

    public const array SHADOW = ['none', 'soft'];

    public const array HIDE_ON = ['none', 'mobile', 'desktop'];

    public const array MOTION = ['none', 'fade', 'rise', 'auto'];

    /**
     * What `auto` (the default) draws for each block type: lists of cards, numbers and bands rise into
     * view, lighter blocks only fade in; the top-of-page bands, forms and long reading text never move
     * (a form must stay where the visitor aims, and moving body text has no purpose). A type not listed
     * here fades. Changing a line changes every site that kept the default.
     */
    public const array AUTO_MOTION = [
        'hero' => 'none', 'hero_slider' => 'none', 'page_header' => 'none', 'form' => 'none', 'contact' => 'none',
        'richtext' => 'none', 'richtext_html' => 'none',
        'about' => 'rise', 'cta' => 'rise', 'entry_grid' => 'rise', 'latest_posts' => 'rise', 'recent_posts' => 'rise',
        'category_list' => 'rise', 'features' => 'rise', 'team' => 'rise', 'testimonial' => 'rise', 'pricing' => 'rise',
        'process' => 'rise', 'stats' => 'rise',
    ];

    /**
     * The block types whose items come into view one after another (their head first, then each card,
     * stat, logo or step a moment later) when the block keeps the automatic motion; any other block,
     * or a block whose motion was chosen, moves as one piece. Changing a line changes every site that
     * kept the default.
     */
    public const array AUTO_STAGGER = [
        'entry_grid', 'latest_posts', 'features', 'team', 'testimonial', 'pricing', 'process', 'stats',
        'partners', 'gallery', 'quick_links', 'branches',
    ];

    /**
     * The extra looks a block may add on top (the reserved prop `extra_style`, a list), registered by the
     * theme: each saved value maps to the class the wrapper prints. A value that is not a key here is
     * refused at save and ignored at render; the saved value is never put in the markup. Append-only.
     */
    public const array EXTRA_STYLES = ['pattern-cross' => 'sf-s--x-pattern-cross'];

    public const string ANCHOR_PATTERN = '/^[a-z0-9-]{1,64}$/D';

    /** Ids the storefront layout already uses (the skip link's target, the phone menu): never an anchor. */
    public const array LAYOUT_IDS = ['noi-dung', 'mobile-menu'];

    /**
     * The choices the editor's panel sets one at a time (Editor::setLook), each with its list.
     * `spacing` is both steps at once (top and bottom joined).
     */
    public const array CHOICES = [
        'scheme' => self::SCHEMES, 'overlay' => self::OVERLAY, 'spacing' => self::SPACING,
        'spacing_top' => self::SPACING, 'spacing_bottom' => self::SPACING, 'width' => self::WIDTH,
        'align' => self::ALIGN, 'corner' => self::CORNER, 'shadow' => self::SHADOW,
        'hide_on' => self::HIDE_ON, 'motion' => self::MOTION,
    ];

    /**
     * Every look prop and its schema leaf. A spacing prop that is not set keeps the block's own spacing,
     * so it has no default.
     */
    public const array SCHEMA = [
        'scheme' => ['type' => 'enum', 'options' => self::SCHEMES, 'default' => 'light', 'group' => 'style'],
        'bg' => ['type' => 'enum', 'options' => self::BG, 'default' => 'none', 'group' => 'style'],
        'bg_image' => ['type' => 'image', 'group' => 'style'],
        'overlay' => ['type' => 'enum', 'options' => self::OVERLAY, 'default' => '0', 'group' => 'style'],
        'spacing_top' => ['type' => 'enum', 'options' => self::SPACING, 'group' => 'style'],
        'spacing_bottom' => ['type' => 'enum', 'options' => self::SPACING, 'group' => 'style'],
        'width' => ['type' => 'enum', 'options' => self::WIDTH, 'default' => 'contained', 'group' => 'style'],
        'align' => ['type' => 'enum', 'options' => self::ALIGN, 'default' => 'left', 'group' => 'style'],
        'corner' => ['type' => 'enum', 'options' => self::CORNER, 'default' => 'none', 'group' => 'style'],
        'shadow' => ['type' => 'enum', 'options' => self::SHADOW, 'default' => 'none', 'group' => 'style'],
        'hide_on' => ['type' => 'enum', 'options' => self::HIDE_ON, 'default' => 'none', 'group' => 'style'],
        'anchor_id' => ['type' => 'text', 'pattern' => self::ANCHOR_PATTERN, 'group' => 'style'],
        'motion' => ['type' => 'enum', 'options' => self::MOTION, 'default' => 'auto', 'group' => 'style'],
        'extra_style' => ['type' => 'list', 'of' => ['type' => 'enum', 'options' => ['pattern-cross']], 'group' => 'style'],
        // Only on a block that draws a section heading (ComponentRegistry::schemaFor); the block editor
        // lists it with the block's own props, in its own group.
        'heading_decor' => ['type' => 'enum', 'options' => HeadingDecor::BLOCK_CHOICES, 'default' => 'inherit', 'group' => 'heading'],
        'heading_align' => ['type' => 'enum', 'options' => HeadingDecor::HEAD_ALIGNS, 'default' => 'center', 'group' => 'heading'],
    ];

    /**
     * Names a block's own schema may never declare: the props above, the ones reserved for later
     * (overlay_tone, divider_top/bottom, border, motion_stagger) and the editor's own
     * state (ink, spacing_split), and heading_decor / heading_align (the decoration and the alignment of a section heading, only on a block
     * that has one). page_header and hero_slider had their own `overlay` (how dark their
     * photo is, a number) before; schemaFor() keeps theirs, and the wrapper photo is not offered there.
     */
    public const array RESERVED = [
        'scheme', 'bg', 'bg_image', 'overlay', 'spacing_top', 'spacing_bottom', 'width', 'align', 'corner',
        'shadow', 'hide_on', 'anchor_id', 'motion', 'overlay_tone', 'divider_top', 'divider_bottom', 'border',
        'motion_stagger', 'extra_style', 'ink', 'spacing_split', 'heading_decor', 'heading_align',
    ];

    /**
     * The wrapper around one rendered block, or null when the block sets no look and does not move (it
     * then renders exactly as before). $imageUrl is `bg_image` already resolved by BlockRenderer; the
     * first block never moves, so the page's main content paints at once; $slot is a block of a slot
     * around the page (motion()).
     *
     * @param  array<array-key, mixed>  $props
     * @return array{classes: list<string>, id: string|null, image: string|null}|null
     */
    public static function wrapper(array $props, string $type, bool $first, ?string $imageUrl, bool $slot = false): ?array
    {
        $classes = [];
        $bg = SectionProps::choice($props, 'bg', self::BG);
        $image = $bg === 'image' && $imageUrl !== null && $imageUrl !== '' ? $imageUrl : null;

        if ($image !== null) {
            $classes[] = 'sf-s--image';
            $classes[] = 'sf-s--ov-'.SectionProps::choice($props, 'overlay', self::OVERLAY);
        } elseif ($bg === 'scheme') {
            $classes[] = 'sf-s--sch-'.SectionProps::choice($props, 'scheme', self::SCHEMES);
        }

        foreach (['spacing_top' => 'pt', 'spacing_bottom' => 'pb'] as $prop => $short) {
            $value = $props[$prop] ?? null;
            if (is_string($value) && in_array($value, self::SPACING, true)) {
                $classes[] = "sf-s--{$short}-{$value}";
            }
        }

        foreach (['width' => ['w', self::WIDTH], 'align' => ['a', self::ALIGN], 'corner' => ['corner', self::CORNER], 'shadow' => ['shadow', self::SHADOW], 'hide_on' => ['hide', self::HIDE_ON]] as $prop => [$short, $options]) {
            $value = SectionProps::choice($props, $prop, $options);
            if ($value !== $options[0]) {
                $classes[] = "sf-s--{$short}-{$value}";
            }
        }

        $motion = self::motion($props, $type, $first, $slot);
        if ($motion !== 'none') {
            $classes[] = "sf-s--m-{$motion}";
            if (self::staggers($props, $type)) {
                $classes[] = 'sf-s--stagger';
            }
        }

        $extra = $props['extra_style'] ?? null;
        foreach (self::EXTRA_STYLES as $value => $class) {
            if (is_array($extra) && in_array($value, $extra, true)) {
                $classes[] = $class;
            }
        }

        $anchor = $props['anchor_id'] ?? null;
        $id = is_string($anchor) && preg_match(self::ANCHOR_PATTERN, $anchor) === 1 ? $anchor : null;

        if ($classes === [] && $id === null) {
            return null;
        }

        return ['classes' => ['sf-s', ...$classes], 'id' => $id, 'image' => $image];
    }

    /**
     * The colour set a block shows today, for the editor's "Bộ màu" cards: the chosen set when the
     * band paints one; otherwise what the block paints itself — hero and cta their dark band
     * (storefront.css falls back to --color-dark), the older `surface` / `tint` a soft band, `dark`
     * a dark one, anything else the page (light).
     *
     * @param  array<array-key, mixed>  $props
     */
    public static function schemeOf(array $props, string $type): string
    {
        $bg = SectionProps::choice($props, 'bg', self::BG);

        return match (true) {
            $bg === 'scheme' => SectionProps::choice($props, 'scheme', self::SCHEMES),
            $bg === 'none' && in_array($type, ['hero', 'cta'], true) => 'dark',
            $bg === 'surface', $bg === 'tint' => 'soft',
            $bg === 'dark' => 'dark',
            default => 'light',
        };
    }

    /**
     * Whether a block's look asks for the scroll-in motion (the layout loads sections.js only then).
     *
     * @param  array<array-key, mixed>  $props
     */
    public static function moves(array $props, string $type, bool $first, bool $slot = false): bool
    {
        return self::motion($props, $type, $first, $slot) !== 'none';
    }

    /**
     * The scroll-in motion a block draws: none for the first block; the saved choice; for `auto` (also
     * when nothing or an unknown value is saved) the one AUTO_MOTION gives its type, except in a slot
     * around the page (a post's side column sticks, so a block there may never scroll into view).
     *
     * @param  array<array-key, mixed>  $props
     * @return 'none'|'fade'|'rise'
     */
    public static function motion(array $props, string $type, bool $first, bool $slot = false): string
    {
        if ($first) {
            return 'none';
        }

        $motion = SectionProps::choice($props, 'motion', self::MOTION, 'auto');

        return match ($motion) {
            'none', 'fade', 'rise' => $motion,
            default => $slot ? 'none' : self::autoMotion($type),
        };
    }

    /**
     * Whether a moving block brings its items in one after another rather than as one piece: only a
     * block that keeps the automatic motion (also when nothing or an unknown value is saved) and whose
     * type AUTO_STAGGER lists.
     *
     * @param  array<array-key, mixed>  $props
     */
    public static function staggers(array $props, string $type): bool
    {
        return SectionProps::choice($props, 'motion', self::MOTION, 'auto') === 'auto' && in_array($type, self::AUTO_STAGGER, true);
    }

    /** @return 'none'|'fade'|'rise' */
    public static function autoMotion(string $type): string
    {
        return self::AUTO_MOTION[$type] ?? 'fade';
    }
}

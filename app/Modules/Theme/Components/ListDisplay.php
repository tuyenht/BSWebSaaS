<?php

declare(strict_types=1);

namespace App\Modules\Theme\Components;

/**
 * Resolves the "display" group of a list block (SectionProps::LIST_DISPLAY) into what the view
 * draws: grid or slider, columns, the phone's behaviour, the rotation interval, the arrows and the
 * "view all" button. Pure: no query, no request state.
 *
 * A block saved before the group existed has no `layout` key; it gets null here and renders exactly
 * as it always did. A stored value that is not in its list reads as `auto`.
 */
final class ListDisplay
{
    /** The most items a slider draws, whatever the block's limit. */
    public const int SLIDER_MAX = 24;

    /** The most items a list block may ask for (when saving and when drawing). */
    public const int LIMIT_MAX = 50;

    /** The fewest items a list block may ask for. */
    public const int LIMIT_MIN = 1;

    /** Logos in view at once on a phone's partner slider. */
    public const int PHONE_LOGOS = 3;

    /** The shortest rotation, in seconds. */
    public const int MIN_SECONDS = 3;

    /**
     * What `auto` means for each kind of list: columns across on a wide screen; `fit` = the most
     * items a grid holds before `auto` turns it into a slider (the product kinds hold two rows
     * instead: see plan()); `seconds` = rotation of a slider;
     * `arrows` = the arrows' own default.
     */
    public const array AUTO = [
        'product-overlay' => ['columns' => 3, 'seconds' => 5, 'arrows' => 'auto'],
        'product' => ['columns' => 4, 'seconds' => 5, 'arrows' => 'auto'],
        'icon' => ['columns' => 4, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
        'project' => ['columns' => 4, 'fit' => 4, 'seconds' => 5, 'arrows' => 'always'],
        'news' => ['columns' => 3, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
        'partners' => ['columns' => 6, 'fit' => 6, 'seconds' => 3, 'arrows' => 'always'],
        'testimonial' => ['columns' => 3, 'fit' => 3, 'seconds' => 8, 'arrows' => 'auto'],
        'team' => ['columns' => 4, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
        'branches' => ['columns' => 3, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
    ];

    /**
     * The `sizes` of a card photo in a list drawn from a plan(): what the rail's CSS gives each card
     * (`.sf-rail__list`: 1.5rem gap, `columns` across from 1024px, two across from 640px, and on a phone
     * the swipe row shows 1.25 cards, `two` shows two, `one` one, `slider` three; the page container is
     * 80rem with 2rem side padding, 1.5rem from 640px, 1rem on a phone). A block's own width setting
     * can only make the card narrower, never wider, so the answer is never too small.
     *
     * @param  array{columns: int, mobile: string}  $plan
     */
    public static function cardSizes(array $plan): string
    {
        $columns = max(1, $plan['columns']);
        $gaps = 1.5 * ($columns - 1);
        $rem = static fn (float $n): string => rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
        $wide = $rem((76 - $gaps) / $columns).'rem';
        $desktop = $columns === 1 ? 'calc(100vw - 4rem)' : sprintf('calc((100vw - %srem) / %d)', $rem(4 + $gaps), $columns);
        $phone = match ($plan['mobile']) {
            'one' => 'calc(100vw - 2rem)',
            'two' => 'calc((100vw - 3rem) / 2)',
            'slider' => 'calc((100vw - 4rem) / 3)',
            default => 'calc((100vw - 2.25rem) / 1.25)',
        };

        return sprintf('(min-width:1280px) %s, (min-width:1024px) %s, (min-width:640px) calc((100vw - 4.5rem) / 2), %s', $wide, $desktop, $phone);
    }

    /**
     * Whether the block carries the group at all (a block saved before it did not).
     *
     * @param  array<array-key, mixed>  $props
     */
    public static function applies(array $props): bool
    {
        return array_key_exists('layout', $props);
    }

    /**
     * @param  array<array-key, mixed>  $props
     * @param  int  $found  the rows read (a query reads one more than $limit to learn whether more exist)
     * @param  int  $limit  the block's limit (a static list passes its own length)
     * @return array{layout: string, columns: int, mobile: string, interval: int, arrows: string, view_all: bool, shown: int, class: string, scrolls: bool, bar: string}|null
     */
    public static function plan(string $kind, array $props, int $found, int $limit): ?array
    {
        if (! self::applies($props) || ! isset(self::AUTO[$kind])) {
            return null;
        }

        $auto = self::AUTO[$kind];
        $count = min($found, $limit);

        $columns = SectionProps::choice($props, 'columns', SectionProps::LIST_DISPLAY['columns']['options']);
        $columns = $columns === 'auto' ? $auto['columns'] : (int) $columns;

        // A product grid holds two rows of whatever columns it resolved to before it becomes a slider.
        $fit = $auto['fit'] ?? 2 * $columns;
        $layout = SectionProps::choice($props, 'layout', SectionProps::LIST_DISPLAY['layout']['options']);
        if ($layout === 'auto') {
            $layout = $count > $fit ? 'slider' : 'grid';
        }

        // Partner logos are a slider on a phone (three in view, rotating) whatever the wide layout is;
        // the other kinds are a swipe row, or one column for one or two items.
        $mobile = SectionProps::choice($props, 'mobile', SectionProps::LIST_DISPLAY['mobile']['options']);
        if ($mobile === 'auto') {
            $mobile = $kind === 'partners' ? 'slider' : ($count <= 2 ? 'one' : 'swipe');
        }

        $seconds = SectionProps::choice($props, 'autoplay', SectionProps::LIST_DISPLAY['autoplay']['options']);
        $seconds = match ($seconds) {
            'auto' => $auto['seconds'],
            'off' => 0,
            default => max(self::MIN_SECONDS, (int) $seconds),
        };

        $arrows = SectionProps::choice($props, 'arrows', SectionProps::LIST_DISPLAY['arrows']['options']);
        $arrows = $arrows === 'auto' ? $auto['arrows'] : $arrows;

        $shown = $layout === 'slider' ? min($count, self::SLIDER_MAX) : $count;
        $viewAll = SectionProps::choice($props, 'view_all', SectionProps::LIST_DISPLAY['view_all']['options']);
        $more = $found > $limit || $shown < $count;
        $wideRotates = $layout === 'slider' && $shown > $columns;
        $phoneRotates = $mobile === 'slider' && $shown > self::PHONE_LOGOS;
        // Where the Pause button's room is kept: always, or only on the widths where the list rotates.
        $bar = match (true) {
            $wideRotates && ($mobile === 'swipe' || ($mobile === 'slider' && ! $phoneRotates)) => 'wide',
            $wideRotates => '',
            $phoneRotates => 'phone',
            default => '',
        };

        return [
            'layout' => $layout,
            'columns' => $columns,
            'mobile' => $mobile,
            // A grid never rotates, and neither does a one-page slider; a phone's swipe row never does
            // (the script enforces that too, since the phone is a width, not a markup choice). A
            // partner grid still rotates on a phone, where it is a slider.
            'interval' => $wideRotates || $phoneRotates ? $seconds * 1000 : 0,
            'arrows' => $arrows,
            'view_all' => $viewAll === 'show' || ($viewAll === 'auto' && $more),
            'shown' => $shown,
            'scrolls' => $layout === 'slider' || $mobile === 'slider',
            'bar' => $bar,
            'class' => sprintf('sf-rail__list sf-rail__list--%s sf-rail__list--c%d sf-rail__list--m-%s', $layout, $columns, $mobile),
        ];
    }
}

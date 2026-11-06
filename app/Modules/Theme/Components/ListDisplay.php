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

    /** The shortest rotation, in seconds. */
    public const int MIN_SECONDS = 3;

    /**
     * What `auto` means for each kind of list: columns across on a wide screen; `fit` = the most
     * items a grid holds before `auto` turns it into a slider; `seconds` = rotation of a slider;
     * `arrows` = the arrows' own default.
     */
    public const array AUTO = [
        'product-overlay' => ['columns' => 3, 'fit' => 6, 'seconds' => 5, 'arrows' => 'auto'],
        'product' => ['columns' => 4, 'fit' => 8, 'seconds' => 5, 'arrows' => 'auto'],
        'icon' => ['columns' => 4, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
        'project' => ['columns' => 4, 'fit' => 4, 'seconds' => 5, 'arrows' => 'always'],
        'news' => ['columns' => 3, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
        'partners' => ['columns' => 6, 'fit' => 6, 'seconds' => 3, 'arrows' => 'always'],
        'testimonial' => ['columns' => 3, 'fit' => 3, 'seconds' => 8, 'arrows' => 'auto'],
        'team' => ['columns' => 4, 'fit' => PHP_INT_MAX, 'seconds' => 5, 'arrows' => 'auto'],
    ];

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
     * @return array{layout: string, columns: int, mobile: string, interval: int, arrows: string, view_all: bool, shown: int, class: string}|null
     */
    public static function plan(string $kind, array $props, int $found, int $limit): ?array
    {
        if (! self::applies($props) || ! isset(self::AUTO[$kind])) {
            return null;
        }

        $auto = self::AUTO[$kind];
        $count = min($found, $limit);

        $layout = SectionProps::choice($props, 'layout', SectionProps::LIST_DISPLAY['layout']['options']);
        if ($layout === 'auto') {
            $layout = $count > $auto['fit'] ? 'slider' : 'grid';
        }

        $columns = SectionProps::choice($props, 'columns', SectionProps::LIST_DISPLAY['columns']['options']);
        $columns = $columns === 'auto' ? $auto['columns'] : (int) $columns;

        $mobile = SectionProps::choice($props, 'mobile', SectionProps::LIST_DISPLAY['mobile']['options']);
        if ($mobile === 'auto') {
            $mobile = $count <= 2 ? 'one' : 'swipe';
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

        return [
            'layout' => $layout,
            'columns' => $columns,
            'mobile' => $mobile,
            // A grid never rotates, and neither does a one-page slider; a phone's swipe row never does
            // (the script enforces that too, since the phone is a width, not a markup choice).
            'interval' => $layout === 'slider' && $shown > $columns ? $seconds * 1000 : 0,
            'arrows' => $arrows,
            'view_all' => $viewAll === 'show' || ($viewAll === 'auto' && $more),
            'shown' => $shown,
            'class' => sprintf('sf-rail__list sf-rail__list--%s sf-rail__list--c%d sf-rail__list--m-%s', $layout, $columns, $mobile),
        ];
    }
}

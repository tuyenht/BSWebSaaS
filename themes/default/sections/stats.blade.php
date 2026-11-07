{{--
    Stats: big numbers with a label (up to 8; a number, years since a start year, or a year as is).
    The server prints the final number already formatted the Vietnamese way (2.000), so print, search engines, no-JS and reduced motion are right; sf-stats.js
    only counts up the numbers that start below the fold. The animated number is hidden from screen
    readers; a visually hidden copy carries the final text. An item needs a number or a label.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $stats = \App\Modules\Theme\Components\SectionProps::statEntries($props, 'items', \App\Modules\Theme\Components\SectionProps::STATS_MAX_ITEMS);
    $floating = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::STATS_VARIANTS) === 'floating';
@endphp
@if ($stats !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => $floating ? 'sf-stats sf-stats--floating' : 'sf-stats'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-stats__list sf-stats__list--{{ min(count($stats), 4) }}">
@include('theme::partials.sf-stat-items', ['stats' => $stats])
</ul>
@endcomponent
@endif

{{--
    Stats: big numbers with a label (up to 8). The server prints the final number already formatted
    the Vietnamese way (2.000), so print, search engines, no-JS and reduced motion are right; sf-stats.js
    only counts up the numbers that start below the fold. The animated number is hidden from screen
    readers; a visually hidden copy carries the final text. An item needs a number or a label.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $level = $heading !== '' ? 'h3' : 'h2';
    $stats = [];
    foreach (\App\Modules\Theme\Components\SectionProps::items($props, 'items', 8) as $item) {
        $raw = $item['value'] ?? null;
        $value = is_string($raw) && preg_match('/\A\d{1,12}\z/', trim($raw)) === 1 ? (int) trim($raw) : $raw;
        $value = is_int($value) && $value >= 0 && $value <= 999_999_999_999 ? $value : null;
        $label = \App\Modules\Theme\Components\SectionProps::text($item, 'label');
        if ($value === null && $label === '') {
            continue;
        }
        $stats[] = [
            'value' => $value,
            'number' => $value === null ? '' : number_format($value, 0, ',', '.'),
            'suffix' => $value === null ? '' : mb_substr(\App\Modules\Theme\Components\SectionProps::text($item, 'suffix'), 0, 8),
            'label' => $label,
        ];
    }
@endphp
@if ($stats !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-stats'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-stats__list sf-stats__list--{{ min(count($stats), 4) }}">
@foreach ($stats as $stat)
<li class="sf-stat">
@if ($stat['value'] !== null)
<span class="sf-stat__value" aria-hidden="true"><span data-count="{{ $stat['value'] }}">{{ $stat['number'] }}</span>@if ($stat['suffix'] !== '')<span class="sf-stat__suffix">{{ $stat['suffix'] }}</span>@endif</span>
<span class="sr-only">{{ $stat['number'] }}{{ $stat['suffix'] }}</span>
@endif
@if ($stat['label'] !== '')
<span class="sf-stat__label">{{ $stat['label'] }}</span>
@endif
</li>
@endforeach
</ul>
@endcomponent
@endif

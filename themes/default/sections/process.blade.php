{{--
    Process: numbered steps — stacked on a phone, in a row from a wide screen (3 to 6 steps; the
    number is drawn by CSS; `variant` numeral draws it as a big accent numeral on a grey card). A step needs its title. Fewer than three steps render nothing.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $description = \App\Modules\Theme\Components\SectionProps::text($props, 'description');
    $numeral = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::PROCESS_VARIANTS) === 'numeral';
    $level = $heading !== '' ? 'h3' : 'h2';
    $steps = array_slice(array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'steps', 6),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'title') !== '',
    )), 0, 6);
@endphp
@if (count($steps) >= 3)
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => $numeral ? 'sf-steps sf-steps--numeral' : 'sf-steps'])
@include('theme::partials.sf-section-head', ['heading' => $heading, 'description' => $description])
<ol class="sf-steps__list sf-steps__list--{{ count($steps) }}">
@foreach ($steps as $item)
<li class="sf-step">
<{{ $level }} class="sf-step__title">{{ $item['title'] }}</{{ $level }}>
@if (\App\Modules\Theme\Components\SectionProps::text($item, 'text') !== '')
<p class="sf-step__text">{{ $item['text'] }}</p>
@endif
</li>
@endforeach
</ol>
@endcomponent
@endif

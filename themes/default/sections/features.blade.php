@if (\App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::FEATURES_VARIANTS) === 'top-rule')
{{-- The top-rule look (opt-in): cards with an accent rule on top and an optional glyph per item
     (`icon`, a key of the icon partial; an unknown key draws nothing). --}}
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-features'])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
<ul class="sf-grid sf-grid--3">
@foreach ((array) ($props['items'] ?? []) as $item)
@php($item = (array) $item)
<li class="sf-feature">
@if (is_string($item['icon'] ?? null) && $item['icon'] !== '')
    @include('theme::partials.icon', ['name' => $item['icon'], 'stroke' => 1.5, 'wrap' => 'sf-feature__icon'])
@endif
    <h3 class="sf-feature__title">{{ $item['title'] ?? '' }}</h3>
@if (! empty($item['text']))
    <p class="sf-feature__text">{{ $item['text'] }}</p>
@endif
</li>
@endforeach
</ul>
@endcomponent
@else
<section class="features">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    <ul class="features__list">
        @foreach ((array) ($props['items'] ?? []) as $item)
            @php($item = (array) $item)
            <li class="features__item">
                <h3>{{ $item['title'] ?? '' }}</h3>
                @if (! empty($item['text']))
                    <p>{{ $item['text'] }}</p>
                @endif
            </li>
        @endforeach
    </ul>
</section>
@endif

{{--
    Testimonials: a card per quote — a quote glyph, the italic text, then a round avatar (the first
    letter of the name when there is no photo), the name and the role. One column on a phone, two or
    three (variant) on a wide screen. A card needs its quote text.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $variant = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::TESTIMONIAL_VARIANTS);
    $quotes = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'items'),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'quote') !== '',
    ));
@endphp
@php
    $sfDisplay = \App\Modules\Theme\Components\ListDisplay::plan('testimonial', $props, count($quotes), PHP_INT_MAX);
    $quotes = $sfDisplay !== null ? array_slice($quotes, 0, $sfDisplay['shown']) : $quotes;
@endphp
@if ($quotes !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-quotes'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => $heading])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-quotes__list sf-quotes__list--'.$variant.' sf-quotes__list--n'.min(count($quotes), $variant === 'grid-3' ? 3 : 2) }}">
@foreach ($quotes as $item)
@php
    $name = \App\Modules\Theme\Components\SectionProps::text($item, 'name');
    $role = \App\Modules\Theme\Components\SectionProps::text($item, 'role');
    $hasAvatar = \App\Modules\Shared\Support\SafeLink::href($item['avatar'] ?? null) !== null;
@endphp
<li><figure class="sf-quote">
<span class="sf-quote__mark" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'quote', 'size' => 28])</span>
<blockquote class="sf-quote__text"><p>{{ $item['quote'] }}</p></blockquote>
@if ($name !== '' || $role !== '' || $hasAvatar)
<figcaption class="sf-quote__by">
@if ($hasAvatar)
@include('theme::partials.sf-img', ['src' => $item['avatar'], 'srcset' => ($srcsets ?? [])[$item['avatar']] ?? null, 'sizes' => '4rem', 'w' => 96, 'h' => 96, 'class' => 'sf-quote__avatar'])
@elseif ($name !== '')
<span class="sf-quote__avatar sf-quote__avatar--initial" aria-hidden="true">{{ \App\Modules\Theme\Components\SectionProps::initial($name) }}</span>
@endif
<span class="sf-quote__who">
@if ($name !== '')
<span class="sf-quote__name">{{ $name }}</span>
@endif
@if ($role !== '')
<span class="sf-quote__role">{{ $role }}</span>
@endif
</span>
</figcaption>
@endif
</figure></li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@endcomponent
@endif

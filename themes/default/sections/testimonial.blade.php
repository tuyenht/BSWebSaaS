{{--
    Testimonials: quotes with the name and role of who said them and an optional round photo.
    A card needs its quote text.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $quotes = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'items'),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'quote') !== '',
    ));
@endphp
@if ($quotes !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-quotes'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-quotes__list">
@foreach ($quotes as $item)
@php
    $name = \App\Modules\Theme\Components\SectionProps::text($item, 'name');
    $role = \App\Modules\Theme\Components\SectionProps::text($item, 'role');
@endphp
<li><figure class="sf-quote">
<blockquote class="sf-quote__text"><p>{{ $item['quote'] }}</p></blockquote>
@if ($name !== '' || $role !== '' || \App\Modules\Shared\Support\SafeLink::href($item['avatar'] ?? null) !== null)
<figcaption class="sf-quote__by">
@include('theme::partials.sf-img', ['src' => $item['avatar'] ?? null, 'srcset' => ($srcsets ?? [])[$item['avatar'] ?? ''] ?? null, 'sizes' => '4rem', 'w' => 96, 'h' => 96, 'class' => 'sf-quote__avatar'])
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
@endcomponent
@endif

{{--
    Gallery: pictures from the site's library in a grid of 2–4 columns (one on a phone, two on a
    tablet), each with an optional caption. A picture that did not resolve is skipped. The picture
    is decorative (alt=""), its caption carries the text.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $columns = \App\Modules\Theme\Components\SectionProps::choice($props, 'columns', \App\Modules\Theme\Components\SectionProps::GALLERY_COLUMNS);
    $pictures = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'images'),
        static fn (array $item): bool => \App\Modules\Shared\Support\SafeLink::href($item['image'] ?? null) !== null,
    ));
@endphp
@if ($pictures !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-gallery'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-gallery__list sf-gallery__list--{{ $columns }}">
@foreach ($pictures as $item)
@php($caption = \App\Modules\Theme\Components\SectionProps::text($item, 'caption'))
<li><figure class="sf-gallery__item">
@include('theme::partials.sf-img', ['src' => $item['image'], 'srcset' => ($srcsets ?? [])[$item['image']] ?? null, 'sizes' => '(min-width: 64rem) 25vw, (min-width: 40rem) 50vw, 100vw', 'w' => 800, 'h' => 600, 'class' => 'sf-gallery__img'])
@if ($caption !== '')
<figcaption class="sf-gallery__caption">{{ $caption }}</figcaption>
@endif
</figure></li>
@endforeach
</ul>
@endcomponent
@endif

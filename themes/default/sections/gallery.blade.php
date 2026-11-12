{{--
    Gallery: pictures from the site's library in a grid of 2–4 columns (two on a phone; never more columns
    than pictures), each with an optional caption. A picture that did not resolve is skipped. A picture with a caption keeps
    alt="" (the caption carries the text); one without takes the library's alt text.
    Each picture links to itself: without script the link opens the photo, with sf-viewer.js it opens
    the photo viewer, which steps through every gallery picture on the page. The link's name says it
    opens the photo larger and repeats the caption (or the alt text). `caption_style` overlay puts the
    caption on the photo, white on a dark fade at its foot (the caption stays text, never part of the image).
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $columns = \App\Modules\Theme\Components\SectionProps::choice($props, 'columns', \App\Modules\Theme\Components\SectionProps::GALLERY_COLUMNS);
    $sfOverlay = \App\Modules\Theme\Components\SectionProps::choice($props, 'caption_style', \App\Modules\Theme\Components\SectionProps::GALLERY_CAPTIONS) === 'overlay';
    $pictures = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'images'),
        static fn (array $item): bool => \App\Modules\Shared\Support\SafeLink::href($item['image'] ?? null) !== null,
    ));
    // Never more columns than pictures (two at least): three pictures in a four-column gallery fill three
    // columns instead of leaving the fourth empty.
    $columns = (string) min((int) $columns, max(2, count($pictures)));
@endphp
@if ($pictures !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => $sfOverlay ? 'sf-gallery sf-gallery--overlay' : 'sf-gallery'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-gallery__list sf-gallery__list--{{ $columns }}">
@foreach ($pictures as $item)
@php
    $caption = \App\Modules\Theme\Components\SectionProps::text($item, 'caption');
    $sfAlt = $caption === '' ? (string) (($alts ?? [])[$item['image']] ?? '') : '';
    $sfName = $caption !== '' ? $caption : $sfAlt;
@endphp
<li><figure class="sf-gallery__item">
<a class="sf-gallery__zoom" href="{{ \App\Modules\Shared\Support\SafeLink::href($item['image']) }}" data-sf-zoom="gallery" aria-label="{{ $sfName === '' ? __('storefront.zoom_photo') : __('storefront.zoom_photo_named', ['name' => $sfName]) }}">
@include('theme::partials.sf-img', ['src' => $item['image'], 'srcset' => ($srcsets ?? [])[$item['image']] ?? null, 'alt' => $sfAlt, 'sizes' => '(min-width: 64rem) 25vw, 50vw', 'w' => 800, 'h' => 600, 'priority' => ! empty($props['_high']) && $loop->first ? 'high' : 'lazy', 'class' => 'sf-gallery__img'])
@if ($sfOverlay && $caption !== '')
<span class="sf-gallery__caption sf-gallery__caption--on">{{ $caption }}</span>
@endif
</a>
@if (! $sfOverlay && $caption !== '')
<figcaption class="sf-gallery__caption">{{ $caption }}</figcaption>
@endif
</figure></li>
@endforeach
</ul>
@endcomponent
@endif

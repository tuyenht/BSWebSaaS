{{--
    Video block: an optional heading over a click-to-load YouTube/Vimeo player (partials.sf-video).
    Only an address VideoEmbed recognises renders; anything else (or none) renders nothing.
--}}
@php
    $video = is_string($props['url'] ?? null) ? \App\Modules\Shared\Support\VideoEmbed::player($props['url']) : null;
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $poster = is_string($props['poster'] ?? null) && $props['poster'] !== '' ? $props['poster'] : null;
@endphp
@if ($video !== null)
@component('theme::partials.sf-band', ['bg' => 'none', 'class' => 'sf-video-block'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<div class="sf-video-block__frame">
@include('theme::partials.sf-video', [
    'video' => $video,
    'title' => $heading !== '' ? $heading : __('storefront.video_frame_'.$video['provider']),
    'poster' => $poster,
    'srcset' => $poster !== null ? (($srcsets ?? [])[$poster] ?? null) : null,
])
</div>
@endcomponent
@endif

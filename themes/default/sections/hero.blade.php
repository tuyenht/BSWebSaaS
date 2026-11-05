{{--
    Hero section. The layout's page__title h1 is the single document heading (made sr-only
    via CSS :has(.hero) when this section is present). The hero heading here is h2 so there
    is exactly one h1 per page regardless of whether a hero is rendered.

    image_url (type:url, pre-resolved by SeedAnVinhContent via MediaUrlResolver) renders as a
    real <img> tag — not a CSS background — so fetchpriority="high" signals LCP priority to
    the browser. alt="" marks it as decorative; the h2 heading is the textual equivalent.

    bg_video is the background video already resolved by BlockRenderer ({url, type} of a video of
    this site, else null → the plain hero). The <video> sits where the image sits, under the same
    band colour, with no source and no autoplay: sf-bgvideo.js adds the source and starts it after
    the page has loaded, only when the visitor allows motion and is not saving data, and shows the
    pause/play control (hidden until then). $videoBand ('dark' | 'light') picks the control's look.
    The video's @if/@endif lines start at column 0 so a hero without a video prints byte for byte
    what it printed before (UnchangedRenderTest).
--}}
@php
    $bgVideo = is_array($props['bg_video'] ?? null) ? $props['bg_video'] : null;
    $cta1 = \App\Modules\Theme\Components\ButtonLink::of($props, 'cta_url');
    $cta2 = \App\Modules\Theme\Components\ButtonLink::of($props, 'cta2_url');
@endphp
<section class="hero{{ $bgVideo !== null ? ' hero--video' : '' }}">
    @if (! empty($props['image_url']))
        <img class="hero__bg"
             src="{{ $props['image_url'] }}"
             alt=""
             width="1600"
             height="900"
             loading="eager"
             fetchpriority="high">
    @endif
@if ($bgVideo !== null)
        <video class="hero__bg hero__video" data-sf-bgvideo data-src="{{ $bgVideo['url'] }}" data-type="{{ $bgVideo['type'] }}" muted loop playsinline preload="none" disablepictureinpicture disableremoteplayback aria-hidden="true"@if (! empty($props['image_url'])) poster="{{ $props['image_url'] }}"@endif></video>
@endif
    <div class="hero__content">
        @if (! empty($props['heading']))
            <h2 class="hero__heading">{{ $props['heading'] }}</h2>
        @endif
        @if (! empty($props['subheading']))
            <p class="hero__subheading">{{ $props['subheading'] }}</p>
        @endif
        @if (! empty($props['cta_label']) && $cta1 !== null)
            <a class="hero__cta" href="{{ $cta1['href'] }}"@if ($cta1['blank']) target="_blank" rel="noopener"@elseif ($cta1['download']) download=""@endif>{{ $props['cta_label'] }}@if ($cta1['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
        @endif
        @if (! empty($props['cta2_label']) && $cta2 !== null)
            <a class="hero__cta hero__cta--secondary" href="{{ $cta2['href'] }}"@if ($cta2['blank']) target="_blank" rel="noopener"@elseif ($cta2['download']) download=""@endif>{{ $props['cta2_label'] }}@if ($cta2['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
        @endif
    </div>
@if ($bgVideo !== null)
        <button type="button" class="hero__vctl" data-band="{{ ($videoBand ?? 'dark') === 'light' ? 'light' : 'dark' }}" data-pause="{{ __('storefront.bgvideo_pause') }}" data-play="{{ __('storefront.bgvideo_play') }}" hidden>
            <svg class="hero__vctl-icon hero__vctl-icon--pause" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
            <svg class="hero__vctl-icon hero__vctl-icon--play" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 5.14v13.72a.5.5 0 0 0 .76.43l10.6-6.86a.5.5 0 0 0 0-.86L8.76 4.71a.5.5 0 0 0-.76.43z"/></svg>
            <span class="hero__vctl-label">{{ __('storefront.bgvideo_pause') }}</span>
        </button>
@endif
</section>

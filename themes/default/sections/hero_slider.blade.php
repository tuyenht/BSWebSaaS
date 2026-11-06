{{--
    Hero slider (WAI-ARIA APG carousel). Up to five slides; a slide with neither a photo nor a
    heading is skipped, none renders nothing, and one renders a still band with no controls (and
    the page loads no slider script). Slide headings are h2: the page's h1 stays the layout's.
    Every slide sits in the same grid cell, so the box is as tall as the tallest slide from the
    first paint and switching never moves the page. Slide 1's photo is the LCP image (eager, high
    priority); the photos of the others sit in a `hidden` wrapper, which no browser fetches, and
    the script reveals each one shortly before it is shown. The controls are rendered `hidden` and
    only the script shows them, so a page without JS never shows dead buttons. Their texts come
    from the server in attributes (no strings in the script, no inline script).
    `autoplay` is in seconds (0 = off, else 5–15); `overlay` darkens the photo 55–80% (default 60):
    white text over a white photo still reads 4.76:1 at 55%.
--}}
@php
    $sfSlides = [];
    foreach ((array) ($props['slides'] ?? []) as $sfSlide) {
        $sfSlide = (array) $sfSlide;
        $sfRaw = is_string($sfSlide['image_url'] ?? null) ? $sfSlide['image_url'] : null;
        $sfImage = \App\Modules\Shared\Support\SafeLink::href($sfRaw);
        $sfHeading = \App\Modules\Theme\Components\SectionProps::text($sfSlide, 'heading');
        if ($sfImage === null && $sfHeading === '') {
            continue;
        }
        $sfSlides[] = [
            'image' => $sfImage,
            'srcset' => $sfImage !== null && $sfRaw !== null ? (($srcsets ?? [])[$sfRaw] ?? null) : null,
            'heading' => $sfHeading,
            'subheading' => \App\Modules\Theme\Components\SectionProps::text($sfSlide, 'subheading'),
        ];
        if (count($sfSlides) === 5) {
            break;
        }
    }
    $sfCount = count($sfSlides);
    $sfAutoplay = \App\Modules\Theme\Components\SectionProps::clampInt($props, 'autoplay', 0, 15, 0);
    $sfInterval = $sfCount > 1 && $sfAutoplay > 0 ? max(5, $sfAutoplay) * 1000 : 0;
    $sfOverlay = \App\Modules\Theme\Components\SectionProps::clampInt($props, 'overlay', 55, 80, 60);
    $sfCta1 = \App\Modules\Theme\Components\ButtonLink::of($props, 'cta_url');
    $sfCta2 = \App\Modules\Theme\Components\ButtonLink::of($props, 'cta2_url');
    $sfCta1Label = \App\Modules\Theme\Components\SectionProps::text($props, 'cta_label');
    $sfCta2Label = \App\Modules\Theme\Components\SectionProps::text($props, 'cta2_label');
@endphp
@if ($sfCount > 0)
<section class="sf-slider"@if ($sfCount > 1) data-sf-slider data-interval="{{ $sfInterval }}"@endif aria-roledescription="carousel" aria-label="{{ __('storefront.slider') }}">
@if ($sfCount > 1)
    <div class="sf-slider__controls" hidden>
@if ($sfInterval > 0)
        <button type="button" class="sf-slider__btn sf-slider__pause" data-sf-pause aria-label="{{ __('storefront.slide_pause') }}" data-label-pause="{{ __('storefront.slide_pause') }}" data-label-play="{{ __('storefront.slide_play') }}">
            <svg class="sf-slider__icon-pause" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3v14H7zM14 5h3v14h-3z"/></svg>
            <svg class="sf-slider__icon-play" width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>
        </button>
@endif
        <button type="button" class="sf-slider__btn sf-slider__prev" data-sf-prev aria-label="{{ __('storefront.slide_prev') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 19l-7-7 7-7"/></svg>
        </button>
        <button type="button" class="sf-slider__btn sf-slider__next" data-sf-next aria-label="{{ __('storefront.slide_next') }}">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
        </button>
        <div class="sf-slider__dots">
@foreach ($sfSlides as $sfIndex => $sfSlide)
            <button type="button" class="sf-slider__dot" data-sf-dot="{{ $sfIndex }}" aria-label="{{ __('storefront.slide_go', ['n' => $sfIndex + 1]) }}"@if ($sfIndex === 0) aria-current="true"@endif><span class="sf-slider__dot-mark"></span></button>
@endforeach
        </div>
    </div>
@endif
    <div class="sf-slider__viewport" aria-live="off">
@foreach ($sfSlides as $sfIndex => $sfSlide)
        <div class="sf-slide{{ $sfIndex === 0 ? ' is-active' : '' }}" role="group" aria-roledescription="slide" aria-label="{{ __('storefront.slide_of', ['n' => $sfIndex + 1, 'total' => $sfCount]) }}">
@if ($sfSlide['image'] !== null)
            <div class="sf-slide__media"@if ($sfIndex > 0) hidden @endif>
                @include('theme::partials.sf-img', ['src' => $sfSlide['image'], 'srcset' => $sfSlide['srcset'], 'sizes' => '100vw', 'w' => 1600, 'h' => 900, 'priority' => $sfIndex === 0 && ! empty($props['_high']) ? 'high' : 'lazy', 'class' => 'sf-slide__img'])
            </div>
            <div class="sf-slide__overlay" style="opacity:{{ $sfOverlay / 100 }}"></div>
@endif
            <div class="sf-slide__content">
@if ($sfSlide['heading'] !== '')
                <h2 class="sf-slide__title">{{ $sfSlide['heading'] }}</h2>
@endif
@if ($sfSlide['subheading'] !== '')
                <p class="sf-slide__text">{{ $sfSlide['subheading'] }}</p>
@endif
@if (($sfCta1 !== null && $sfCta1Label !== '') || ($sfCta2 !== null && $sfCta2Label !== ''))
                <div class="sf-slide__ctas">
@if ($sfCta1 !== null && $sfCta1Label !== '')
                    <a class="sf-slide__cta" href="{{ $sfCta1['href'] }}"@if ($sfCta1['blank']) target="_blank" rel="noopener"@elseif ($sfCta1['download']) download=""@endif>{{ $sfCta1Label }}@if ($sfCta1['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
@endif
@if ($sfCta2 !== null && $sfCta2Label !== '')
                    <a class="sf-slide__cta sf-slide__cta--accent" href="{{ $sfCta2['href'] }}"@if ($sfCta2['blank']) target="_blank" rel="noopener"@elseif ($sfCta2['download']) download=""@endif>{{ $sfCta2Label }}@if ($sfCta2['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
@endif
                </div>
@endif
            </div>
        </div>
@endforeach
    </div>
</section>
@endif

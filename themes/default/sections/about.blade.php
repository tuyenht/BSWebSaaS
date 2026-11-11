{{--
    About section. The about__image is the above-fold LCP candidate on inner pages (gioi-thieu,
    home scroll-area): as the page's first image it gets loading="eager" and fetchpriority="high".
    Its frame (about__media) reserves the space of a cut-to-fit photo; a portrait photo shown whole
    reserves it by its stored width/height (no CLS). alt is the section heading. A portrait photo
    links to itself with a "tap to enlarge" line: the photo viewer (sf-viewer.js) opens it, or the
    plain link does without script.

    The letter look: the first paragraph is the greeting (bold) and, from three paragraphs up, the last
    two are the signature (name, then title); the author types plain paragraphs only. Every saved text
    is printed escaped. The showcase look puts a rule under the heading and ends in a dark pill button
    with an arrow (a file to download gets an outlined button with the download glyph instead). `bg`
    (none, surface, tint) paints a band behind the block. The photo focus is a fixed class from a lookup table, never the saved string.
--}}
@php
    $sfVariant = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::ABOUT_VARIANTS);
    $sfLetter = $sfVariant === 'letter';
    $sfShowcase = $sfVariant === 'showcase';
    $sfBand = \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::ABOUT_BG);
    $sfFocus = ['top' => ' about__image--top', 'bottom' => ' about__image--bottom'][\App\Modules\Theme\Components\SectionProps::choice($props, 'image_focus', \App\Modules\Theme\Components\SectionProps::ABOUT_IMAGE_FOCUS)] ?? '';
    $sfParagraphs = array_values(array_filter((array) ($props['paragraphs'] ?? []), fn ($sfP) => is_string($sfP) && trim($sfP) !== ''));
    $sfSigned = $sfLetter && count($sfParagraphs) >= 3;
    $sfBody = $sfSigned ? array_slice($sfParagraphs, 0, -2) : $sfParagraphs;
    $sfSignature = $sfSigned ? array_slice($sfParagraphs, -2) : [];
    $sfTiles = [];
    foreach (\App\Modules\Theme\Components\SectionProps::items($props, 'links') as $sfItem) {
        $sfHref = \App\Modules\Theme\Components\ButtonLink::of($sfItem, 'url');
        $sfLabel = \App\Modules\Theme\Components\SectionProps::text($sfItem, 'label');
        if ($sfHref !== null && $sfLabel !== '') {
            $sfTiles[] = ['href' => $sfHref, 'label' => $sfLabel, 'icon' => \App\Modules\Theme\Components\SectionProps::choice($sfItem, 'icon', \App\Modules\Theme\Components\SectionProps::QUICK_LINK_ICONS)];
        }
    }
    $sfTiles = array_slice($sfTiles, 0, \App\Modules\Theme\Components\SectionProps::ABOUT_LINKS_MAX_ITEMS);
    // The photo's stored size (media library) reserves its space; a portrait one (a certificate) is shown whole.
    $sfSrc = is_string($props['image_url'] ?? null) && $props['image_url'] !== '' ? $props['image_url'] : null;
    $sfSize = $sfSrc !== null ? (($dims ?? [])[$sfSrc] ?? null) : null;
    $sfSize = is_array($sfSize) && ($sfSize[0] ?? 0) > 0 && ($sfSize[1] ?? 0) > 0 ? $sfSize : null;
    $sfWhole = $sfSize !== null && $sfSize[1] > $sfSize[0];
    // A portrait photo opens larger: a link to itself (a safe link only), with a tap-to-enlarge line.
    $sfZoom = $sfWhole ? \App\Modules\Shared\Support\SafeLink::href($sfSrc) : null;
    // The files offered (server-built, keyed by the photo's address) and the width the frame draws them at.
    // A whole photo: its frame is at most 28rem with 0.5rem padding, and from 768px sits in the 2fr column
    // (.about--whole). A cut-to-fit photo: the full width less the section padding on a phone, half the
    // grid from 768px (the container is 80rem; padding 1.5rem, 2rem from 1024px; gap 3rem); the frame
    // crops the photo to fit, so it may need it up to a quarter wider (the 0.625 is 0.5 x 1.25).
    $sfSrcset = $sfSrc !== null ? (($srcsets ?? [])[$sfSrc] ?? null) : null;
    $sfSizes = $sfWhole
        ? '(min-width:768px) min(27rem, calc((100vw - 6rem) * 0.4 - 1rem)), min(27rem, calc(100vw - 4rem))'
        : '(min-width:1280px) 46rem, (min-width:1024px) calc((100vw - 7rem) * 0.625), (min-width:768px) calc((100vw - 6rem) * 0.625), calc(100vw - 3rem)';
@endphp
@if ($sfBand !== 'none')
<div class="about-band about-band--{{ $sfBand }}">
@endif
<section class="about{{ $sfLetter ? ' about--letter' : '' }}{{ $sfShowcase ? ' about--showcase' : '' }}{{ $sfWhole ? ' about--whole' : '' }}">
    <div class="about__body">
        @if (($sfHeading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading')) !== '')
            <h2 class="about__heading">{{ $sfHeading }}</h2>
        @endif
        @foreach ($sfLetter ? $sfBody : (array) ($props['paragraphs'] ?? []) as $sfIndex => $paragraph)
            <p class="about__paragraph{{ $sfLetter && $sfIndex === 0 ? ' about__paragraph--greeting' : '' }}">{{ $paragraph }}</p>
        @endforeach@if ($sfSignature !== [])
            <div class="about__signature"><p class="about__signer">{{ $sfSignature[0] }}</p><p class="about__signer-role">{{ $sfSignature[1] }}</p></div>
        @endif
        @if (! empty($props['cta_label']) && ($cta = \App\Modules\Theme\Components\ButtonLink::of($props, 'cta_url')) !== null)
            <a class="about__cta{{ $cta['download'] ? ' about__cta--download' : '' }}" href="{{ $cta['href'] }}"@if ($cta['blank']) target="_blank" rel="noopener"@elseif ($cta['download']) download=""@endif>@if ($cta['download'])<svg class="about__cta-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>@endif{{ $props['cta_label'] }}@if ($sfShowcase && ! $cta['download'])<span class="about__cta-arrow" aria-hidden="true">→</span>@endif@if ($cta['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
        @endif@if ($sfTiles !== [])
            <ul class="about__links">
                @foreach ($sfTiles as $sfTile)
                    <li><a class="about__link{{ $loop->first ? ' about__link--primary' : '' }}" href="{{ $sfTile['href']['href'] }}"@if ($sfTile['href']['blank']) target="_blank" rel="noopener"@elseif ($sfTile['href']['download']) download=""@endif><span class="about__link-icon">@include('theme::partials.sf-icon', ['name' => $sfTile['icon'], 'size' => 24])</span><span class="about__link-label">{{ $sfTile['label'] }}</span>@if ($sfTile['href']['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a></li>
                @endforeach
            </ul>
        @endif
    </div>
    @if ($sfSrc !== null)
        {{-- A landscape photo fills a frame as tall as the text (cut to fit, 20–42rem, it stays in view while
             the text scrolls); a portrait one is shown whole in a white frame. loading="eager" +
             fetchpriority="high" when it is the page's first image (LCP). --}}
        <div class="about__media{{ $sfWhole ? ' about__media--whole' : '' }}">
            @if ($sfZoom !== null)<a class="about__zoom" href="{{ $sfZoom }}" data-sf-zoom="single">@endif<img class="about__image{{ $sfWhole ? '' : $sfFocus }}"
                 src="{{ $sfSrc }}"@if (! empty($sfSrcset)) srcset="{{ $sfSrcset }}" sizes="{{ $sfSizes }}"@endif

                 alt="{{ $sfHeading }}"
                 width="{{ $sfSize[0] ?? 640 }}"
                 height="{{ $sfSize[1] ?? 480 }}"
                 loading="{{ ! empty($props['_high']) ? 'eager' : 'lazy' }}"@if (! empty($props['_high'])) fetchpriority="high"@endif>
@if ($sfZoom !== null)<span class="about__zoom-hint">{{ __('storefront.zoom_hint') }}</span></a>@endif        </div>
    @endif
</section>
@if ($sfBand !== 'none')
</div>
@endif

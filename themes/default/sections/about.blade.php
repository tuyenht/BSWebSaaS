{{--
    About section. The about__image is the above-fold LCP candidate on inner pages (gioi-thieu,
    home scroll-area): as the page's first image it gets loading="eager" and fetchpriority="high".
    Its frame (about__media) reserves the space of a cut-to-fit photo; a portrait photo shown whole
    reserves it by its stored width/height (no CLS). alt is the section heading.

    The letter look: the first paragraph is the greeting (bold) and, from three paragraphs up, the last
    two are the signature (name, then title); the author types plain paragraphs only. Every saved text
    is printed escaped. The photo focus is a fixed class from a lookup table, never the saved string.
--}}
@php
    $sfLetter = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::ABOUT_VARIANTS) === 'letter';
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
@endphp
<section class="about{{ $sfLetter ? ' about--letter' : '' }}{{ $sfWhole ? ' about--whole' : '' }}">
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
            <a class="about__cta" href="{{ $cta['href'] }}"@if ($cta['blank']) target="_blank" rel="noopener"@elseif ($cta['download']) download=""@endif>{{ $props['cta_label'] }}@if ($cta['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
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
            <img class="about__image{{ $sfWhole ? '' : $sfFocus }}"
                 src="{{ $sfSrc }}"
                 alt="{{ $sfHeading }}"
                 width="{{ $sfSize[0] ?? 640 }}"
                 height="{{ $sfSize[1] ?? 480 }}"
                 loading="{{ ! empty($props['_high']) ? 'eager' : 'lazy' }}"@if (! empty($props['_high'])) fetchpriority="high"@endif>
        </div>
    @endif
</section>

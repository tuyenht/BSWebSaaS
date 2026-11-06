{{--
    About section. The about__image is the above-fold LCP candidate on inner pages (gioi-thieu,
    home scroll-area) so it uses loading="eager" and fetchpriority="high" — the browser fetches
    it at the highest priority without waiting for lazy thresholds. width/height reserve space
    (no CLS). alt is the section heading (meaningful descriptive text, not empty).

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
@endphp
<section class="about{{ $sfLetter ? ' about--letter' : '' }}">
    <div class="about__body">
        @if (! empty($props['heading']))
            <h2 class="about__heading">{{ $props['heading'] }}</h2>
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
    @if (! empty($props['image_url']))
        {{-- Option C image URL; loading="eager" + fetchpriority="high" for LCP. --}}
        <img class="about__image{{ $sfFocus }}"
             src="{{ $props['image_url'] }}"
             alt="{{ $props['heading'] ?? '' }}"
             width="640"
             height="480"
             loading="{{ ! empty($props['_high']) ? 'eager' : 'lazy' }}"@if (! empty($props['_high'])) fetchpriority="high"@endif>
    @endif
</section>

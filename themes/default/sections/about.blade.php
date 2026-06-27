{{--
    About section. The about__image is the above-fold LCP candidate on inner pages (gioi-thieu,
    home scroll-area) so it uses loading="eager" and fetchpriority="high" — the browser fetches
    it at the highest priority without waiting for lazy thresholds. width/height reserve space
    (no CLS). alt is the section heading (meaningful descriptive text, not empty).
--}}
<section class="about">
    <div class="about__body">
        @if (! empty($props['heading']))
            <h2 class="about__heading">{{ $props['heading'] }}</h2>
        @endif
        @foreach ((array) ($props['paragraphs'] ?? []) as $paragraph)
            <p class="about__paragraph">{{ $paragraph }}</p>
        @endforeach
        @if (! empty($props['cta_label']) && ! empty($props['cta_url']))
            <a class="about__cta" href="{{ $props['cta_url'] }}">{{ $props['cta_label'] }}</a>
        @endif
    </div>
    @if (! empty($props['image_url']))
        {{-- Option C image URL; loading="eager" + fetchpriority="high" for LCP. --}}
        <img class="about__image"
             src="{{ $props['image_url'] }}"
             alt="{{ $props['heading'] ?? '' }}"
             width="640"
             height="480"
             loading="eager"
             fetchpriority="high">
    @endif
</section>

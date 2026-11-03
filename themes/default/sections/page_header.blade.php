{{--
    Page title band (inner pages). When it leads the page it carries the page's single h1 (the
    layout skips its own — PageRenderer marks it `_lead`); a later band is an h2. The background is
    a real <img> (not a CSS background) so fetchpriority="high" reaches the LCP image; alt="" since
    the heading is its text. No image (or one whose URL is not a safe web link) → a solid ink band. The overlay darkens the photo by
    55–80% (default 60): at 55% white text over a white photo still reads 4.76:1.
--}}
@php
    $overlay = is_numeric($props['overlay'] ?? null) ? (int) $props['overlay'] : 60;
    $overlay = max(55, min(80, $overlay));
    $heading = ! empty($props['_lead']) ? 'h1' : 'h2';
    $image = \App\Modules\Shared\Support\SafeLink::href($props['image_url'] ?? null);
@endphp
<section class="page-band">
    @if ($image !== null)
        <img class="page-band__bg"
             src="{{ $image }}"
             alt=""
             width="1600"
             height="400"
             loading="eager"
             fetchpriority="high">
        <div class="page-band__overlay" style="opacity:{{ $overlay / 100 }}"></div>
    @endif
    <div class="page-band__content">
        @if (! empty($props['title']))
            <{{ $heading }} class="page-band__title">{{ $props['title'] }}</{{ $heading }}>
        @endif
        @if (! empty($props['subtitle']))
            <p class="page-band__subtitle">{{ $props['subtitle'] }}</p>
        @endif
    </div>
</section>

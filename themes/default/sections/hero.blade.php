{{--
    Hero section. The layout's page__title h1 is the single document heading (made sr-only
    via CSS :has(.hero) when this section is present). The hero heading here is h2 so there
    is exactly one h1 per page regardless of whether a hero is rendered.

    image_url (type:url, pre-resolved by SeedAnVinhContent via MediaUrlResolver) renders as a
    real <img> tag — not a CSS background — so fetchpriority="high" signals LCP priority to
    the browser. alt="" marks it as decorative; the h2 heading is the textual equivalent.
--}}
<section class="hero">
    @if (! empty($props['image_url']))
        <img class="hero__bg"
             src="{{ $props['image_url'] }}"
             alt=""
             width="1600"
             height="900"
             loading="eager"
             fetchpriority="high">
    @endif
    <div class="hero__content">
        @if (! empty($props['heading']))
            <h2 class="hero__heading">{{ $props['heading'] }}</h2>
        @endif
        @if (! empty($props['subheading']))
            <p class="hero__subheading">{{ $props['subheading'] }}</p>
        @endif
        @if (! empty($props['cta_label']))
            <a class="hero__cta" href="{{ $props['cta_url'] ?? '#' }}">{{ $props['cta_label'] }}</a>
        @endif
        @if (! empty($props['cta2_label']))
            <a class="hero__cta hero__cta--secondary" href="{{ $props['cta2_url'] ?? '#' }}">{{ $props['cta2_label'] }}</a>
        @endif
    </div>
</section>

<section class="hero">
    <h1>{{ $props['heading'] ?? '' }}</h1>
    @if (! empty($props['subheading']))
        <p class="hero__subheading">{{ $props['subheading'] }}</p>
    @endif
    @if (! empty($props['cta_label']))
        <a class="hero__cta" href="{{ $props['cta_url'] ?? '#' }}">{{ $props['cta_label'] }}</a>
    @endif
    @if (! empty($props['cta2_label']))
        <a class="hero__cta hero__cta--secondary" href="{{ $props['cta2_url'] ?? '#' }}">{{ $props['cta2_label'] }}</a>
    @endif
</section>

<section class="hero">
    <h1>{{ $props['heading'] ?? '' }}</h1>
    @if (! empty($props['subheading']))
        <p class="hero__subheading">{{ $props['subheading'] }}</p>
    @endif
    @if (! empty($props['cta_label']))
        <a class="hero__cta" href="{{ $props['cta_url'] ?? '#' }}">{{ $props['cta_label'] }}</a>
    @endif
</section>

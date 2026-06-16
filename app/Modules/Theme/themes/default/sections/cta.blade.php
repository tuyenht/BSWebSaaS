<section class="cta">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
    @if (! empty($props['button_label']))
        <a class="cta__button" href="{{ $props['button_url'] ?? '#' }}">{{ $props['button_label'] }}</a>
    @endif
</section>

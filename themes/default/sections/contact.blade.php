<section class="contact">
    @if (! empty($props['heading']))
        <h2 class="contact__heading">{{ $props['heading'] }}</h2>
    @endif
    @if (! empty($props['address']))
        <p class="contact__address">{{ $props['address'] }}</p>
    @endif
    @if (! empty($props['phone']))
        {{-- tel: built as a concat INSIDE {{ }} so the drift sentinel still sees $props['phone']
             (type:url) — a "tel:{{ … }}" prefix outside the braces would evade it. --}}
        <a class="contact__phone" href="{{ 'tel:'.$props['phone'] }}">{{ $props['phone'] }}</a>
    @endif
    @if (! empty($props['email']))
        <a class="contact__email" href="{{ 'mailto:'.$props['email'] }}">{{ $props['email'] }}</a>
    @endif
    @if (! empty($props['map_embed']))
        {{-- Tenant's own map embed URL (scheme-checked at save); lazy + fixed box reserves space (no CLS). --}}
        <iframe class="contact__map" src="{{ $props['map_embed'] }}" title="{{ $props['heading'] ?? 'Bản đồ' }}" width="600" height="450" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    @endif
</section>

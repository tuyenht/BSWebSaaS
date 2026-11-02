@if (\App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::CTA_VARIANTS) === 'rounded')
{{-- The rounded look (opt-in): a centered dark band with a pill button; `text` is its sub-line. --}}
<section class="cta cta--rounded">
    <div class="sf-container cta__inner">
@if (! empty($props['heading']))
        <h2 class="cta__title">{{ $props['heading'] }}</h2>
@endif
@if (! empty($props['text']))
        <p class="cta__text">{{ $props['text'] }}</p>
@endif
@if (! empty($props['button_label']) && \App\Modules\Shared\Support\SafeLink::href($props['button_url'] ?? null) !== null)
        <a class="cta__button cta__button--pill" href="{{ \App\Modules\Shared\Support\SafeLink::href($props['button_url']) }}">{{ $props['button_label'] }}</a>
@endif
    </div>
</section>
@else
<section class="cta">
    @if (! empty($props['heading']))
        <h2>{{ $props['heading'] }}</h2>
    @endif
@if (! empty($props['text']))
    <p class="cta__text">{{ $props['text'] }}</p>
@endif
    @if (! empty($props['button_label']))
        <a class="cta__button" href="{{ $props['button_url'] ?? '#' }}">{{ $props['button_label'] }}</a>
    @endif
</section>
@endif

@php($button = \App\Modules\Theme\Components\ButtonLink::of($props, 'button_url'))
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
@if (! empty($props['button_label']) && $button !== null)
        <a class="cta__button cta__button--pill" href="{{ $button['href'] }}"@if ($button['blank']) target="_blank" rel="noopener"@elseif ($button['download']) download=""@endif>{{ $props['button_label'] }}@if ($button['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
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
    @if (! empty($props['button_label']) && $button !== null)
        <a class="cta__button" href="{{ $button['href'] }}"@if ($button['blank']) target="_blank" rel="noopener"@elseif ($button['download']) download=""@endif>{{ $props['button_label'] }}@if ($button['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
    @endif
</section>
@endif

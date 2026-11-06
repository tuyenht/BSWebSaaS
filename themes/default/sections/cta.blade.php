@php
    $button = \App\Modules\Theme\Components\ButtonLink::of($props, 'button_url');
    $button2 = \App\Modules\Theme\Components\ButtonLink::of($props, 'button2_url');
    $hasButton2 = ! empty($props['button2_label']) && $button2 !== null;
    $rounded = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::CTA_VARIANTS) === 'rounded';
    // The stats beside the text exist with the default look only; the rounded band ignores them.
    $side = ! $rounded && \App\Modules\Theme\Components\SectionProps::choice($props, 'side', \App\Modules\Theme\Components\SectionProps::CTA_SIDES) === 'stats'
        ? \App\Modules\Theme\Components\SectionProps::statEntries($props, 'side_items', \App\Modules\Theme\Components\SectionProps::CTA_SIDE_MAX_ITEMS, \App\Modules\Theme\Components\SectionProps::currentYear())
        : [];
@endphp
@if ($rounded)
{{-- The rounded look (opt-in): a centered dark band with a pill button; `text` is its sub-line. --}}
<section class="cta cta--rounded">
    <div class="sf-container cta__inner">
@if (! empty($props['heading']))
        <h2 class="cta__title">{{ $props['heading'] }}</h2>
@endif
@if (! empty($props['text']))
        <p class="cta__text">{{ $props['text'] }}</p>
@endif
@if ($hasButton2)
        <div class="cta__actions">
@endif
@if (! empty($props['button_label']) && $button !== null)
        <a class="cta__button cta__button--pill" href="{{ $button['href'] }}"@if ($button['blank']) target="_blank" rel="noopener"@elseif ($button['download']) download=""@endif>{{ $props['button_label'] }}@if ($button['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
@endif
@if ($hasButton2)
        <a class="cta__button cta__button--pill cta__button--outline" href="{{ $button2['href'] }}"@if ($button2['blank']) target="_blank" rel="noopener"@elseif ($button2['download']) download=""@endif>{{ $props['button2_label'] }}@if ($button2['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
        </div>
@endif
    </div>
</section>
@elseif ($side !== [])
{{-- The default look with up to three stats beside the text: 3/5 and 2/5 on a wide screen, stacked on a phone. --}}
<section class="cta cta--side">
    <div class="sf-container cta__split">
        <div class="cta__panel">
@if (! empty($props['heading']))
            <h2>{{ $props['heading'] }}</h2>
@endif
@if (! empty($props['text']))
            <p class="cta__text">{{ $props['text'] }}</p>
@endif
@if ($hasButton2 || (! empty($props['button_label']) && $button !== null))
            <div class="cta__actions">
@if (! empty($props['button_label']) && $button !== null)
                <a class="cta__button" href="{{ $button['href'] }}"@if ($button['blank']) target="_blank" rel="noopener"@elseif ($button['download']) download=""@endif>{{ $props['button_label'] }}@if ($button['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
@endif
@if ($hasButton2)
                <a class="cta__button cta__button--outline" href="{{ $button2['href'] }}"@if ($button2['blank']) target="_blank" rel="noopener"@elseif ($button2['download']) download=""@endif>{{ $props['button2_label'] }}@if ($button2['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
@endif
            </div>
@endif
        </div>
        <ul class="sf-stats__list cta__stats">
@include('theme::partials.sf-stat-items', ['stats' => $side])
        </ul>
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
@if ($hasButton2)
    <div class="cta__actions">
@endif
    @if (! empty($props['button_label']) && $button !== null)
        <a class="cta__button" href="{{ $button['href'] }}"@if ($button['blank']) target="_blank" rel="noopener"@elseif ($button['download']) download=""@endif>{{ $props['button_label'] }}@if ($button['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
    @endif
@if ($hasButton2)
        <a class="cta__button cta__button--outline" href="{{ $button2['href'] }}"@if ($button2['blank']) target="_blank" rel="noopener"@elseif ($button2['download']) download=""@endif>{{ $props['button2_label'] }}@if ($button2['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
    </div>
@endif
</section>
@endif

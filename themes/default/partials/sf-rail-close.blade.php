{{-- The rail's controls. The arrows sit outside the flow and are rendered hidden: only the script
     reveals them, so a page without JS shows no dead buttons. The Pause bar is rendered at its final
     size whenever the list rotates by itself, so nothing moves when the script shows its button (the
     script hides the button where it does not rotate, a phone's swipe row or a visitor who asked for
     less motion). The arrows draw 36px inside a 44px transparent hit area. --}}
    <div class="sf-rail__controls">
@if ($display['arrows'] !== 'none')
        <button type="button" class="sf-rail__btn sf-rail__prev" data-sf-rail-prev hidden aria-label="{{ __('storefront.rail_prev') }}" title="{{ __('storefront.rail_prev') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 19l-7-7 7-7"/></svg>
        </button>
        <button type="button" class="sf-rail__btn sf-rail__next" data-sf-rail-next hidden aria-label="{{ __('storefront.rail_next') }}" title="{{ __('storefront.rail_next') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
        </button>
@endif
@if ($display['interval'] > 0)
        <div class="sf-rail__bar{{ $display['bar'] !== '' ? ' sf-rail__bar--'.$display['bar'] : '' }}">
            <button type="button" class="sf-rail__btn sf-rail__pause" data-sf-rail-pause hidden aria-label="{{ __('storefront.rail_pause') }}" title="{{ __('storefront.rail_pause') }}" data-label-pause="{{ __('storefront.rail_pause') }}" data-label-play="{{ __('storefront.rail_play') }}">
                <svg class="sf-slider__icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3v14H7zM14 5h3v14h-3z"/></svg>
                <svg class="sf-slider__icon-play" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>
            </button>
        </div>
@endif
    </div>
</div>
{{-- A phone's swipe row says so under it (decoration: the row scrolls by itself); not where the arrows always show. --}}
@if (($display['mobile'] ?? '') === 'swipe' && ($display['arrows'] ?? '') !== 'always')
<p class="sf-rail__hint" aria-hidden="true">{{ __('storefront.swipe_hint') }}</p>
@endif

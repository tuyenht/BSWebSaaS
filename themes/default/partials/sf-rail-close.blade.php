{{-- The rail's controls, rendered hidden: only the script reveals them, so a page without JS shows no
     dead buttons. Pause is there whenever the rail rotates by itself (the script hides it where it
     does not, a phone's swipe row or a visitor who asked for less motion). The arrows draw 36px
     inside a 44px transparent hit area. --}}
    <div class="sf-rail__controls" hidden>
@if ($display['arrows'] !== 'none')
        <button type="button" class="sf-rail__btn sf-rail__prev" data-sf-rail-prev hidden aria-label="{{ __('storefront.slide_prev') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 19l-7-7 7-7"/></svg>
        </button>
        <button type="button" class="sf-rail__btn sf-rail__next" data-sf-rail-next hidden aria-label="{{ __('storefront.slide_next') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
        </button>
@endif
@if ($display['interval'] > 0)
        <div class="sf-rail__bar">
            <button type="button" class="sf-rail__btn sf-rail__pause" data-sf-rail-pause hidden aria-label="{{ __('storefront.slide_pause') }}" data-label-pause="{{ __('storefront.slide_pause') }}" data-label-play="{{ __('storefront.slide_play') }}">
                <svg class="sf-slider__icon-pause" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M7 5h3v14H7zM14 5h3v14h-3z"/></svg>
                <svg class="sf-slider__icon-play" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>
            </button>
        </div>
@endif
    </div>
</div>

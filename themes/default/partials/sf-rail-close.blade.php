{{-- The rail's controls: under 1440px the arrows float over the list's left and right edges, centred on the
     cards, with the dots alone in a row below; from 1440px the arrows stand beside the list, outside its cards,
     inside the window (sf-rail.css). They are rendered hidden: only the script reveals
     them, so a page without JS shows no dead buttons, and the script fills the dots. There is no pause button:
     the rotation pauses while a mouse is over the rail or focus is inside it. The arrows draw a disc inside a
     44px transparent hit area. No "swipe" line under a phone's row: the peeking card, the arrows and the dots say it. --}}
    <div class="sf-rail__controls">
@if ($display['arrows'] !== 'none')
        <button type="button" class="sf-rail__btn sf-rail__prev" data-sf-rail-prev hidden aria-label="{{ __('storefront.rail_prev') }}" title="{{ __('storefront.rail_prev') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15 19l-7-7 7-7"/></svg>
        </button>
        <span class="sf-rail__dots" data-sf-rail-dots hidden aria-hidden="true"></span>
        <button type="button" class="sf-rail__btn sf-rail__next" data-sf-rail-next hidden aria-label="{{ __('storefront.rail_next') }}" title="{{ __('storefront.rail_next') }}">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7"/></svg>
        </button>
@endif
    </div>
</div>

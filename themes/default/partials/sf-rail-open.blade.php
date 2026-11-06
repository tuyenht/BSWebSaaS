{{-- Opens the rail around a list block's items (a block with the display group): the script reads
     these data attributes; sf-rail-close prints the controls and closes it. $display = ListDisplay::plan(). --}}
<div class="sf-rail" data-sf-rail data-interval="{{ $display['interval'] }}" data-mobile="{{ $display['mobile'] }}" data-arrows="{{ $display['arrows'] }}" role="group" aria-roledescription="carousel" aria-label="{{ $label !== '' ? $label : __('storefront.list_rail') }}">

{{-- Opens the rail around a list block's items (a block with the display group): the script reads
     these data attributes; sf-rail-close prints the controls and closes it. $display = ListDisplay::plan().
     Only a list that rotates or scrolls (a slider on any width) is announced as a carousel; a grid is plain content. --}}
<div class="sf-rail" data-sf-rail data-interval="{{ $display['interval'] }}" data-mobile="{{ $display['mobile'] }}" data-arrows="{{ $display['arrows'] }}"@if ($display['scrolls']) role="group" aria-roledescription="{{ __('storefront.rail_roledescription') }}" aria-label="{{ trim($label) !== '' ? $label : __('storefront.list_rail') }}"@endif>

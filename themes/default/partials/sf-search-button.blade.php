{{--
    The header's search button: a 44×44 magnifier named "Tìm kiếm", with the name as a tooltip on hover
    and keyboard focus, in the header's own colours. A link to the search page that the storefront script
    turns into the button that opens the search panel. $place: `desk` (after the desktop menu), `solo`
    (a header with no menu), `corner` (the phone header's corner, alone or before the call).
--}}
<a class="sf-sbtn sf-sbtn--{{ $place }}" href="{{ $href }}" aria-label="{{ __('storefront.search.open') }}" aria-controls="sf-search-panel" data-search-open><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg><span class="sf-tip" aria-hidden="true">{{ __('storefront.search.open') }}</span></a>

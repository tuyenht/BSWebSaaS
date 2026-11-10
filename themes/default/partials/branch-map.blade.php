{{--
    The branches' map: a chooser of branches (when there are two or more), the map of the chosen one and,
    under it, its card — name, area and role, address, a line when the pin is only approximate, and four
    buttons (directions, call, open in Maps, copy the address).

    Needs `$places` (BranchMap::places: every address is built on the server from a fixed template,
    never from author text), `$callLink` (the site's hotline as a tel: link, or null) and `$uid` (a prefix
    for ids). Without JavaScript the first branch shows and the buttons are plain links; the chooser and
    the copy button are drawn hidden and only sf-branch-map.js reveals them, so a page without the script
    shows no dead controls. The script swaps the map's address and the card's text among the values printed
    on the chooser's buttons (data-*), so it never builds an address itself.
--}}
@php
    $sfFirst = $places[0];
    $sfMulti = count($places) > 1;
    $sfMeta = fn (array $place): string => implode(' · ', array_filter([$place['area'], $place['role']], fn (?string $part): bool => $part !== null && $part !== ''));
@endphp
<section class="sf-bmap" id="{{ $uid }}" data-sf-bmap data-copy-label="{{ __('storefront.branch_copy') }}" data-copied-label="{{ __('storefront.branch_copied') }}" data-copy-failed="{{ __('storefront.branch_copy_failed') }}" aria-labelledby="{{ $uid }}-h">
    <div class="sf-bmap__head">
        <h2 class="sf-bmap__title" id="{{ $uid }}-h">{{ __('storefront.branch_map_heading') }}</h2>
@if ($sfMulti)
        <span class="sf-bmap__hint">{{ __('storefront.branch_pick_hint') }}</span>
@endif
    </div>
@if ($sfMulti)
    <div class="sf-bmap__tabs" data-sf-bmap-tabs data-label="{{ __('storefront.branch_pick_label') }}" hidden>
@foreach ($places as $sfPlace)
        <button type="button" class="sf-bmap__tab" data-sf-bmap-tab
                data-embed="{{ $sfPlace['embed'] }}" data-dir="{{ $sfPlace['dir'] }}" data-open="{{ $sfPlace['open'] }}"
                data-name="{{ $sfPlace['full_name'] }}" data-meta="{{ $sfMeta($sfPlace) }}" data-addr="{{ $sfPlace['address'] }}"
                data-approx="{{ $sfPlace['approximate'] ? '1' : '' }}" data-title="{{ __('storefront.branch_map_title', ['name' => $sfPlace['full_name']]) }}">
            <span class="sf-bmap__tab-name">{{ $sfPlace['short'] }}</span>
@if ($sfPlace['locality'] !== '')
            <span class="sf-bmap__tab-sub">{{ $sfPlace['locality'] }}</span>
@endif
        </button>
@endforeach
    </div>
@endif
    <div class="sf-bmap__panel" data-sf-bmap-panel>
        <div class="sf-bmap__frame">
            <iframe class="sf-bmap__map" data-sf-bmap-frame src="{{ $sfFirst['embed'] }}" title="{{ __('storefront.branch_map_title', ['name' => $sfFirst['full_name']]) }}" width="600" height="340" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
        </div>
        <div class="sf-bmap__card">
            <div class="sf-bmap__name-row">
                <h3 class="sf-bmap__name" data-sf-bmap-name>{{ $sfFirst['full_name'] }}</h3>
                <span class="sf-bmap__meta" data-sf-bmap-meta>{{ $sfMeta($sfFirst) }}</span>
            </div>
            <p class="sf-bmap__addr" data-sf-bmap-addr {{ ($sfFirst['address'] ?? '') === '' ? 'hidden' : '' }}>{{ $sfFirst['address'] }}</p>
            <p class="sf-bmap__approx" data-sf-bmap-approx {{ $sfFirst['approximate'] ? '' : 'hidden' }}>{{ __('storefront.branch_approx') }}</p>
            <div class="sf-bmap__actions">
                <a class="sf-bmap__btn sf-bmap__btn--main" data-sf-bmap-dir href="{{ $sfFirst['dir'] }}" target="_blank" rel="noopener"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3l8 18-8-4-8 4 8-18z"/></svg>{{ __('storefront.branch_directions') }}<span class="sr-only"> {{ __('storefront.new_tab') }}</span></a>
@if ($callLink !== null)
                <a class="sf-bmap__btn sf-bmap__btn--dark" href="{{ $callLink }}">{{ __('storefront.branch_call') }}</a>
@endif
                <a class="sf-bmap__btn" data-sf-bmap-open href="{{ $sfFirst['open'] }}" target="_blank" rel="noopener">{{ __('storefront.branch_open_maps') }}<span class="sr-only"> {{ __('storefront.new_tab') }}</span></a>
                <button type="button" class="sf-bmap__btn" data-sf-bmap-copy hidden>{{ __('storefront.branch_copy') }}</button>
            </div>
            <p class="sr-only" role="status" aria-live="polite" data-sf-bmap-status></p>
        </div>
    </div>
</section>

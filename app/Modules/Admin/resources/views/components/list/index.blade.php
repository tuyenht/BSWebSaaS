@props([
    'label',
    'kind' => null,
    'busy' => null,
])
@php
    // The shared record list (stylesheet: resources/css/admin-list.css). It is a size container:
    // each row lays itself out by the LIST's width, not the window's — a card below 600, one record
    // per line to 900, a table with a header row from 900. Rows and header share one grid, so a
    // column header always stands over its cells. `select` is the select-all bar shown below 900,
    // where the header row (and its select-all box) is hidden. `data-hide="au"` drops the author
    // column and its name in the row's meta line too; `data-fold="au"` (written by the page's measuring
    // script, resources/js/admin-list-fold.js) moves the author from its column into the meta line. `kind="facts"` is a list of records with no
    // select box and short fact columns (f1–f3): a card below 600, a table with a header from 768.
    // `data-cols="people"` on a facts list lets f1 share the room with the name and sizes the status
    // and actions columns by the body text (the members list: role chips, a labelled button + ⋮).
    // `kind="catalog"` is the product list: the table gains the category, price, stock and label columns
    // (cat, price, stock, badge), which `data-hide` (space-separated keys) turns off one by one and the
    // list's width folds into the meta line (label below 80rem, category below 68.75rem).
    // `kind="posts"` is a post list: from 900 the table gains the category column (cat, `data-hide~="cat"` turns it
    // off) and each name its cover thumbnail (.list-th); below 900 the category stands in the meta line.
    // `busy` names the requests that reload the rows (wire:target: the search, filters, group, sort and
    // page, plus $refresh). While one of them is out the rows are covered by the loading rows of the
    // design (no shift: the cover stands over the rows' own room); one that does not come back gives
    // way to «Chưa tải được danh sách … Thử lại» (Alpine.data 'bsListState', resources/js/admin-list-state.js),
    // which stays until a request of the list succeeds (the page's own count line listens for the event
    // 'bs-list-failed' and hides while it shows, so it never states counts of rows that are no longer shown). Nothing is redrawn on a failure, so what the
    // member typed stays in its fields; «Thử lại» sends the failed request again.
    // Sizes live in the stylesheet only.
    $busyTargets = $busy === null ? [] : array_values(array_filter(array_map('trim', explode(',', $busy))));
@endphp
<div @if (in_array($kind, ['facts', 'catalog', 'posts'], true)) data-kind="{{ $kind }}" @endif {{ $attributes->merge(['class' => 'list-rows'.($busyTargets !== [] ? ' relative' : '')]) }}
    @if ($busyTargets !== []) x-data="bsListState({{ \Illuminate\Support\Js::from($busyTargets) }})" @endif>
    @isset($select)
        <div class="list-selbar">{{ $select }}</div>
    @endisset
    <div role="table" aria-label="{{ $label }}" class="list-g"@if ($busyTargets !== []) x-show="! failed" wire:loading.delay.short.attr="aria-busy" wire:target="{{ implode(',', $busyTargets) }}" @endif>{{ $slot }}</div>
    @if ($busyTargets !== [])
        <template x-if="failed">
            <div role="alert" class="list-fail">
                <x-bs-admin::list.state icon="ri-wifi-off" kind="error" :title="__('admin.list.load_failed')">
                    {{ __('admin.list.load_failed_text') }}
                    <x-slot:action>
                        <x-bs-admin::button color="primary" x-on:click="retry()">
                            <x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('admin.list.retry') }}
                        </x-bs-admin::button>
                    </x-slot:action>
                </x-bs-admin::list.state>
            </div>
        </template>
        <div wire:loading.delay.short.flex wire:target="{{ implode(',', $busyTargets) }}" class="list-busy hidden" aria-hidden="true">
            @for ($i = 0; $i < 6; $i++)
                <div class="list-skr"><span class="list-sk list-sk-1"></span><span class="list-sk list-sk-2"></span><span class="list-sk list-sk-3"></span></div>
            @endfor
        </div>
        <p class="sr-only" role="status"><span wire:loading.delay.short wire:target="{{ implode(',', $busyTargets) }}">{{ __('admin.list.loading') }}</span></p>
    @endif
</div>

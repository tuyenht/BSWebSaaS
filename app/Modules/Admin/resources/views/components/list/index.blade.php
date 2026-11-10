@props([
    'label',
    'kind' => null,
])
@php
    // The shared record list (stylesheet: resources/css/admin-list.css). It is a size container:
    // each row lays itself out by the LIST's width, not the window's — a card below 600, one record
    // per line to 900, a table with a header row from 900. Rows and header share one grid, so a
    // column header always stands over its cells. `select` is the select-all bar shown below 900,
    // where the header row (and its select-all box) is hidden. `data-hide="au"` drops the author
    // column and its name in the row's meta line too. `kind="facts"` is a list of records with no
    // select box and short fact columns (f1–f3): a card below 600, a table with a header from 768.
    // `kind="catalog"` is the product list: the table gains the category, price, stock and label columns
    // (cat, price, stock, badge), which `data-hide` (space-separated keys) turns off one by one and the
    // list's width folds into the meta line (label below 80rem, category below 68.75rem).
    // Sizes live in the stylesheet only.
@endphp
<div @if (in_array($kind, ['facts', 'catalog'], true)) data-kind="{{ $kind }}" @endif {{ $attributes->merge(['class' => 'list-rows']) }}>
    @isset($select)
        <div class="list-selbar">{{ $select }}</div>
    @endisset
    <div role="table" aria-label="{{ $label }}" class="list-g">{{ $slot }}</div>
</div>

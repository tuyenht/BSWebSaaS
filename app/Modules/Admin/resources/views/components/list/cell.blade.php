@props([
    'col' => 'main',
    'head' => false,
    'sort' => null,
])
@php
    // A cell of a shared list row, placed by its column: chk (select box), main (name + meta
    // line), st (status), au (author, from 1280 only), act (row buttons), full (one cell across
    // the row: the empty state), f1–f3 (the short fact columns of a list kind="facts"). `head` makes it a column header; `sort` (ascending, descending
    // or none) says how the list is sorted by it.
    $cols = ['chk', 'main', 'st', 'au', 'act', 'full', 'f1', 'f2', 'f3'];
    $col = in_array($col, $cols, true) ? $col : 'main';
@endphp
<div role="{{ $head ? 'columnheader' : 'cell' }}"@if ($head && $sort) aria-sort="{{ $sort }}"@endif {{ $attributes->merge(['class' => 'list-c list-c-'.$col.(str_starts_with($col, 'f') && $col !== 'full' ? ' list-c-f' : '')]) }}>{{ $slot }}</div>

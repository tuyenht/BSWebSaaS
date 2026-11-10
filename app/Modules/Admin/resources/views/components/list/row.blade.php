@props([
    'selected' => false,
])
@php
    // One record of a shared list. The cells inside are <x-bs-admin::list.cell col="…">; the
    // list's width picks the layout. A selected row takes the light accent tint.
@endphp
<div role="row"@if ($selected) data-sel="1"@endif {{ $attributes->merge(['class' => 'list-r']) }}>{{ $slot }}</div>

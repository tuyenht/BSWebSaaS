@props([
    'active' => false,
    'dir' => 'asc',
])
@php
    // A sortable column header's button: 44 high, the arrow shows the order when the list is
    // sorted by this column. The column header carries aria-sort.
    $glyph = ! $active ? 'chevron-up-down' : ($dir === 'asc' ? 'chevron-up' : 'chevron-down');
@endphp
<button type="button" class="list-sb"@if ($active) data-a="1"@endif{{ $attributes->isEmpty() ? '' : ' ' }}{{ $attributes }}>
    {{ $slot }}
    <x-bs-admin::icon :name="$glyph" class="list-sb-i" />
</button>

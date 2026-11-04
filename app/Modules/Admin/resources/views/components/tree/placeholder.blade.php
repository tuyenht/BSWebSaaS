@props([
    'level' => 1,
    'bad' => false,
])
@php
    // Where a dragged record will land, before it is let go: a dashed row at the level it will
    // take, with the label ("Sẽ thành mục con đầu tiên của «X» · cấp N"); red with the reason
    // when the drop is refused. The label wraps and is never clipped. Hidden from screen readers:
    // the same words are read from the tree's live line. The live tree moves one of these between
    // its rows; the gallery draws them in place.
@endphp
<div aria-hidden="true" data-lv="{{ $level }}" data-bad="{{ $bad ? '1' : '0' }}" {{ $attributes->merge(['class' => 'tree-ph']) }}>
    <x-bs-admin::icon name="ri-corner-down-right" class="tree-ph-i tree-ph-ok" />
    <x-bs-admin::icon name="ri-close-circle" class="tree-ph-i tree-ph-no" />
    <span data-tree-ph-text>{{ $slot }}</span>
</div>

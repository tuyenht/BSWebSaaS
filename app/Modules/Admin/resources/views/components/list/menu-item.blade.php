@props([
    'icon' => null,
    'danger' => false,
    'href' => null,
    'why' => null,
])
@php
    // One row of a list ⋮ menu: 40 high with a mouse (44 from the larger text sizes), 48 on touch.
    // `danger` paints it in the danger ink (a delete). `why` is the reason an item cannot be used
    // yet: the item stays (aria-disabled) and the reason sits on a line under it, not in a tooltip.
@endphp
@if ($href)
    <a href="{{ $href }}" role="menuitem"@if ($danger) data-t="danger"@endif {{ $attributes->merge(['class' => 'list-mi']) }}>
        @if ($icon)<x-bs-admin::icon :name="$icon" class="list-mi-i" />@endif
        <span>{{ $slot }}</span>
    </a>
@else
    <button type="button" role="menuitem"@if ($danger) data-t="danger"@endif @if ($why) aria-disabled="true" @endif {{ $attributes->merge(['class' => 'list-mi']) }}>
        @if ($icon)<x-bs-admin::icon :name="$icon" class="list-mi-i" />@endif
        <span>{{ $slot }}</span>
    </button>
    @if ($why)
        <span class="list-mwhy">{{ $why }}</span>
    @endif
@endif

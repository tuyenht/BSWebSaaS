@props([
    'label' => null,
    'wide' => false,
    'display' => 'grid',
])
@php
    // An icon button of the top bar (design board 01 .tb-ib; .tb-lb when `wide`, the language
    // button that may carry a code beside its glyph): 40px with a mouse at the default text size,
    // 44px from the next size up and on touch, an 8px corner, no fill at rest, the bar's button
    // hover fill (black 12% on the brand bar) under a pointer that hovers and a darker one while
    // pressed, the bar's own ink (white on the brand bar) and the bar's inset focus ring. The icons
    // inside take the board's size (19px, growing with the reader's text size up to 24px). `label`
    // names the button and gives it the same tooltip; `display` is its display class at each width
    // (e.g. "hidden md:grid" for a button that steps out on phones).
    $classes = [
        $display,
        'relative shrink-0 place-items-center rounded-lg text-topbar-fg-strong transition-colors',
        'hover:bg-topbar-button-hover active:bg-topbar-press',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg',
        '[&>svg]:size-[min(1.5rem,calc(1.1875rem+var(--user-text-boost)))] [&>svg]:shrink-0',
        $wide
            ? 'h-control min-w-control gap-1 px-2 any-pointer-coarse:h-hit any-pointer-coarse:min-w-hit'
            : 'size-control any-pointer-coarse:size-hit',
    ];
@endphp
<button type="button" @if ($label !== null) aria-label="{{ $label }}" title="{{ $label }}" @endif
        {{ $attributes->class($classes) }}>
    {{ $slot }}
</button>

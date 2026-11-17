@props([
    'title' => '',
    // the h1's id; the heading then takes focus from a script (tabindex -1)
    'titleId' => null,
])
@php
    // A list screen's title row: the name, then ONE group at the end of the row with the (?) help (named slot
    // `help`) first and the main button (the slot) right after it, 12px apart from 768 and 8px below.
    // From 768 the name keeps its words whole: when the name and the group do not fit on one line, the group
    // goes down whole, to the right. Below 768 the group's box is a pass-through (display: contents): the name
    // takes the room left, from a base of 6em, and wraps; the (?) stays beside it on the first line, and the
    // main button stays beside the (?) unless the name would get less than 6em — then the button takes a full
    // line of its own under them. The name's first line stands level with the 44px controls. The row is the
    // positioning box of a help popup below 768.
@endphp
<div {{ $attributes->merge(['class' => 'relative flex flex-wrap items-center gap-x-3 gap-y-2 max-md:items-start max-md:gap-x-2']) }}>
    <h1 @if ($titleId) id="{{ $titleId }}" tabindex="-1" @endif class="m-0 min-w-0 flex-[1_1_auto] text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere] max-md:max-w-[calc(100%-var(--spacing-hit)-0.5rem)] max-md:flex-[1000_1_6em] max-md:py-[max(0px,calc((var(--spacing-hit)-1.375em)/2))] max-md:[overflow-wrap:break-word] max-md:[text-wrap:balance]">{{ $title }}</h1>
    <div data-title-act class="ms-auto flex max-w-full flex-wrap items-center justify-end gap-3 max-md:contents max-md:[&>:not([data-title-help])]:flex-[1_0_auto]">
        @if (isset($help) && $help->isNotEmpty())
            <span data-title-help class="inline-flex shrink-0">{{ $help }}</span>
        @endif
        {{ $slot }}
    </div>
</div>

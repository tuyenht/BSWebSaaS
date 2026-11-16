@props([
    'title' => '',
    // the h1's id; the heading then takes focus from a script (tabindex -1)
    'titleId' => null,
])
@php
    // A list screen's title row: the name · the (?) help (named slot `help`) · the main button (the slot).
    // From 768px the (?) follows the name's last word and the main button stands at the end of the row
    // (the signed order: name · (?) · main button). Below 768 the name takes the room left and may wrap, and
    // the (?) stands right beside the main button, the two centred on the name; with no main button the (?)
    // stands at the end of the row. The name's box turns into a pass-through (display: contents) below 768,
    // so the (?) is printed once. The row is the positioning box of a help popup below 768.
@endphp
<div {{ $attributes->merge(['class' => 'relative flex items-center gap-3 max-md:gap-1.5']) }}>
    <div class="min-w-0 flex-1 max-md:contents">
        <h1 @if ($titleId) id="{{ $titleId }}" tabindex="-1" @endif class="m-0 inline text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere] max-md:min-w-0 max-md:flex-1">{{ $title }}</h1>
        @if (isset($help) && $help->isNotEmpty())
            <span data-title-help class="ms-1 inline-block align-middle max-md:ms-0 max-md:shrink-0">{{ $help }}</span>
        @endif
    </div>
    {{ $slot }}
</div>

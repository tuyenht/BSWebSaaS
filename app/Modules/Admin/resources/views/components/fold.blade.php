@props([
    'title' => '',
    // a short grey note after the title («hiện ở thẻ tin trong danh sách»)
    'hint' => null,
    // a warning chip after the title («chưa có»)
    'warn' => null,
    // a note pushed to the right edge of the header («3 lần chuyển»)
    'end' => null,
    'open' => true,
])
@php
    // A block of a reading panel that folds (design board 17 «.sp-fld»): a grey header row with a chevron, the
    // title in small capitals, an optional hint, warning chip and end note; the body under a rule. The header
    // is a real button (aria-expanded) at the shared row height; the body is shown or hidden by Alpine, so a
    // redraw of the panel keeps what the reader folded away.
@endphp
<div {{ $attributes->merge(['class' => 'rounded-md border border-border bg-surface']) }} x-data="{ open: @js((bool) $open) }">
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()"
            class="hit-area flex min-h-control-row w-full flex-wrap items-baseline gap-x-2 gap-y-0.5 rounded-[inherit] bg-surface-muted px-3 py-2 text-start text-caption font-bold uppercase tracking-[.06em] text-text-muted transition-colors hover:bg-surface-hover"
            x-bind:class="open && 'rounded-b-none'">
        <x-bs-admin::icon name="ri-arrow-down-s" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0 self-center transition-transform motion-reduce:transition-none" x-bind:class="open ? '' : '-rotate-90'" />
        <span class="[overflow-wrap:anywhere]">{{ $title }}</span>
        @if ($hint)
            <span class="font-normal normal-case tracking-normal">{{ $hint }}</span>
        @endif
        @if ($warn)
            <span class="inline-flex items-center gap-1 font-semibold normal-case tracking-normal text-warning-text">
                <x-bs-admin::icon name="ri-error-warning" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0 self-center" />{{ $warn }}
            </span>
        @endif
        @if ($end)
            <span class="ms-auto font-normal normal-case tracking-normal">{{ $end }}</span>
        @endif
    </button>
    <div x-show="open" class="flex flex-col gap-2 border-t border-border p-3">
        {{ $slot }}
    </div>
</div>

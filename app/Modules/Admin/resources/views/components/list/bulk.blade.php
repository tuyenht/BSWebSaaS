@props([
    'label',
    'status',
    'clear' => 'clearSelection',
    'clearLabel',
    'skip' => null,
])
@php
    // The bar of a shared list's selection (canvas .ql-bulk): "Đã chọn N …" read out politely, the
    // actions as buttons in the default slot, "Bỏ chọn" at the end, and one line under them naming
    // what the first action skips. It sits between the filter line and the rows in the markup; below 900 (by the
    // LIST's width: the list is the `lt` container, a flex column) CSS order moves it after the rows
    // (a sticky bottom bar must come after what it floats over) and it sticks to the bottom of the screen, its
    // buttons sharing the full width at the touch size; from 900 it is a plain bar over the rows.
    // While it sticks, its height is kept in --bulk-bar-h on the page root: the page keeps that much clear
    // at the bottom when focus or a link scrolls (WCAG 2.4.11) and the toast rises above it.
@endphp
<div role="region" aria-label="{{ $label }}"
    x-data="{ ro: null, sync() { const s = document.documentElement.style; getComputedStyle(this.$root).position === 'sticky' ? s.setProperty('--bulk-bar-h', this.$root.offsetHeight + 'px') : s.removeProperty('--bulk-bar-h') }, init() { this.ro = new ResizeObserver(() => this.sync()); this.ro.observe(this.$root) }, destroy() { this.ro?.disconnect(); document.documentElement.style.removeProperty('--bulk-bar-h') } }"
    {{ $attributes->merge(['class' => 'mb-3 flex flex-wrap items-center gap-2 rounded-md border border-primary/25 bg-primary/5 px-3 py-2 min-[600px]:mx-4 @max-[56.25rem]/lt:sticky @max-[56.25rem]/lt:bottom-0 @max-[56.25rem]/lt:z-50 @max-[56.25rem]/lt:m-0 @max-[56.25rem]/lt:rounded-none @max-[56.25rem]/lt:border-x-0 @max-[56.25rem]/lt:border-b-0 @max-[56.25rem]/lt:bg-surface @max-[56.25rem]/lt:px-3 @max-[56.25rem]/lt:pt-2.5 @max-[56.25rem]/lt:pb-[max(0.625rem,env(safe-area-inset-bottom))] @max-[56.25rem]/lt:shadow-[0_-4px_12px_rgb(15_18_30/0.12)] @max-[56.25rem]/lt:order-4']) }}>
    <span aria-live="polite" class="font-semibold text-primary-text [overflow-wrap:anywhere]">{{ $status }}</span>
    <div class="flex flex-wrap gap-2 @max-[56.25rem]/lt:order-3 @max-[56.25rem]/lt:basis-full @max-[56.25rem]/lt:*:min-h-hit @max-[56.25rem]/lt:*:flex-auto">{{ $slot }}</div>
    <x-bs-admin::button size="sm" variant="ghost" color="primary" class="ms-auto @max-[56.25rem]/lt:min-h-hit" wire:click="{{ $clear }}">
        <x-bs-admin::icon name="ri-close" class="size-4" /> {{ $clearLabel }}
    </x-bs-admin::button>
    @if ($skip)
        <p class="m-0 flex basis-full items-start gap-1.5 text-caption text-text-muted [overflow-wrap:anywhere] @max-[56.25rem]/lt:order-4">
            <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0 text-info-text" />
            <span>{{ $skip }}</span>
        </p>
    @endif
</div>

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
    // what the first action skips. It sits between the filter line and the rows. Below 900 (by the
    // LIST's width: the list is the `lt` container) it sticks to the bottom of the screen, its
    // buttons sharing the full width at the touch size; from 900 it is a plain bar over the rows.
@endphp
<div role="region" aria-label="{{ $label }}"
    {{ $attributes->merge(['class' => 'mb-3 flex flex-wrap items-center gap-2 rounded-md border border-primary/25 bg-primary/5 px-3 py-2 min-[600px]:mx-4 @max-[56.25rem]/lt:sticky @max-[56.25rem]/lt:bottom-0 @max-[56.25rem]/lt:z-50 @max-[56.25rem]/lt:m-0 @max-[56.25rem]/lt:rounded-none @max-[56.25rem]/lt:border-x-0 @max-[56.25rem]/lt:border-b-0 @max-[56.25rem]/lt:bg-surface @max-[56.25rem]/lt:px-3 @max-[56.25rem]/lt:pt-2.5 @max-[56.25rem]/lt:pb-[max(0.625rem,env(safe-area-inset-bottom))] @max-[56.25rem]/lt:shadow-[0_-4px_12px_rgb(15_18_30/0.12)]']) }}>
    <span aria-live="polite" class="font-semibold text-primary-text [overflow-wrap:anywhere]">{{ $status }}</span>
    <div class="flex flex-wrap gap-2 @max-[56.25rem]/lt:order-3 @max-[56.25rem]/lt:basis-full @max-[56.25rem]/lt:*:flex-auto">{{ $slot }}</div>
    <x-bs-admin::button size="sm" variant="ghost" color="primary" class="ms-auto" wire:click="{{ $clear }}">
        <x-bs-admin::icon name="ri-close" class="size-4" /> {{ $clearLabel }}
    </x-bs-admin::button>
    @if ($skip)
        <p class="m-0 flex basis-full items-start gap-1.5 text-caption text-text-muted [overflow-wrap:anywhere] @max-[56.25rem]/lt:order-4">
            <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0 text-info-text" />
            <span>{{ $skip }}</span>
        </p>
    @endif
</div>

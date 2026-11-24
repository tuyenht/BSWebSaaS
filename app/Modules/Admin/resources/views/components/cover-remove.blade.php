@props(['title'])
{{-- The «Bỏ ảnh» pill in the corner of a filled cover card (boards 07 and 10): a 44px hit area around a small dark pill.
     The action comes in as attributes (wire:click / x-on:click). --}}
<button type="button" {{ $attributes->merge(['class' => 'absolute end-1.5 top-1.5 inline-flex min-h-hit items-center']) }} title="{{ $title }}" aria-label="{{ $title }}">
    <span class="inline-flex min-h-8 items-center gap-1 rounded-full border border-white px-3 text-[calc(0.875rem+var(--user-text-boost))] font-semibold leading-tight text-white" style="background: rgba(15, 23, 41, .72)">
        <x-bs-admin::icon name="ri-close" class="size-4 shrink-0" />{{ $slot }}
    </span>
</button>

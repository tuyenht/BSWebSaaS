@props([
    'title' => null,
])
@php
    // Mirrors Velzon .card (shadow-card + 1px border + header border-bottom, title 16px).
    // Deviation: radius rounded-md (6px) to match the dashboard screenshot; base token is 4px.
@endphp
<div {{ $attributes->merge(['class' => 'rounded-md border border-border bg-surface shadow-card']) }}>
    @isset($header)
        <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
            {{ $header }}
        </div>
    @elseif ($title)
        <div class="border-b border-border px-4 py-3">
            <h5 class="text-[1rem] font-semibold leading-tight text-body-text">{{ $title }}</h5>
        </div>
    @endisset

    <div class="p-4">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-border px-4 py-3">
            {{ $footer }}
        </div>
    @endisset
</div>

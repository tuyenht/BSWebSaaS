@props([
    'label' => null,
    'type' => 'text',
    'name' => null,
    'id' => null,
])
@php
    // Mirrors Velzon .form-control: 1px border in the input-border role (3:1 against the field and
    // the page — Velzon's gray-300 edge reads 1.2:1). Focus → accent border (the brand navy,
    // lightened by the dark scheme) plus the shared focus ring (app.css :focus-visible).
    $inputId = $id ?? $name;
@endphp
<div>
    @if ($label)
        <label @if ($inputId) for="{{ $inputId }}" @endif class="mb-1.5 block text-sm font-medium text-body-text">{{ $label }}</label>
    @endif
    <input
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($inputId) id="{{ $inputId }}" @endif
        {{ $attributes->merge(['class' => 'block w-full rounded border border-border-input bg-surface px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-accent disabled:bg-surface-hover']) }}
    >
</div>

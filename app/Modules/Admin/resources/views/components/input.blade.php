@props([
    'label' => null,
    'type' => 'text',
    'name' => null,
    'id' => null,
])
@php
    // Mirrors Velzon .form-control: 1px gray-300 border, focus → primary border + subtle ring.
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
        {{ $attributes->merge(['class' => 'block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20 disabled:bg-surface-hover']) }}
    >
</div>

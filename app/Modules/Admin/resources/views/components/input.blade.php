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
        <label @if ($inputId) for="{{ $inputId }}" @endif class="mb-1.5 block text-sm font-medium text-gray-700">{{ $label }}</label>
    @endif
    <input
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($inputId) id="{{ $inputId }}" @endif
        {{ $attributes->merge(['class' => 'block w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20 disabled:bg-gray-100']) }}
    >
</div>

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
    // Height is the main control token (40 → 44 with the text size, 44 on touch); a field the
    // server rejected (an error for its name in the shared bag; none outside a web request) carries aria-invalid and takes the danger edge.
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
        @if ($name && isset($errors) && $errors->has($name)) aria-invalid="true" @endif
        {{ $attributes->merge(['class' => 'block min-h-control w-full rounded border border-border-input bg-surface px-3 py-1.5 text-body text-text placeholder:text-placeholder transition-colors pointer-coarse:min-h-hit focus:border-primary-text aria-invalid:border-danger-text disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-60']) }}
    >
</div>

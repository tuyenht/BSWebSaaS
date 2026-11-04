@props([
    'label' => null,
    'type' => 'text',
    'name' => null,
    'id' => null,
    // The error bag key of this field: by default the Livewire property it is bound to, else its name.
    'error' => null,
])
@php
    // Mirrors Velzon .form-control: 1px border in the input-border role (3:1 against the field and
    // the page — Velzon's gray-300 edge reads 1.2:1). Focus → accent border (the brand navy,
    // lightened by the dark scheme) plus the shared focus ring (app.css :focus-visible).
    // Height and text size come from the shared `field` utility (app.css: 40 → 44 with the text
    // size, 44 on touch, the user's body text). A field the server rejected (an error for it in the
    // shared bag; none outside a web request) carries aria-invalid, takes the danger edge and shows
    // the message under it, named by aria-describedby so a screen reader reads it with the field.
    $inputId = $id ?? $name;
    $errorKey = $error ?? ($attributes->wire('model')->value() ?: $name);
    $message = $errorKey && isset($errors) ? (string) $errors->first($errorKey) : '';
    $errorId = $message !== '' ? ($inputId ?? 'field-'.\Illuminate\Support\Str::slug((string) $errorKey)).'-error' : null;
    $describedBy = trim(implode(' ', array_filter([$attributes->get('aria-describedby'), $errorId])));
@endphp
<div>
    @if ($label)
        <label @if ($inputId) for="{{ $inputId }}" @endif class="mb-1.5 block text-sm font-medium text-body-text">{{ $label }}</label>
    @endif
    <input
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" @endif
        @if ($inputId) id="{{ $inputId }}" @endif
        @if ($message !== '') aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->except('aria-describedby')->merge(['class' => 'field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-60']) }}
    >
    @if ($errorId)
        <p id="{{ $errorId }}" class="mt-1 text-sm text-danger-text">{{ $message }}</p>
    @endif
</div>

@props([
    'checked' => false,
    'label',
    'visible' => false,
])
@php
    // The select box of a list row and of select-all: a 44 × 44 button around an 18px box, so
    // the touch area is whole and the box stays small. `checked` is true, false or "mixed" (some
    // of the page picked). `visible` writes the name beside the box (the select-all bar) instead
    // of as an aria-label.
    $state = $checked === 'mixed' ? 'mixed' : ($checked ? 'true' : 'false');
@endphp
<button type="button" role="checkbox" aria-checked="{{ $state }}"@unless ($visible) aria-label="{{ $label }}"@endunless {{ $attributes->merge(['class' => $visible ? 'list-ck list-ck-text' : 'list-ck']) }}>
    <span class="list-box" aria-hidden="true">
        @if ($state === 'true')
            <x-bs-admin::icon name="ri-check" class="list-box-i" />
        @elseif ($state === 'mixed')
            <x-bs-admin::icon name="ri-subtract" class="list-box-i" />
        @endif
    </span>
    @if ($visible)
        <span class="list-ck-label">{{ $label }}</span>
    @endif
</button>

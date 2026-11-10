@props([
    'label',
    'placeholder' => null,
    'clearLabel' => null,
    'clearAction' => null,
    'clearOn' => false,
])
@php
    // The list search field (canvas .sx-search, K69 tools row): the whole bordered box is a <label>,
    // so a tap on the magnifier or the padding focuses the field — the icon never takes the tap
    // itself. The visible hint may be a short example; the field's name is the full $label, so a
    // screen reader never hears only "Tên hoặc mã". A screen that passes clear-label + clear-action gets a
    // "Xóa từ khóa tìm" button at the end of the box while clear-on says the field holds a term (the
    // other screens draw none). Height follows the main control token, 44 on
    // touch; the focus ring sits on the box. Extra attributes (wire:model.live.debounce, id, autofocus) go on the <input>.
@endphp
<label class="flex min-h-control min-w-0 items-center gap-2 rounded border border-border-input bg-surface ps-3 pe-1 text-body focus-ring-within transition-colors any-pointer-coarse:min-h-hit focus-within:border-primary-text">
    <x-bs-admin::icon name="ri-search" class="size-4 shrink-0 text-text-muted" />
    <input
        type="search"
        aria-label="{{ $label }}"
        placeholder="{{ $placeholder ?? $label }}"
        autocomplete="off"
        {{ $attributes->merge(['class' => 'min-h-control min-w-0 flex-1 self-stretch border-0 bg-transparent p-0 text-body text-text placeholder:text-placeholder any-pointer-coarse:min-h-hit']) }}
    >
    @if ($clearLabel !== null && $clearAction !== null && $clearOn)
        <button type="button" wire:click="{{ $clearAction }}" aria-label="{{ $clearLabel }}" title="{{ $clearLabel }}"
            class="hit-area inline-flex size-8 shrink-0 items-center justify-center rounded-full text-text-muted transition-colors hover:text-text-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary-text">
            <x-bs-admin::icon name="ri-close-circle-fill" class="size-4" />
        </button>
    @endif
</label>

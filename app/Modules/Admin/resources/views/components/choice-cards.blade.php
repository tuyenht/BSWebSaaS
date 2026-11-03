@props([
    'options' => [],
    'pick',
    'labelledby',
])
@php
    // One choice out of a few, as cards in a radio group (design 03 .kh-plans / .kh-po): each card
    // shows a name, an optional line under it and an optional tag ("Đang dùng"). A card that cannot
    // be chosen is dashed and aria-disabled, never hidden. Cards flow in as many columns as fit
    // (at least 9.375rem each, so they follow the reader's text size). ← → ↑ ↓ move between the
    // cards that can be chosen; a click or Space/Enter chooses.
    //
    // $options: list<array{value: string, label: string, sub?: string|null, tag?: string|null, checked?: bool, disabled?: bool}>
    // $pick: the host's public method that takes the chosen value (called with Js::from()).
    // $labelledby: the id of the group's visible label.
@endphp
<div role="radiogroup" aria-labelledby="{{ $labelledby }}"
     x-data="{ step(d) { const all = [...$el.querySelectorAll('[role=radio]:not([aria-disabled=true])')], i = all.indexOf(document.activeElement); all[(i + d + all.length) % all.length]?.focus(); } }"
     x-on:keydown.right.prevent="step(1)" x-on:keydown.down.prevent="step(1)" x-on:keydown.left.prevent="step(-1)" x-on:keydown.up.prevent="step(-1)"
     {{ $attributes->merge(['class' => 'grid grid-cols-[repeat(auto-fill,minmax(9.375rem,1fr))] gap-2']) }}>
    @foreach ($options as $o)
        @php $off = (bool) ($o['disabled'] ?? false); $on = ! $off && (bool) ($o['checked'] ?? false); @endphp
        <button type="button" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" @if ($off) aria-disabled="true" @endif
                @unless ($off) wire:click="{{ $pick }}({{ \Illuminate\Support\Js::from($o['value']) }})" @endunless
                @class([
                    'hit-area flex min-h-16 flex-col items-start gap-0.5 rounded-md border bg-surface px-3 py-2.5 text-start text-body text-text transition-colors',
                    'cursor-pointer border-border-input hover:bg-surface-hover' => ! $off && ! $on,
                    'cursor-pointer border-primary-text bg-[color-mix(in_srgb,var(--color-primary-text)_6%,var(--color-surface))] shadow-[inset_0_0_0_1px_var(--color-primary-text)]' => $on,
                    'cursor-not-allowed border-dashed border-border-input opacity-70' => $off,
                ])>
            <b class="font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $o['label'] }}</b>
            @if (($o['sub'] ?? null) !== null)
                <span class="text-small text-text-muted">{{ $o['sub'] }}</span>
            @endif
            @if (($o['tag'] ?? null) !== null)
                <span class="text-small text-text-muted">{{ $o['tag'] }}</span>
            @endif
        </button>
    @endforeach
</div>

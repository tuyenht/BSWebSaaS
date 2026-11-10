@props([
    'href' => null,
    'quickEdit' => null,
    'child' => false,
    'act' => null,
])
@php
    // A record's name: a link to its editor (plain text when it has none, such as a Trash row),
    // wrapping onto as many lines as it needs — never clipped. `quick-edit` adds the visible ✎
    // button with that name; it dispatches `list-quick-edit` for the row to open its title field.
    // `act` (a public method of the host) makes the name a button that calls it, for a record with no
    // editor to open (a Trash row says what to do instead).
    // `child` puts the tree mark of a child record (a sub-page) before the name, in its indent.
@endphp
<span {{ $attributes->merge(['class' => 'list-tl']) }}>
    @if ($child)
        <x-bs-admin::icon name="chevron-right" class="list-tree" />
    @endif
    @if ($href)
        <a href="{{ $href }}" wire:navigate class="list-name">{!! trim($slot) !!}</a>
    @elseif ($act)
        <button type="button" wire:click="{{ $act }}" class="list-name cursor-pointer border-0 bg-transparent p-0 text-left font-[inherit]">{!! trim($slot) !!}</button>
    @else
        <span class="list-name">{!! trim($slot) !!}</span>
    @endif
    @if ($quickEdit)
        <button type="button" class="list-qe" aria-label="{{ $quickEdit }}" title="{{ $quickEdit }}" x-on:click="$dispatch('list-quick-edit')">
            <x-bs-admin::icon name="ri-pencil" class="list-qe-i" />
        </button>
    @endif
</span>

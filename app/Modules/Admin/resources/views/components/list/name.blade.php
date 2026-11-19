@props([
    'href' => null,
    'quickEdit' => null,
    'act' => null,
    'external' => false,
    'grip' => null,
])
@php
    // A record's name: a link to its editor (plain text when it has none, such as a Trash row),
    // wrapping onto as many lines as it needs — never clipped. `quick-edit` adds the visible ✎
    // button with that name; it dispatches `list-quick-edit` for the row to open its title field.
    // `act` (a public method of the host) makes the name a button that calls it, for a record with no
    // editor to open (a Trash row says what to do instead).
    // `external` opens the href in a new tab instead of inside the admin (a record's public page).
    // `grip` (['title' => tip, 'off' => bool]) puts a tree's drag grip first: the row is dragged by it,
    // and an `off` grip is greyed with the reason as its tip (the host's script reads data-grip).
@endphp
<span {{ $attributes->merge(['class' => 'list-tl']) }}>
    @if ($grip)
        <span class="list-grip" data-grip aria-hidden="true" title="{{ $grip['title'] }}" draggable="{{ $grip['off'] ? 'false' : 'true' }}"@if ($grip['off']) data-off="1"@endif><x-bs-admin::icon name="ri-draggable" class="list-grip-i" /></span>
    @endif
    @if ($href)
        <a href="{{ $href }}" {!! $external ? 'target="_blank" rel="noopener"' : 'wire:navigate' !!} class="list-name">{!! trim($slot) !!}</a>
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

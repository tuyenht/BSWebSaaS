@props(['note'])
@php
    // An editor's save state beside its title (design: the pill next to "Quay lại"). The server draws
    // the state at rest from `note` (Editor::saveNote(): key, icon, short text, full text, tooltip):
    // never saved, saved at a time, saved by itself, a field to fix. Inside an editor's
    // bsEditorSave scope (resources/js/admin-editor.js) the browser's state covers it: changes
    // waiting, saving, no connection. Two live regions: the server's state and the browser's; the
    // count of changes is not read out at every keystroke, saving and no connection are.
    $glyph = 'size-[1.15em] shrink-0';
@endphp
<span {{ $attributes->only('class')->merge(['class' => 'flex min-w-0']) }}>
    <span role="status" class="ped-sv ped-sv-rest @max-[48rem]:rounded-xl" data-s="{{ $note['key'] }}" title="{{ $note['tip'] }}">
        <x-bs-admin::icon :name="$note['icon']" :class="$glyph" /><span aria-hidden="true">{{ $note['text'] }}</span><span class="sr-only">{{ $note['full'] }}</span>
    </span>
    <span wire:ignore class="contents">
        <span role="status" class="ped-sv @max-[48rem]:rounded-xl" x-show="state()" x-cloak x-bind:data-s="state()" x-bind:aria-live="state() === 'dirty' ? 'off' : 'polite'" x-bind:title="text()">
            <x-bs-admin::icon name="ri-error-warning" :class="$glyph" x-show="state() === 'dirty'" />
            <x-bs-admin::icon name="ri-loader-4" :class="$glyph.' motion-safe:animate-spin'" x-show="state() === 'saving'" />
            <x-bs-admin::icon name="ri-wifi-off" :class="$glyph" x-show="state() === 'offline'" />
            <span x-text="text()"></span>
        </span>
    </span>
</span>

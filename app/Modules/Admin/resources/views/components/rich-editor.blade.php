@props([
    'target',            // Livewire property the HTML syncs into
    'profile' => 'full', // full | basic — see resources/js/admin-rich-editor.js
    'value' => '',
    'rows' => 8,
])
{{-- ONE-LINE rich editor: <x-bs-admin::rich-editor target="description" profile="full" :value="$description" />
     Livewire 4 bridge, DOM-only by design ($wire.$set defaults to live = a request per
     keystroke, and the island must not depend on Livewire JS internals):
     · content OUT:  the island mirrors Jodit's HTML into the hidden [data-rich-sync] input
       (OUTSIDE wire:ignore) and fires `input` — plain deferred wire:model carries it on save.
     · actions IN:   toolbar image/video buttons .click() the hidden [data-rich-action] buttons,
       which are real wire:click handlers (requires the WithMediaLibraryPicker trait).
     wire:ignore only shields Jodit's own DOM from morphs. --}}
<div wire:ignore>
    <textarea id="rich-editor-{{ $target }}"
              data-rich-editor="{{ $profile === 'basic' ? 'basic' : 'full' }}"
              data-rich-target="{{ $target }}"
              rows="{{ $rows }}"
              {{ $attributes }}>{{ $value }}</textarea>
</div>
<input type="hidden" data-rich-sync="{{ $target }}" wire:model="{{ $target }}">
@if ($profile !== 'basic')
    <span class="hidden" aria-hidden="true">
        <button type="button" data-rich-action="image" tabindex="-1" wire:click="openImagePicker('editor')"></button>
        <button type="button" data-rich-action="video" tabindex="-1" wire:click="openVideoDialog"></button>
    </span>
@endif

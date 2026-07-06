@props([
    'target',            // Livewire property the HTML syncs into (also the wire:model fallback)
    'profile' => 'full', // full | basic — see resources/js/admin-rich-editor.js
    'value' => '',
    'rows' => 8,
])
{{-- ONE-LINE rich editor: <x-admin::rich-editor target="description" profile="full" :value="$description" />
     The admin-rich-editor island mounts Jodit over this textarea on livewire:navigated.
     wire:ignore keeps Livewire morphs off Jodit's DOM; wire:model is the no-JS fallback only
     (Jodit never fires input on the hidden textarea — the island writes via $wire.$set and
     mirrors the value here). The FULL profile's image button requires the owning component
     to use the WithMediaLibraryPicker trait. --}}
<div wire:ignore>
    <textarea id="rich-editor-{{ $target }}"
              data-rich-editor="{{ $profile === 'basic' ? 'basic' : 'full' }}"
              data-rich-target="{{ $target }}"
              rows="{{ $rows }}"
              wire:model="{{ $target }}"
              {{ $attributes }}>{{ $value }}</textarea>
</div>

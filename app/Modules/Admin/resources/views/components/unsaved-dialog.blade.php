@props([
    'count' => 0,
    'label' => '',
    'save' => 'save',
    'discard' => 'resetChanges',
    'sections' => [],
    // An editor's own sentence and save button for leaving (it names no item), in place of the
    // shared "“label” có thay đổi chưa lưu" and "Lưu và rời đi". Both may change with the page (a
    // new item once saved, a draft once published): they ride on the redrawn count and are read
    // when the dialog opens.
    'body' => null,
    'saveLabel' => null,
])
@php
    // The one blocking dialog for unsaved changes (design 01 "Bạn có thay đổi chưa lưu"; behaviour
    // in resources/js/admin-unsaved.js, Alpine.data 'bsUnsaved', started by the shared lazy loader).
    // A page that keeps its draft on the server renders it with its change count (`count`, redrawn
    // by Livewire on every update), the name of what is being edited (`label`), its own save and
    // discard methods, and the paths of its sibling sections (`sections`: going there reads "sang
    // mục khác", anywhere else "rời đi"). Closing the tab or reloading asks the browser's own
    // question instead. The dialog itself sits under wire:ignore: its buttons are laid out in the
    // browser (one row when they fit, stacked otherwise) and a redraw must not undo that.
    $seed = 'ud-'.substr(md5($label.'|'.$save), 0, 8);
    $config = ['save' => $save, 'discard' => $discard, 'sections' => array_values($sections)];
    $glyph = 'size-[1.15em] shrink-0';
@endphp
<div x-ignore wire:ignore.self data-bs-lazy="bsUnsaved" x-data="bsUnsaved(@js($config))" data-unsaved-guard>
    <span hidden x-ref="count" data-unsaved-count="{{ (int) $count }}" @if ($body !== null) data-unsaved-body="{{ $body }}" @endif @if ($saveLabel !== null) data-unsaved-save="{{ $saveLabel }}" @endif></span>
    <div wire:ignore>
        <div x-show="open" x-cloak class="sx-udw" x-on:click.self="stay()">
            <div class="sx-ud" role="dialog" aria-modal="true" aria-labelledby="{{ $seed }}-t" aria-describedby="{{ $seed }}-d" x-ref="box" x-on:keydown.window="key($event)">
                <h2 id="{{ $seed }}-t">{{ __('admin.unsaved.title') }}</h2>
                <p id="{{ $seed }}-d">
                    @if ($body !== null)
                        <span x-text="body">{{ $body }}</span>
                    @else
                        <span x-show="section">{{ __('admin.unsaved.body_section', ['label' => $label]) }}</span>
                        <span x-show="!section">{{ __('admin.unsaved.body_leave', ['label' => $label]) }}</span>
                    @endif
                </p>
                <div class="sx-ud-foot" x-ref="foot">
                    <button type="button" class="sx-ud-b sx-ud-p hit-area" data-ud="p" aria-busy="false" x-bind:aria-busy="busy ? 'true' : 'false'" x-on:click="saveGo()">
                        <span data-ud-t x-show="section">{{ __('admin.unsaved.save_section') }}</span>
                        <span data-ud-t x-show="!section" @if ($saveLabel !== null) x-text="saveText" @endif>{{ $saveLabel ?? __('admin.unsaved.save_leave') }}</span>
                    </button>
                    <button type="button" class="sx-ud-b sx-ud-s hit-area" data-ud="s" x-on:click="stay()">{{ __('admin.unsaved.stay') }}</button>
                    <button type="button" class="sx-ud-b sx-ud-x hit-area" data-ud="x" x-on:click="discardGo()">
                        <x-bs-admin::icon name="ri-error-warning" :class="$glyph" />
                        <span data-ud-t x-show="section">{{ __('admin.unsaved.discard_section') }}</span>
                        <span data-ud-t x-show="!section">{{ __('admin.unsaved.discard_leave') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

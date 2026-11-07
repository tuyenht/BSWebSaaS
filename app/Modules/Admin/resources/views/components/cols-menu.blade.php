@props([
    'id',
    'locked' => [],
    'options' => [],
    'selected' => [],
    'model' => 'cols',
])
@php
    // The "Cột" menu of a wide record list (canvas .ql-colw > .ql-colb, screens 05 and 08): the button
    // says how many optional columns are hidden ("Cột (ẩn 2)", read as "Chọn cột — đang ẩn 2 cột"); the
    // panel "Chọn cột hiển thị" lists the columns that always show (ticked, locked, "Cột bắt buộc — luôn
    // hiện"), a separator, then the optional ones, each ticked while it shows.
    //
    // $id: the panel's id. $locked: list<string> labels. $options: array<string, string> key => label.
    // $selected: list<string> the keys shown now. $model: the host's public array property the ticks
    // bind to (wire:model.live) — a fixed name, never input.
    //
    // Opening moves focus to the first choice; Esc puts it back on the button; leaving it (Tab, a click
    // away) closes it.
    $hidden = count(array_diff(array_keys($options), $selected));
    $label = $hidden > 0 ? __('admin.list.cols_hidden', ['n' => $hidden]) : __('admin.list.cols');
    $aria = $hidden > 0 ? __('admin.list.cols_aria_hidden', ['n' => $hidden]) : __('admin.list.cols_panel');
    $lockedTitle = __('admin.list.cols_locked_title');
@endphp
<div {{ $attributes->merge(['class' => 'relative shrink-0']) }}
     x-data="{
         open: false,
         show() { this.open = true; this.whenShown(() => this.$refs.colsPanel.querySelector('input:not([disabled])')?.focus()); },
         hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.cols.focus(); },
         whenShown(fn, tries = 20) { this.$refs.colsPanel.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
     }"
     @click.outside="hide(false)" @keydown.escape.prevent.stop="hide()"
     @focusout="if ($event.relatedTarget && ! $root.contains($event.relatedTarget)) hide(false)">
    <button type="button" x-ref="cols" @click="open ? hide() : show()" aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $id }}" aria-label="{{ $aria }}"
        class="hit-area inline-flex min-h-control items-center gap-1.5 rounded-full border border-transparent bg-primary/7 px-3.5 text-body font-medium text-text transition-colors hover:bg-primary/12">
        <x-bs-admin::icon name="ri-layout-column" class="size-4 text-text-muted" /> {{ $label }}
        <x-bs-admin::icon name="chevron-down" class="size-4 text-text-muted transition-transform" ::class="open && 'rotate-180'" />
    </button>
    <div x-show="open" x-cloak x-transition x-ref="colsPanel" id="{{ $id }}" role="group" aria-label="{{ __('admin.list.cols_panel') }}"
        class="absolute end-0 top-full z-60 mt-1.5 w-60 rounded-md border border-border bg-surface p-1.5 shadow-lg">
        <p id="{{ $id }}-req" class="m-0 px-2 pb-1 pt-1.5 text-caption font-semibold text-text-muted">{{ __('admin.list.cols_required') }}</p>
        @foreach ($locked as $name)
            <label title="{{ $lockedTitle }}" class="flex min-h-menu-row cursor-not-allowed items-center gap-2.5 rounded px-2 text-body text-text-muted any-pointer-coarse:min-h-menu-row-touch">
                <input type="checkbox" checked disabled aria-describedby="{{ $id }}-req" class="size-4 shrink-0 rounded border-border-strong">
                <span class="min-w-0 flex-1">{{ $name }}</span>
                <x-bs-admin::icon name="ri-lock" class="size-4 shrink-0 text-text-muted" />
            </label>
        @endforeach
        <div role="separator" class="mx-0 my-1 h-px bg-border"></div>
        <p class="m-0 px-2 pb-1 pt-1 text-caption font-semibold text-text-muted">{{ __('admin.list.cols_optional') }}</p>
        @foreach ($options as $key => $name)
            <label class="flex min-h-menu-row cursor-pointer items-center gap-2.5 rounded px-2 text-body text-text hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                <input type="checkbox" value="{{ $key }}" wire:model.live="{{ $model }}" class="size-4 shrink-0 accent-primary-text">
                {{ $name }}
            </label>
        @endforeach
    </div>
</div>

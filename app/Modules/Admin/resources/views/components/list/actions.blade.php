@props([
    'name',
    'title' => null,
    'nameBind' => null,
])
@php
    // The buttons of one record: the row buttons (<x-bs-admin::button size="sm" variant="soft">,
    // icon + text) in the default slot, then a ⋮ button for the `menu` slot (list.menu-item rows).
    // The ⋮ is named for the record; Esc or a pick closes the menu and puts focus back on it — a pick
    // does so before the item's own handler runs (capture), so a dialog the item opens takes the ⋮
    // as the place to give the focus back to, not the item that is gone with the menu;
    // arrow keys move between the items. With nothing in `menu` there is no ⋮. Opened with too
    // little room below it, the menu opens upwards. On a narrow screen the menu is a sheet from the
    // bottom of the screen, its title over the items and the rest dimmed: with a `title` ("Thao tác
    // cho …", a screen whose board names it) below a 600 screen; without one as board 05 draws it,
    // titled «Thao tác khác cho "…"», below a 900 list (where a row is a card or one line). While it
    // is a sheet (the stylesheet says so: --list-sheet; a turn of the screen that makes it a menu
    // again ends that) Tab stays inside it, the page behind does not scroll, and Esc, a tap on the dimmed part or its «Đóng» item (shown when it has the
    // keyboard focus) closes it, the focus back on the ⋮.
    // `name-bind` is an Alpine expression for the record's name, for rows the browser draws.
    $hasMenu = isset($menu) && $menu->hasActualContent();
    $boundLabel = $nameBind ? \Illuminate\Support\Js::from(__('admin.list.more_for')).'.replace(\':name\', '.$nameBind.')' : null;
    $sheet = $title ? 'title' : 'row';
    // a component tag cannot hold @if, so the bound name reaches the ⋮ through an attribute bag
@endphp
<div class="list-acts">
    {{ $slot }}
    @if ($hasMenu)
        <div class="list-more" x-data="{ open: false, up: false, sheet: false, was: '',
                lock(on) { const de = document.documentElement.style; if (on) { this.was = de.overflow; de.overflow = 'hidden' } else { de.overflow = this.was } this.sheet = on },
                close(back) { this.open = false; this.sheet && this.lock(false); if (back) { this.$refs.more.focus() } },
                destroy() { this.sheet && this.lock(false) } }"
            x-on:click.outside="open = false; sheet && lock(false)" x-on:keydown.escape.prevent.stop="close(true)"
            x-on:resize.window="sheet && getComputedStyle($refs.menu).getPropertyValue('--list-sheet').trim() !== '1' && lock(false)">
            <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-ref="more" data-guide="row-more"
                aria-label="{{ __('admin.list.more_for', ['name' => $name]) }}" title="{{ __('admin.list.more') }}"
                {{ $attributes->only([])->merge($boundLabel ? ['x-bind:aria-label' => $boundLabel] : []) }}
                aria-haspopup="menu" ::aria-expanded="open.toString()"
                x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 300; open = ! open; sheet && lock(false); open && $nextTick(() => { getComputedStyle($refs.menu).getPropertyValue('--list-sheet').trim() === '1' && lock(true); $focus.within($refs.menu).first() })">
                <x-bs-admin::icon name="ri-more-2-fill" class="list-more-i" />
            </x-bs-admin::button>
            <div x-show="open" x-cloak class="list-sheet-bg" data-sheet="{{ $sheet }}" x-on:click="close(true)"></div>
            <x-bs-admin::list.menu class="list-menu" :label="$title ?? __('admin.list.more_for', ['name' => $name])" :bind-label="$boundLabel" :sheet="$sheet">
                @if ($title)
                    <div class="list-mh" @if ($nameBind) x-text="{{ $nameBind }}" @endif>{{ $title }}</div>
                @else
                    {{-- the menu's own name says the same, so a screen reader hears it once --}}
                    <div class="list-mh" aria-hidden="true" @if ($boundLabel) x-text="{{ $boundLabel }}" @endif>{{ __('admin.list.more_for', ['name' => $name]) }}</div>
                @endif
                {{ $menu }}
                <button type="button" role="menuitem" class="list-mx">{{ __('admin.close') }}</button>
            </x-bs-admin::list.menu>
        </div>
    @endif
</div>

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
    // little room below it, the menu opens upwards. `title` ("Thao tác cho …") turns the menu into
    // a sheet from the bottom below 600px, with that title over the items and the rest dimmed.
    // `name-bind` is an Alpine expression for the record's name, for rows the browser draws.
    $hasMenu = isset($menu) && $menu->hasActualContent();
    $boundLabel = $nameBind ? \Illuminate\Support\Js::from(__('admin.list.more_for')).'.replace(\':name\', '.$nameBind.')' : null;
    // a component tag cannot hold @if, so the bound name reaches the ⋮ through an attribute bag
@endphp
<div class="list-acts">
    {{ $slot }}
    @if ($hasMenu)
        <div class="list-more" x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.more.focus() } } }"
            x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
            <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-ref="more"
                aria-label="{{ __('admin.list.more_for', ['name' => $name]) }}" title="{{ __('admin.list.more') }}"
                {{ $attributes->only([])->merge($boundLabel ? ['x-bind:aria-label' => $boundLabel] : []) }}
                aria-haspopup="menu" ::aria-expanded="open.toString()"
                x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 300; open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">
                <x-bs-admin::icon name="ri-more-2-fill" class="list-more-i" />
            </x-bs-admin::button>
            @if ($title)
                <div x-show="open" x-cloak class="list-sheet-bg" x-on:click="close(true)"></div>
            @endif
            <x-bs-admin::list.menu class="list-menu" :label="$title ?? __('admin.list.more_for', ['name' => $name])" :bind-label="$boundLabel" :sheet="(bool) $title">
                @if ($title)
                    <div class="list-mh" @if ($nameBind) x-text="{{ $nameBind }}" @endif>{{ $title }}</div>
                @endif
                {{ $menu }}
            </x-bs-admin::list.menu>
        </div>
    @endif
</div>

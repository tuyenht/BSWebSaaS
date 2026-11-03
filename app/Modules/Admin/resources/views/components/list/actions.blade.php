@props([
    'name',
    'title' => null,
])
@php
    // The buttons of one record: the row buttons (<x-bs-admin::button size="sm" variant="soft">,
    // icon + text) in the default slot, then a ⋮ button for the `menu` slot (list.menu-item rows).
    // The ⋮ is named for the record; Esc or a pick closes the menu and puts focus back on it;
    // arrow keys move between the items. With nothing in `menu` there is no ⋮. Opened with too
    // little room below it, the menu opens upwards. `title` ("Thao tác cho …") turns the menu into
    // a sheet from the bottom below 600px, with that title over the items and the rest dimmed.
    $hasMenu = isset($menu) && $menu->hasActualContent();
@endphp
<div class="list-acts">
    {{ $slot }}
    @if ($hasMenu)
        <div class="list-more" x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.more.focus() } } }"
            x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
            <x-bs-admin::button size="sm" variant="ghost" color="primary" icon x-ref="more"
                aria-label="{{ __('admin.list.more_for', ['name' => $name]) }}" title="{{ __('admin.list.more') }}"
                aria-haspopup="menu" ::aria-expanded="open.toString()"
                x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 300; open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">
                <x-bs-admin::icon name="ri-more-2-fill" class="list-more-i" />
            </x-bs-admin::button>
            @if ($title)
                <div x-show="open" x-cloak class="list-sheet-bg" x-on:click="close(true)"></div>
            @endif
            <div x-ref="menu" x-show="open" x-cloak role="menu" aria-label="{{ $title ?? __('admin.list.more_for', ['name' => $name]) }}" class="list-menu"
                @if ($title) data-sheet @endif x-bind:data-up="up ? 'true' : null"
                x-on:keydown.down.prevent="$focus.within($refs.menu).wrap().next()" x-on:keydown.up.prevent="$focus.within($refs.menu).wrap().prev()"
                x-on:keydown.home.prevent="$focus.within($refs.menu).first()" x-on:keydown.end.prevent="$focus.within($refs.menu).last()"
                x-on:keydown.tab="open = false" x-on:click="$event.target.closest('[role=menuitem]') && close(true)">
                @if ($title)
                    <div class="list-mh">{{ $title }}</div>
                @endif
                {{ $menu }}
            </div>
        </div>
    @endif
</div>

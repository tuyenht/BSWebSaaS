@props([
    'name',
])
@php
    // The buttons of one record: the row buttons (<x-bs-admin::button size="sm" variant="soft">,
    // icon + text) in the default slot, then a ⋮ button for the `menu` slot (list.menu-item rows).
    // The ⋮ is named for the record; Esc or a pick closes the menu and puts focus back on it;
    // arrow keys move between the items. With nothing in `menu` there is no ⋮.
    $hasMenu = isset($menu) && $menu->hasActualContent();
@endphp
<div class="list-acts">
    {{ $slot }}
    @if ($hasMenu)
        <div class="list-more" x-data="{ open: false, close(back) { this.open = false; if (back) { this.$refs.more.focus() } } }"
            x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
            <x-bs-admin::button size="sm" variant="soft" color="secondary" icon x-ref="more"
                aria-label="{{ __('admin.list.more_for', ['name' => $name]) }}" title="{{ __('admin.list.more') }}"
                aria-haspopup="menu" ::aria-expanded="open.toString()"
                x-on:click="open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">
                <x-bs-admin::icon name="ri-more-2-fill" class="list-more-i" />
            </x-bs-admin::button>
            <div x-ref="menu" x-show="open" x-cloak role="menu" aria-label="{{ __('admin.list.more_for', ['name' => $name]) }}" class="list-menu"
                x-on:keydown.down.prevent="$focus.within($refs.menu).wrap().next()" x-on:keydown.up.prevent="$focus.within($refs.menu).wrap().prev()"
                x-on:keydown.home.prevent="$focus.within($refs.menu).first()" x-on:keydown.end.prevent="$focus.within($refs.menu).last()"
                x-on:keydown.tab="open = false" x-on:click="$event.target.closest('[role=menuitem]') && close(true)">
                {{ $menu }}
            </div>
        </div>
    @endif
</div>

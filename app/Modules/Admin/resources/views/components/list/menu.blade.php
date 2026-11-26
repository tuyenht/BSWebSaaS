@props([
    'label' => null,
    'bindLabel' => null,
    'sheet' => false,
    'onOpen' => false,
])
@php
    // The panel of a menu opened by a button (the ⋮ of list.actions, a row's status pill): hidden until
    // the host's `open` is true, named for the screen reader, and driven from the keyboard — arrows
    // (wrapping), Home and End move between its role="menuitem" rows, and a pick closes it with the
    // focus handed back through the host's `close(true)`. Tab closes it the same way and hides it at
    // once (window.bsMenu.leave), so the focus never lands on an item that is about to vanish: Tab
    // moves on from the button to what follows it, Shift+Tab stops on the button. A host with nothing
    // to give the focus back to (a panel always open, `close()` doing nothing) keeps both keys as usual.
    // The host's x-data supplies `open`, `up` (true: open upwards) and `close(back)`; this panel
    // supplies nothing but the behaviour, so every menu on a list reacts to the keys alike. The keys move between the items that show
    // (window.bsMenu, admin-list-focus.js): an item the row shows as its own button is hidden here. `bind-label` is an Alpine expression for a name
    // the browser draws. `sheet` is the bottom sheet the menu turns into on a narrow screen (list.actions):
    // 'title' below a 600 screen, 'row' below a 900 list; there Tab and Shift+Tab move round the items
    // instead of closing it (the host's `sheet` is true while it is a sheet).
    // `on-open` builds the panel the first time it opens and keeps it from then on (a list draws one
    // per row, and the browser walks every built element on each Livewire answer). It is kept, not
    // taken down on closing: a pick closes the menu before the item's own handler runs, and an item
    // removed at that moment would never act. Livewire does not look inside a template, so its key
    // is made from the content: a changed menu replaces the template.
    $built = $onOpen ? 'lm-'.md5($slot->toHtml().'|'.$label) : null;
@endphp
@if ($built)<template x-if="(open && ($el._lmBuilt = true)) || $el._lmBuilt" wire:key="{{ $built }}">@endif
<div x-ref="menu" x-show="open" x-cloak role="menu" aria-label="{{ $label }}"
    @if ($bindLabel && $sheet !== 'title') x-bind:aria-label="{{ $bindLabel }}" @endif
    @if ($sheet) data-sheet="{{ $sheet }}" @endif x-bind:data-up="up ? 'true' : null"
    x-on:keydown.down.prevent="window.bsMenu.move($refs.menu, 'next')" x-on:keydown.up.prevent="window.bsMenu.move($refs.menu, 'prev')"
    x-on:keydown.home.prevent="window.bsMenu.move($refs.menu, 'first')" x-on:keydown.end.prevent="window.bsMenu.move($refs.menu, 'last')"
    x-on:keydown.tab="$data.sheet ? ($event.preventDefault(), window.bsMenu.move($refs.menu, $event.shiftKey ? 'prev' : 'next')) : window.bsMenu.leave($event, $refs.menu, () => close(true))" x-on:click.capture="$event.target.closest('[role=menuitem]') && close(true)"
    {{ $attributes }}>
    {{ $slot }}
</div>
@if ($built)</template>@endif

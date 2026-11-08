@props([
    'label' => null,
    'bindLabel' => null,
    'sheet' => false,
])
@php
    // The panel of a menu opened by a button (the ⋮ of list.actions, a row's status pill): hidden until
    // the host's `open` is true, named for the screen reader, and driven from the keyboard — arrows
    // (wrapping), Home and End move between its role="menuitem" rows, Tab closes it, and a pick closes
    // it with the focus handed back through the host's `close(true)`. The host's x-data supplies
    // `open`, `up` (true: open upwards) and `close(back)`; this panel supplies nothing but the behaviour,
    // so every menu on a list reacts to the keys alike. `bind-label` is an Alpine expression for a name
    // the browser draws; `sheet` marks the bottom sheet below 600px.
@endphp
<div x-ref="menu" x-show="open" x-cloak role="menu" aria-label="{{ $label }}"
    @if ($bindLabel && ! $sheet) x-bind:aria-label="{{ $bindLabel }}" @endif
    @if ($sheet) data-sheet @endif x-bind:data-up="up ? 'true' : null"
    x-on:keydown.down.prevent="$focus.within($refs.menu).wrap().next()" x-on:keydown.up.prevent="$focus.within($refs.menu).wrap().prev()"
    x-on:keydown.home.prevent="$focus.within($refs.menu).first()" x-on:keydown.end.prevent="$focus.within($refs.menu).last()"
    x-on:keydown.tab="open = false" x-on:click.capture="$event.target.closest('[role=menuitem]') && close(true)"
    {{ $attributes }}>
    {{ $slot }}
</div>

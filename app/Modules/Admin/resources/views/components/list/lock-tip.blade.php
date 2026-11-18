@props([
    'why',
])
@php
    // A locked row button (aria-disabled, its reason in its accessible name) and the reason in a small
    // bubble under it (design board 05, a locked button on a row: no tooltip, no message that floats
    // up). The bubble floats over the rows below, so it takes no room and moves nothing: on a wide
    // list (the table, from 900) it shows while the pointer is over the button or the keyboard is on
    // it; a tap or click keeps it open until the focus leaves, a tap elsewhere or Esc.
@endphp
<span class="list-lkw"
    x-data="{ tip: false, sticky: false, wide() { return ($el.closest('.list-rows')?.clientWidth ?? 0) >= 900 } }"
    x-on:mouseenter="if (wide() && ! sticky) { tip = true }"
    x-on:mouseleave="if (! sticky) { tip = false }"
    x-on:focusin="if (wide()) { tip = true }"
    x-on:focusout="tip = false; sticky = false"
    x-on:click="tip = true; sticky = true"
    x-on:click.outside="tip = false; sticky = false"
    x-on:keydown.escape="if (tip) { tip = false; sticky = false; $event.stopPropagation() }">
    {{ $slot }}
    <span class="list-lkp" x-show="tip" x-cloak aria-hidden="true"><x-bs-admin::icon name="ri-lock" class="size-4 shrink-0" /><span>{{ $why }}</span></span>
</span>

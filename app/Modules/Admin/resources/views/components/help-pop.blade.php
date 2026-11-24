@props([
    'label',
    'align' => 'start',
])
@php
    // A (?) beside a label that opens a short explanation right under it, in place (design board
    // «Đăng nhập nhanh» .sl-info / .sl-helppop): the button opens and closes it, Escape (focus back
    // on the button) or a click elsewhere closes it. `label` names the button for screen readers and
    // its tooltip («Tìm giá trị này ở đâu»). `align="end"` anchors the note to the right edge, for a
    // label near the end of its row. The slot is the note; it may hold <b>. It has its own small
    // Alpine state, so it also works inside a part that starts later (admin-lazy.js).
    $pid = 'hp-'.substr(md5($label.'|'.$slot), 0, 10);
@endphp
<span {{ $attributes->merge(['class' => 'relative inline-flex shrink-0 align-middle normal-case tracking-normal']) }}
      x-data="{ open: false }" x-on:click.outside="open = false"
      x-on:keydown.escape="if (open) { $event.stopPropagation(); open = false; $refs.btn.focus() }">
    <button type="button" x-ref="btn" x-on:click="open = ! open"
            aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $pid }}"
            aria-label="{{ $label }}" title="{{ $label }}"
            class="hit-area inline-flex size-[calc(1.625rem+var(--user-text-boost))] items-center justify-center rounded-full text-text-muted transition-colors hover:bg-primary-soft hover:text-primary-text aria-expanded:text-primary-text">
        <x-bs-admin::icon name="ri-question" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))]" />
    </button>
    <span id="{{ $pid }}" role="note" x-show="open" x-cloak
          @class([
              'absolute top-full z-50 mt-1 w-[min(20rem,calc(100vw-4rem))] rounded-md border border-border-strong bg-surface px-3 py-3 text-start text-caption font-normal leading-relaxed whitespace-normal text-text-muted shadow-lg [&_b]:font-semibold [&_b]:text-text-strong',
              'end-0' => $align === 'end',
              'start-0' => $align !== 'end',
          ])>{{ $slot }}</span>
</span>

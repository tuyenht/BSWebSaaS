@props([
    'title',
    'subtitle' => null,
    'close',
    'returnTo' => null,
    'closeLabel' => null,
    'busy' => null,
    'state' => null,
    'wide' => false,
    'guard' => null,
])
@php
    // A details panel (canvas .nk-drw): from the right edge on wider screens, from the bottom below 600px, over a dimmed
    // page. The host renders it only while it is open (Livewire state, so a reload or a link keeps it) and names its
    // public method that closes it.
    //
    // Layers: the panel sits under every dialog (z-90: modal, confirm-dialog, the media picker), so a dialog opened from
    // the panel shows over it; while such a dialog is open, Esc and Tab are that dialog's, not the panel's.
    // Keyboard: focus goes to ✕ on opening and stays inside (Tab wraps, and brings back focus that left the panel, as a
    // click on plain text does); Esc anywhere, ✕, the footer's own close button or a tap on the dimmed page close it,
    // and focus returns to the element whose id is $returnTo (the row that opened it — it survives the re-render, the
    // old element does not), or to the page heading when that element is not on the page (opened from a link). $close is printed into JS: a fixed method name, never input.
    // $busy (optional, a fixed method name too): while the host's call to it runs, the panel is aria-busy, ✕ is aria-disabled
    // and nothing closes the panel (an irreversible step must not be left half-seen).
    // The panel counts as an open [data-bs-modal] (it is on the page only while open), so a dialog closed over it
    // leaves the page behind locked.
    //
    // A screen that keeps its state in the browser passes `state` (an Alpine expression: the panel is
    // drawn while it is set) and `close` as the Alpine call that asks to close it (the screen may
    // ask first); a `heading` slot replaces the title when it is drawn from that state. Focus goes
    // back to the element that had it when the panel opened, once the panel is gone.
    //
    // `wide` (board 12's file drawer): 520px from the right on wider screens and the whole screen
    // below 600px, where a bottom sheet would leave the fields too little room. An `actions` slot
    // puts the panel's own buttons (a ⋮ menu) before ✕.
    // `guard` is the id of the element that holds the panel's <x-bs-admin::unsaved-dialog> (outside
    // the panel: the panel is a size container, which would trap the dialog's fixed layer). Every
    // way of closing then asks that dialog first while something is unsaved; the dialog's own
    // buttons save or drop the change and then close (the question opens after the key or click
    // that asked has finished, so the dialog's own Esc = «Ở lại» does not take the same Esc). Until
    // its script has started, a close with unsaved changes (the count the server drew) does nothing
    // rather than lose them. The panel then carries data-unsaved-scope="{guard}", which the dialog's
    // `scope` names so that only text typed in the panel counts as unsaved.
    $alpine = $state !== null;
    $tid = 'drw-'.\Illuminate\Support\Str::random(6);
    $closeLabel ??= __('admin.dismiss');
    $hideBody = $alpine
        ? $close.';'
        : 'const back = this.back; $wire.$call('.\Illuminate\Support\Js::from($close)->toHtml().').then(() => { let to = back && document.getElementById(back); if (! to) { to = document.querySelector(\'h1\'); to?.setAttribute(\'tabindex\', \'-1\'); } to?.focus(); });';
    $opener = $alpine ? ' this.opener = document.activeElement;' : '';
    $giveBack = $alpine ? ' const o = this.opener; setTimeout(() => { if (o && o.isConnected) o.focus(); }, 0);' : '';
@endphp
@if ($alpine)
<template x-if="{{ $state }}">
@endif
<div data-bs-modal x-data="{
         open: true,
         back: @js($returnTo),
         opener: null,
         init() { {!! $opener !!} document.body.style.overflow = 'hidden'; this.$nextTick(() => this.$refs.x.focus()); },
         destroy() { document.body.style.overflow = ''; {!! $giveBack !!} },
         above() { return [...document.querySelectorAll('[role=dialog][aria-modal=true]')].some((d) => d !== this.$refs.panel && d.getClientRects().length > 0); },
         hide() { if (this.$refs.panel.hasAttribute('aria-busy')) return; if (this.held()) return; {!! $hideBody !!} },
         held() {
             const box = @js($guard) && document.getElementById(@js($guard)); const g = box && box.querySelector('[data-unsaved-guard]');
             if (! g) return false;
             const d = g._x_dataStack && g._x_dataStack[0];
             if (! d || typeof d.dirty !== 'function') { const c = g.querySelector('[data-unsaved-count]'); return !! c && Number(c.getAttribute('data-unsaved-count')) > 0; }
             if (! d.dirty()) return false;
             setTimeout(() => d.ask(() => { {!! $hideBody !!} }, null), 0);
             return true;
         },
         trap(e) { if (this.above()) return; const f = [...this.$refs.panel.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex=\'-1\'])')].filter((el) => el.getClientRects().length); if (! f.length) return; const first = f[0], last = f[f.length - 1]; const a = document.activeElement; if (a === this.$refs.panel || ! this.$refs.panel.contains(a)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); } else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (! e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } },
     }"
     @keydown.escape.window.prevent.stop="above() || hide()"
     @keydown.tab.window="trap($event)"
     @if ($guard) data-unsaved-scope="{{ $guard }}" @endif
     {{ $attributes }}>
    <div @click="hide()" class="fixed inset-0 z-60 bg-[rgb(15_18_30/0.45)]" aria-hidden="true"></div>
    <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="{{ $tid }}" @if ($subtitle) aria-describedby="{{ $tid }}-s" @endif tabindex="-1"
         @if ($busy) wire:loading.attr="aria-busy" wire:target="{{ $busy }}" @endif
         class="fixed z-60 flex flex-col bg-surface text-body text-text shadow-lg @container min-[600px]:inset-y-0 min-[600px]:start-auto min-[600px]:end-0 min-[600px]:max-h-none min-[600px]:rounded-none {{ $wide ? 'inset-x-0 top-0 bottom-0 min-[600px]:w-[min(32.5rem,100vw)]' : 'inset-x-0 bottom-0 max-h-[92dvh] rounded-t-xl min-[600px]:w-[min(30rem,100vw)]' }}">
        <div class="flex shrink-0 items-start gap-3 border-b border-border py-3 pe-2 ps-5">
            <div class="min-w-0 flex-1 pt-1">
                <h2 id="{{ $tid }}" class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $heading ?? $title }}</h2>
                @if ($subtitle)
                    <p id="{{ $tid }}-s" class="m-0 mt-1 text-small text-text-muted [overflow-wrap:anywhere]">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center">{{ $actions }}</div>
            @endisset
            <button type="button" x-ref="x" @click="hide()" aria-label="{{ $closeLabel }}" title="{{ $closeLabel }}"
                    @if ($busy) wire:loading.attr="aria-disabled" wire:target="{{ $busy }}" @endif
                    class="inline-flex size-hit shrink-0 items-center justify-center rounded text-text-muted hover:bg-surface-hover hover:text-text-strong">
                <x-bs-admin::icon name="ri-close" class="size-5" />
            </button>
        </div>
        <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto overscroll-contain px-5 py-4">{{ $slot }}</div>
        @isset($footer)
            <div class="flex shrink-0 flex-wrap justify-end gap-2 border-t border-border px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599px]:*:flex-1 max-[599px]:*:basis-full">{{ $footer }}</div>
        @endisset
    </div>
</div>
@if ($alpine)
</template>
@endif

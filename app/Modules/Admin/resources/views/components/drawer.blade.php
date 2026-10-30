@props([
    'title',
    'subtitle' => null,
    'close',
    'returnTo' => null,
    'closeLabel' => null,
])
@php
    // A details panel (canvas .nk-drw): from the right edge on wider screens, from the bottom below 600px, over a dimmed
    // page. The host renders it only while it is open (Livewire state, so a reload or a link keeps it) and names its
    // public method that closes it.
    //
    // Keyboard: focus goes to ✕ on opening and stays inside (Tab wraps, and brings back focus that left the panel, as a
    // click on plain text does); Esc anywhere, ✕, the footer's own close button or a tap on the dimmed page close it,
    // and focus returns to the element whose id is $returnTo (the row that opened it — it survives the re-render, the
    // old element does not), or to the page heading when that element is not on the page (opened from a link). $close is printed into JS: a fixed method name, never input.
    $tid = 'drw-'.\Illuminate\Support\Str::random(6);
    $closeLabel ??= __('admin.dismiss');
@endphp
<div x-data="{
         back: @js($returnTo),
         init() { document.body.style.overflow = 'hidden'; this.$nextTick(() => this.$refs.x.focus()); },
         destroy() { document.body.style.overflow = ''; },
         hide() { const back = this.back; $wire.$call(@js($close)).then(() => { let to = back && document.getElementById(back); if (! to) { to = document.querySelector('h1'); to?.setAttribute('tabindex', '-1'); } to?.focus(); }); },
         trap(e) { const f = [...this.$refs.panel.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex=\'-1\'])')].filter((el) => el.getClientRects().length); if (! f.length) return; const first = f[0], last = f[f.length - 1]; const a = document.activeElement; if (a === this.$refs.panel || ! this.$refs.panel.contains(a)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); } else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (! e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } },
     }"
     @keydown.escape.window.prevent.stop="hide()"
     @keydown.tab.window="trap($event)"
     {{ $attributes }}>
    <div @click="hide()" class="fixed inset-0 z-60 bg-[rgb(15_18_30/0.45)]" aria-hidden="true"></div>
    <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="{{ $tid }}" tabindex="-1"
         class="fixed inset-x-0 bottom-0 z-60 flex max-h-[92dvh] flex-col rounded-t-xl bg-surface text-body text-text shadow-lg @container min-[600px]:inset-y-0 min-[600px]:start-auto min-[600px]:end-0 min-[600px]:max-h-none min-[600px]:w-[min(30rem,100vw)] min-[600px]:rounded-none">
        <div class="flex shrink-0 items-start gap-3 border-b border-border py-3 pe-2 ps-5">
            <div class="min-w-0 flex-1 pt-1">
                <h2 id="{{ $tid }}" class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="m-0 mt-1 text-small text-text-muted [overflow-wrap:anywhere]">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" x-ref="x" @click="hide()" aria-label="{{ $closeLabel }}" title="{{ $closeLabel }}"
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

@props([
    'title' => null,
    'size' => 'md',
])
@php
    // Self-contained per-instance modal. $trigger opens it; Escape / backdrop / close button
    // shut it. Focus is hand-rolled (no Alpine focus plugin dependency): the opener is stored
    // and refocused on close, and the first focusable inside the dialog is focused on open.
    // Scroll-lock is bound to `open` (released automatically on close or a wire:navigate reset).
    $sizes = ['sm' => 'max-w-sm', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl'];
    $box = $sizes[$size] ?? $sizes['md'];
    $tid = 'modal-title-'.\Illuminate\Support\Str::random(6);
@endphp
<div x-data="{ open: false, opener: null, show() { this.opener = document.activeElement; this.open = true; }, hide() { this.open = false; this.opener?.focus(); } }"
     x-effect="document.body.style.overflow = open ? 'hidden' : ''"
     @keydown.escape.window="open && hide()"
     {{ $attributes }}>
    @isset($trigger)
        <div @click="show()" class="inline-flex cursor-pointer">{{ $trigger }}</div>
    @endisset

    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <div x-show="open" x-transition.opacity @click="hide()" class="absolute inset-0 bg-gray-900/50"></div>

        <div x-show="open" x-transition
             x-effect="if (open) $nextTick(() => $el.querySelector('[autofocus], button, [href], input, select, textarea')?.focus())"
             role="dialog" aria-modal="true" @if ($title) aria-labelledby="{{ $tid }}" @endif
             class="relative w-full {{ $box }} rounded-md border border-gray-200 bg-white shadow-card">
            @if ($title)
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
                    <h5 id="{{ $tid }}" class="text-[1rem] font-semibold text-gray-700">{{ $title }}</h5>
                    <button type="button" @click="hide()" aria-label="{{ __('admin.dismiss') }}"
                            class="-m-1 rounded p-1 text-gray-400 transition-colors hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-admin-primary/30">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                    </button>
                </div>
            @endif
            <div class="p-4">{{ $slot }}</div>
            @isset($footer)
                <div class="flex justify-end gap-2 border-t border-gray-200 px-4 py-3">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>

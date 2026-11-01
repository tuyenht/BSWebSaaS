@props([
    'title' => null,
    'subtitle' => null,
    'size' => 'md',
    'model' => null,
    'closeLabel' => null,
])
@php
    // Self-contained per-instance modal. $trigger opens it; Escape / backdrop / close button
    // shut it. Focus: the opener is stored and refocused on close, and the first focusable inside
    // the dialog (or the one marked [autofocus]) is focused on open; Tab stays inside while open.
    // Scroll-lock is bound to `open` (released automatically on close or a wire:navigate reset).
    //
    // `model` hands the open state to a Livewire property instead (entangled live): the server
    // opens it, and every way of closing it sets the property back. A model dialog is the shared
    // form dialog (design .kh-dlg): centred, at most the screen's height less 32px with its body
    // scrolling between a fixed header and footer; below 600px a sheet from the bottom, full width,
    // its footer buttons stacked full width. `subtitle` is one line under the title; `closeLabel`
    // names the ✕ ("Đóng hộp Đổi gói").
    $sizes = ['sm' => 'max-w-sm', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl', 'wide' => 'max-w-160'];
    $box = $sizes[$size] ?? $sizes['md'];
    $sheetBoxes = ['sm' => 'min-[600px]:max-w-sm', 'md' => 'min-[600px]:max-w-lg', 'lg' => 'min-[600px]:max-w-2xl', 'wide' => 'min-[600px]:max-w-160'];
    $sheetBox = $sheetBoxes[$size] ?? $sheetBoxes['md'];
    $tid = $model ? 'modal-title-'.substr(md5($model), 0, 8) : 'modal-title-'.\Illuminate\Support\Str::random(6);
    $state = $model
        ? "{ open: \$wire.entangle('".$model."').live, opener: null, show() { this.open = true; }, hide() { this.open = false; } }"
        : '{ open: false, opener: null, show() { this.opener = document.activeElement; this.open = true; }, hide() { this.open = false; this.opener?.focus(); } }';
@endphp
<div x-data="{{ $state }}"
     x-effect="document.body.style.overflow = open ? 'hidden' : ''"
     @keydown.escape.window="open && hide()"
     {{ $attributes }}>
    @isset($trigger)
        <div @click="show()" class="inline-flex cursor-pointer">{{ $trigger }}</div>
    @endisset

    @if ($model)
        <div x-show="open" x-cloak class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
            <div x-show="open" x-transition.opacity @click="hide()" class="absolute inset-0 bg-gray-900/45"></div>

            <div x-show="open" x-transition x-trap="open"
                 role="dialog" aria-modal="true" aria-labelledby="{{ $tid }}"
                 class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] {{ $sheetBox }} min-[600px]:rounded-md">
                <div class="flex items-start gap-3 border-b border-border px-5 py-4">
                    <div class="min-w-0 flex-1">
                        <h2 id="{{ $tid }}" class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h2>
                        @if ($subtitle)
                            <p class="m-0 mt-1 text-small text-text-muted [overflow-wrap:anywhere]">{{ $subtitle }}</p>
                        @endif
                    </div>
                    <x-bs-admin::button variant="ghost" color="primary" icon @click="hide()" :title="__('admin.close')" :aria-label="$closeLabel ?? __('admin.dismiss')">
                        <x-bs-admin::icon name="ri-close" class="size-5" />
                    </x-bs-admin::button>
                </div>
                <div class="flex min-h-0 flex-1 flex-col gap-3.5 overflow-auto px-5 py-4">{{ $slot }}</div>
                @isset($footer)
                    <div class="flex flex-wrap justify-end gap-2 border-t border-border px-5 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599.98px]:*:flex-[1_1_100%]">{{ $footer }}</div>
                @endisset
            </div>
        </div>
    @else
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div x-show="open" x-transition.opacity @click="hide()" class="absolute inset-0 bg-gray-900/50"></div>

            <div x-show="open" x-transition
                 x-effect="if (open) $nextTick(() => $el.querySelector('[autofocus], button, [href], input, select, textarea')?.focus())"
                 role="dialog" aria-modal="true" @if ($title) aria-labelledby="{{ $tid }}" @endif
                 class="relative w-full {{ $box }} rounded-md border border-border bg-surface shadow-card">
                @if ($title)
                    <div class="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                        <h5 id="{{ $tid }}" class="text-[1rem] font-semibold text-text">{{ $title }}</h5>
                        {{-- An icon control: muted ink keeps it >= 3:1 (the placeholder gray read 1.5:1). --}}
                        <button type="button" @click="hide()" aria-label="{{ __('admin.dismiss') }}"
                                class="-m-1 rounded p-1 text-text-muted transition-colors hover:text-text">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
                        </button>
                    </div>
                @endif
                <div class="p-4">{{ $slot }}</div>
                @isset($footer)
                    <div class="flex justify-end gap-2 border-t border-border px-4 py-3">{{ $footer }}</div>
                @endisset
            </div>
        </div>
    @endif
</div>

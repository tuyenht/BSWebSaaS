@props([
    // the address of the saved version (the editor's preview route), drawn inside the frame
    'url',
    // what is being previewed, inside a sentence: «bài viết», «trang», «tin tức»
    'unit',
])
@php
    // «Xem trước trên website» (design 07 .pe-pv and content-ui.js previewSite, one dialog for every
    // editor): the record's saved version, as the website draws it, in a frame inside the editor at a
    // computer's width or a phone's (390px), so the member never leaves the form. Opened by the editor
    // frame's «Xem trước» (the window event `bs-site-preview`). Escape, the backdrop or «Đóng» close it,
    // and focus goes back to what opened it (the ⋮ when that was the phone's menu item, gone by then).
    // The frame is in the Tab order (a keyboard reaches the website to scroll it), and Escape pressed
    // inside it closes the dialog too. It stacks and locks the page as the shared modal does (data-bs-modal).
    // Behaviour: resources/js/admin-site-preview.js, started by the shared lazy loader.
    // The frame loads again on every open, so a save in between shows; its address is read from the root
    // (data-url), which the server keeps current when the form switches language. Up to 600px wide it fills the
    // screen. The website keeps its own light colours inside the frame.
@endphp
<div x-ignore wire:ignore.self data-bs-lazy="bsSitePreview" x-data="bsSitePreview" data-bs-modal
     x-on:bs-site-preview.window="show()"
     x-on:keydown.escape.window="if (open) { const up = [...document.querySelectorAll('[aria-modal=true]')].filter((d) => d.getClientRects().length > 0); if (up.length === 0 || $el.contains(up[up.length - 1])) { hide(); } }"
     data-site-preview data-url="{{ $url }}">
    <div x-show="open" x-cloak class="fixed inset-0 z-90 flex items-center justify-center min-[601px]:p-6">
        <div class="absolute inset-0 bg-gray-900/50" x-on:click="hide()"></div>
        <div role="dialog" aria-modal="true" aria-labelledby="site-pv-t" x-trap="open"
             class="relative flex h-dvh w-full flex-col overflow-hidden bg-surface pb-[env(safe-area-inset-bottom)] shadow-lg min-[601px]:h-[min(820px,100%)] min-[601px]:max-w-[1100px] min-[601px]:rounded-md">
            <div class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3">
                <h2 id="site-pv-t" class="m-0 text-[calc(0.9375rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('entries.pv.title') }}</h2>
                <span class="flex-1"></span>
                <span role="group" aria-label="{{ __('entries.pv.devices') }}" class="inline-flex flex-wrap gap-2">
                    <x-bs-admin::button variant="outline" color="neutral" aria-pressed="true" ::aria-pressed="(dev === 'd').toString()" x-on:click="dev = 'd'">
                        <x-bs-admin::icon name="ri-computer" class="size-[1.15em] shrink-0" /> {{ __('entries.pv.desktop') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button variant="outline" color="neutral" aria-pressed="false" ::aria-pressed="(dev === 'm').toString()" x-on:click="dev = 'm'">
                        <x-bs-admin::icon name="ri-smartphone" class="size-[1.15em] shrink-0" /> {{ __('entries.pv.phone') }}
                    </x-bs-admin::button>
                </span>
                <x-bs-admin::button variant="outline" color="neutral" data-site-preview-close :aria-label="__('entries.pv.close_aria')" x-on:click="hide()">
                    <x-bs-admin::icon name="ri-close" class="size-[1.15em] shrink-0" /> {{ __('entries.pv.close') }}
                </x-bs-admin::button>
            </div>
            <div class="flex min-h-0 flex-1 justify-center overflow-auto bg-bg p-4">
                <template x-if="open">
                    <iframe x-bind:src="$root.dataset.url" tabindex="0" x-on:load="frameKeys($el)" title="{{ __('entries.pv.frame', ['unit' => $unit]) }}" class="h-full min-h-96 w-full rounded-md border border-border bg-white shadow-card"
                            x-bind:class="dev === 'm' ? 'max-w-[390px]' : 'max-w-[1024px]'"></iframe>
                </template>
            </div>
            <p class="m-0 border-t border-border px-4 py-2 text-[calc(0.8125rem+var(--user-text-boost))] leading-normal text-text-muted">{{ __('entries.pv.hint', ['unit' => $unit]) }}</p>
        </div>
    </div>
</div>

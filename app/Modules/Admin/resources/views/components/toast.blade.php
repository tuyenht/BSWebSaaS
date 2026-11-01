@props([
    'message' => '',
    'dismiss',
])
@php
    // A short success message at the bottom of the screen (design 03 .kh-toast), read out politely.
    // It stays at least 8 seconds, then hides by itself; "Đóng" hides it at once. $dismiss is the
    // host's public method that clears the message. The live region is always on the page, so a
    // screen reader hears the text when it arrives.
@endphp
<div role="status" aria-live="polite"
     class="pointer-events-none fixed bottom-[max(1rem,env(safe-area-inset-bottom))] left-1/2 z-95 w-[min(35rem,calc(100vw-1.5rem))] -translate-x-1/2">
    @if ($message !== '')
        <div wire:key="toast-{{ md5($message) }}" x-data x-init="setTimeout(() => $wire.{{ $dismiss }}(), 8000)"
             class="pointer-events-auto flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md bg-text-strong px-4 py-3 text-body text-surface shadow-lg">
            <x-bs-admin::icon name="ri-check" class="size-5 shrink-0" />
            <span class="min-w-0 flex-[1_1_13.75rem] [overflow-wrap:anywhere]">{{ $message }}</span>
            <button type="button" wire:click="{{ $dismiss }}"
                    class="hit-area inline-flex min-h-control items-center whitespace-nowrap rounded border border-current px-3 font-semibold any-pointer-coarse:min-h-hit">{{ __('admin.close') }}</button>
        </div>
    @endif
</div>

@props([
    'message' => '',
    'dismiss',
    'tone' => 'success',
    'undo' => null,
    'seq' => null,
])
@php
    // A short message at the bottom of the screen (design 03 .kh-toast, 21 .tm-tst), read out
    // politely. Success and info stay 6 seconds, then hide by themselves; with `undo` (the host's
    // public method that reverses the change) it shows "Hoàn tác" and stays 10 seconds. An error
    // (`tone="error"`) stays until the member closes it. "Đóng" hides it at once. $dismiss is the
    // host's public method that clears the message; with `seq` (the host's message counter, which
    // makes even the same words a new message) it is called with that number, and the host clears
    // only the message it names — so a timer that fires while a newer message is on its way never
    // takes the newer one away. A new message also replaces the element, clearing its timer. The live
    // region is always on the page, so a screen reader hears the text when it arrives.
    $icon = ['error' => 'ri-error-warning', 'info' => 'ri-information'][$tone] ?? 'ri-check';
    $wait = $undo ? 10000 : 6000;
    $call = $dismiss.'('.($seq !== null ? (int) $seq : '').')';
@endphp
<div role="status" aria-live="polite"
     class="pointer-events-none fixed bottom-[max(1rem,env(safe-area-inset-bottom))] left-1/2 z-95 w-[min(35rem,calc(100vw-1.5rem))] -translate-x-1/2">
    @if ($message !== '')
        <div wire:key="toast-{{ md5($tone.$message.($undo ?? '').'|'.($seq ?? '')) }}"
             @if ($tone !== 'error') x-data="{ timer: null, init() { this.timer = setTimeout(() => this.$wire.{{ $call }}, {{ $wait }}) }, destroy() { clearTimeout(this.timer) } }" @endif
             data-tone="{{ $tone }}"
             class="pointer-events-auto flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md bg-text-strong px-4 py-3 text-body text-surface shadow-lg">
            <x-bs-admin::icon :name="$icon" class="size-5 shrink-0" />
            <span class="min-w-0 flex-[1_1_13.75rem] [overflow-wrap:anywhere]">{{ $message }}</span>
            @if ($undo)
                <button type="button" wire:click="{{ $undo }}"
                        class="hit-area inline-flex min-h-control items-center whitespace-nowrap rounded border border-current px-3 font-semibold any-pointer-coarse:min-h-hit">{{ __('admin.undo') }}</button>
            @endif
            <button type="button" wire:click="{{ $call }}"
                    class="hit-area inline-flex min-h-control items-center whitespace-nowrap rounded border border-current px-3 font-semibold any-pointer-coarse:min-h-hit">{{ __('admin.close') }}</button>
        </div>
    @endif
</div>

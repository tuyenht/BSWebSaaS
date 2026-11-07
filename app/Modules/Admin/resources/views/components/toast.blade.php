@props([
    'message' => '',
    'dismiss',
    'tone' => 'success',
    'undo' => null,
    'seq' => null,
    'state' => null,
    'raised' => false,
    'action' => null,
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
    //
    // A screen that keeps its state in the browser passes `state`: an Alpine expression for its
    // message { text, tone, undo, seq } (seq: a number new for each message). The same look and the
    // same timing — an error stays until closed — with `dismiss` (called with the seq) and `undo`
    // naming the Alpine methods. `raised` lifts it above a bar stuck to the bottom of the screen.
    // `action` (state only) names the Alpine method behind one more button, shown when the message
    // carries its label as `act` ("Thử lại", "Tải lại", "Mở …"); a message with one stays 10 seconds.
    $icon = ['error' => 'ri-error-warning', 'info' => 'ri-information'][$tone] ?? 'ri-check';
    $wait = $undo ? 10000 : 6000;
    $call = $dismiss.'('.($seq !== null ? (int) $seq : '').')';
    $bottom = $raised ? 'bottom-[max(5.5rem,env(safe-area-inset-bottom))]' : 'bottom-[max(1rem,env(safe-area-inset-bottom))]';
    $box = 'pointer-events-auto flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md bg-text-strong px-4 py-3 text-body text-surface shadow-lg';
    $btn = 'hit-area inline-flex min-h-control items-center whitespace-nowrap rounded border border-current px-3 font-semibold any-pointer-coarse:min-h-hit';
@endphp
<div role="status" aria-live="polite"
     class="pointer-events-none fixed {{ $bottom }} left-1/2 z-95 w-[min(35rem,calc(100vw-1.5rem))] -translate-x-1/2">
    @if ($state !== null)
        <template x-for="s in (({{ $state }}) && ({{ $state }}).text ? [{{ $state }}] : [])" :key="s.seq">
            <div x-data="{ timer: null, init() { if (s.tone !== 'error') { this.timer = setTimeout(() => {{ $dismiss }}(s.seq), s.undo || s.act ? 10000 : 6000); } }, destroy() { clearTimeout(this.timer); } }"
                 x-bind:data-tone="s.tone" class="{{ $box }}">
                <span x-show="s.tone === 'error'"><x-bs-admin::icon name="ri-error-warning" class="size-5 shrink-0" /></span>
                <span x-show="s.tone === 'info'"><x-bs-admin::icon name="ri-information" class="size-5 shrink-0" /></span>
                <span x-show="s.tone !== 'error' && s.tone !== 'info'"><x-bs-admin::icon name="ri-check" class="size-5 shrink-0" /></span>
                <span class="min-w-0 flex-[1_1_13.75rem] [overflow-wrap:anywhere]" x-text="s.text"></span>
                @if ($action)
                    <button type="button" x-show="s.act" x-on:click="{{ $action }}()" class="{{ $btn }}" x-text="s.act"></button>
                @endif
                @if ($undo)
                    <button type="button" x-show="s.undo" x-on:click="{{ $undo }}()" class="{{ $btn }}">{{ __('admin.undo') }}</button>
                @endif
                <button type="button" x-on:click="{{ $dismiss }}(s.seq)" class="{{ $btn }}">{{ __('admin.close') }}</button>
            </div>
        </template>
    @elseif ($message !== '')
        <div wire:key="toast-{{ md5($tone.$message.($undo ?? '').'|'.($seq ?? '')) }}"
             @if ($tone !== 'error')
                 x-data="{ timer: null, end: 0, left: {{ $wait }}, arm(ms) { this.end = Date.now() + ms; this.timer = setTimeout(() => this.$wire.{{ $call }}, ms) }, hold() { if (this.timer === null) { return } clearTimeout(this.timer); this.timer = null; this.left = Math.max(0, this.end - Date.now()) }, release() { if (this.timer === null) { this.arm(Math.max(this.left, 3000)) } }, init() { this.arm({{ $wait }}) }, destroy() { clearTimeout(this.timer) } }"
                 x-on:mouseenter="hold()" x-on:mouseleave="release()" x-on:focusin="hold()" x-on:focusout="release()"
             @endif
             data-tone="{{ $tone }}"
             class="{{ $box }}">
            <x-bs-admin::icon :name="$icon" class="size-5 shrink-0" />
            <span class="min-w-0 flex-[1_1_13.75rem] [overflow-wrap:anywhere]">{{ $message }}</span>
            @if ($undo)
                <button type="button" wire:click="{{ $undo }}"
                        class="{{ $btn }}">{{ __('admin.undo') }}</button>
            @endif
            <button type="button" wire:click="{{ $call }}"
                    class="hit-area inline-flex min-h-control items-center whitespace-nowrap rounded border border-current px-3 font-semibold any-pointer-coarse:min-h-hit">{{ __('admin.close') }}</button>
        </div>
    @endif
</div>

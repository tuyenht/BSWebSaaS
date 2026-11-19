{{-- «Khi lưu, bài sẽ là» (board 07 «Trình sửa trang», the "Đăng" card where no approval workflow governs the entry):
     a button showing the status the next save leaves the entry in, opening the board's list of statuses (a sheet
     from the bottom on a phone, with its heading and a close button). A status this member may not reach stays,
     locked, with its reason on a line under it; a tap on it says the reason. A pick goes to the server
     (Editor::updatedStatusTo), which checks it again; nothing moves until the save, and the line under the button
     says so («Chưa áp dụng…»), otherwise it says what the saved status means. Arrows, Home and End walk the list,
     Enter or Space picks, Esc closes; the focus comes back to the button.
     Needs: $panel (Editor::statusPanel), $label, $glyph. --}}
@php
    use Illuminate\Support\Js;

    $lockMark = \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-lock" class="size-4" />');
    // the status-pill's own inks (components/status-pill.blade.php), as the board's dots
    $inks = [
        'published' => 'var(--color-success-text)',
        'draft' => 'var(--color-text)',
        'pending' => 'var(--color-warning-text)',
        'scheduled' => 'color-mix(in oklab,var(--color-secondary-text) 80%,var(--color-text-strong))',
        'archived' => 'var(--color-text-muted)',
    ];
    $dot = static fn (string $key): string => 'background:'.($inks[$key] ?? 'var(--color-text-muted)');
@endphp
<div x-data="{
        open: false,
        toggle() { if (this.open) { this.close(true); return; } this.open = true; this.$nextTick(() => this.focusAt(this.items().findIndex((o) => o.getAttribute('aria-selected') === 'true'))); },
        close(back) { this.open = false; if (back) this.$nextTick(() => this.$refs.btn.focus()); },
        sheet() { return window.matchMedia('(max-width: 599.98px)').matches; },
        items() { return this.$refs.list ? [...this.$refs.list.querySelectorAll('[role=option]')] : []; },
        focusAt(i) { const L = this.items(); if (! L.length) return; const t = L[Math.max(0, Math.min(i, L.length - 1))]; L.forEach((o) => o.setAttribute('tabindex', o === t ? '0' : '-1')); t.focus({ preventScroll: true }); },
        key(e) {
            const L = this.items(), at = L.indexOf(document.activeElement);
            if (e.key === 'ArrowDown') { e.preventDefault(); this.focusAt(at < 0 ? 0 : (at + 1) % L.length); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.focusAt(at < 0 ? 0 : (at - 1 + L.length) % L.length); }
            else if (e.key === 'Home') { e.preventDefault(); this.focusAt(0); }
            else if (e.key === 'End') { e.preventDefault(); this.focusAt(L.length - 1); }
            else if ((e.key === 'Enter' || e.key === ' ') && at >= 0) { e.preventDefault(); L[at].click(); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.close(true); }
            else if (e.key === 'Tab') {
                // the phone's sheet keeps the focus inside until it is closed: the close button and the picked status
                if (this.sheet()) { e.preventDefault(); const x = this.$refs.x, sel = L.find((o) => o.getAttribute('aria-selected') === 'true') || L[0]; (document.activeElement === x ? sel : x)?.focus(); return; }
                this.close(false);
            }
        },
        choose(key, why) {
            if (why) { this.$bsToast({ text: why, icon: {{ Js::from($lockMark) }}, stay: true, close: {{ Js::from(__('admin.close')) }} }); return; }
            this.close(true);
            if (key !== this.$wire.statusTo) { this.$wire.set('statusTo', key); }
        },
     }" x-on:click.outside="open && ! sheet() && close(false)" x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true); }">
    <span id="entry-st-l" class="{{ $label }} mb-1.5">{{ $panel['label'] }}</span>
    @if ($panel['homeWhy'] !== null)
        <p id="entry-st-home" class="mt-0 mb-2 flex items-start gap-1.5 text-small text-text-muted">
            <x-bs-admin::icon name="ri-home-4" class="mt-0.5 size-4 shrink-0" /><span>{{ $panel['homeWhy'] }}</span>
        </p>
    @endif
    <div class="relative">
        <button type="button" id="entry-st-btn" x-ref="btn" data-status-btn aria-haspopup="listbox" x-bind:aria-expanded="open ? 'true' : 'false'" aria-labelledby="entry-st-l entry-st-btn"
                @error('statusTo') aria-invalid="true" aria-describedby="entry-st-e" @enderror
                x-on:click="toggle()" x-on:keydown.arrow-down.prevent="open || toggle()" x-on:keydown.arrow-up.prevent="open || toggle()"
                class="field flex w-full items-center gap-2 rounded-md border border-border-input bg-surface px-3 text-start font-semibold text-text-strong transition-colors hover:bg-surface-hover focus:border-primary-text aria-expanded:border-primary-text aria-invalid:border-danger-text">
            <span class="size-2 shrink-0 rounded-full" style="{{ $dot($panel['current']) }}" aria-hidden="true"></span>
            <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ $panel['currentShort'] }}</span>
            <x-bs-admin::icon name="ri-arrow-down-s" :class="$glyph.' text-text-muted transition-transform'" x-bind:class="open && 'rotate-180'" />
        </button>
        <div x-show="open" x-cloak class="fixed inset-0 z-[1000] bg-[rgb(15_23_41/0.45)] min-[600px]:hidden" aria-hidden="true" x-on:click="close(true)"></div>
        <div x-show="open" x-cloak role="dialog" aria-modal="false" aria-labelledby="entry-st-h" x-on:keydown="key($event)"
             class="absolute inset-x-0 top-[calc(100%+4px)] z-30 max-h-[min(60vh,420px)] overflow-auto rounded-md border border-border-input bg-surface p-1 shadow-card
                    max-[600px]:fixed max-[600px]:inset-x-0 max-[600px]:top-auto max-[600px]:bottom-0 max-[600px]:z-[1001] max-[600px]:max-h-[75vh] max-[600px]:rounded-none max-[600px]:rounded-t-xl max-[600px]:border-x-0 max-[600px]:border-b-0 max-[600px]:p-2 max-[600px]:pb-[calc(8px+env(safe-area-inset-bottom))]">
            <div class="hidden items-center justify-between gap-2 pb-1 ps-2 text-[calc(0.9375rem+var(--user-text-boost))] font-semibold text-text-strong max-[600px]:flex">
                <span id="entry-st-h">{{ __('entries.ed.st_sheet') }}</span>
                <button type="button" x-ref="x" class="flex size-(--spacing-control) shrink-0 items-center justify-center rounded text-text hover:bg-surface-hover" title="{{ __('admin.close') }}" aria-label="{{ __('admin.close') }}" x-on:click="close(true)">
                    <x-bs-admin::icon name="ri-close" class="size-5" />
                </button>
            </div>
            <ul x-ref="list" role="listbox" aria-labelledby="entry-st-l" class="m-0 list-none p-0">
                @foreach ($panel['options'] as $o)
                    @php $sel = $o['key'] === $panel['current']; @endphp
                    <li role="option" id="entry-st-o-{{ $o['key'] }}" tabindex="-1" wire:key="st-o-{{ $o['key'] }}" data-st="{{ $o['key'] }}"
                        aria-selected="{{ $sel ? 'true' : 'false' }}" @if ($o['why'] !== '') aria-disabled="true" @endif
                        x-on:click="choose({{ Js::from($o['key']) }}, {{ Js::from($o['why']) }})"
                        class="grid min-h-menu-row cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-2.5 gap-y-0.5 rounded px-2.5 py-2 text-text-strong any-pointer-coarse:min-h-menu-row-touch hover:bg-surface-hover focus:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text aria-disabled:cursor-not-allowed aria-disabled:text-text-muted">
                        <span class="flex h-[calc(1.125rem+var(--user-text-boost))] items-center" aria-hidden="true"><span class="size-2 rounded-full" style="{{ $dot($o['key']) }}"></span></span>
                        <span class="min-w-0">
                            <b class="block text-body leading-snug font-semibold [overflow-wrap:anywhere]">{{ $o['short'] }}</b>
                            @if ($o['sub'] !== '')
                                <small class="block text-caption leading-normal text-text-muted [overflow-wrap:anywhere]">{{ $o['sub'] }}</small>
                            @endif
                        </span>
                        <span class="flex h-[calc(1.125rem+var(--user-text-boost))] items-center" aria-hidden="true">
                            @if ($sel)
                                <x-bs-admin::icon name="ri-check" class="size-4 text-primary-text" />
                            @elseif ($o['why'] !== '')
                                <x-bs-admin::icon name="ri-lock" class="size-3.5 text-text-muted" />
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    @error('statusTo')
        <x-bs-admin::field-error id="entry-st-e" class="mt-1.5">{{ $message }}</x-bs-admin::field-error>
    @enderror
    @if ($panel['pending'] !== null)
        <p role="status" class="mt-2 mb-0 flex items-start gap-1.5 text-small leading-normal text-text">
            <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ $panel['pending'] }}</span>
        </p>
    @else
        <p class="mt-2 mb-0 flex items-start gap-1.5 text-small leading-normal text-text-muted">
            <x-bs-admin::icon :name="$panel['vis']['icon']" class="mt-0.5 size-4 shrink-0" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ $panel['vis']['text'] }}</span>
        </p>
    @endif
</div>

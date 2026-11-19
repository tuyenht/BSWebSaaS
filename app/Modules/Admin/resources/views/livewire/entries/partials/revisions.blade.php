{{-- «Lịch sử phiên bản» in the «Đăng» card (board 07): the history of the language being edited, read
     when the button opens it (Editor::loadRevisions; a failed read says so and offers «Thử lại»). Its
     newest three rows, each with «Xem» and, for an older one, «Khôi phục»; then «Xem tất cả» opens the
     full list. The dialogs are partials/revision-dialogs. While one of them is open the region stays
     open, so focus can come back to the row it was opened from. --}}
@php
    $rowGlyph = 'size-[1.15em] shrink-0';
@endphp
<div class="relative" data-entry-rev
     x-data="{
        open: false, busy: false, failed: false,
        load() { this.busy = true; this.failed = false; $wire.loadRevisions().then(() => { this.busy = false; }, () => { this.busy = false; this.failed = true; }); },
        toggle() { this.open = ! this.open; if (this.open) { this.load(); } },
        dialogs() { return $wire.revAllOpen || $wire.revViewOpen || $wire.revAskOpen; },
     }"
     x-on:keydown.escape="if (open && ! dialogs()) { open = false; $refs.btn.focus(); }"
     x-on:click.outside="if (! dialogs() && ! $event.target.closest('[data-entry-rev-all],[data-entry-rev-view],[data-entry-rev-ask]')) { open = false; }">
    <button type="button" x-ref="btn" x-on:click="toggle()" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="entry-rev-pop"
            class="flex min-h-(--spacing-control) w-full items-center gap-2 rounded-md border border-border-input bg-surface px-2.5 py-2 text-left text-body font-medium text-text transition-colors hover:bg-surface-hover">
        <x-bs-admin::icon name="ri-history" class="size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0 text-text-muted" />
        <span class="min-w-0 [overflow-wrap:anywhere]">{{ $revisions['button'] }}</span>
        <x-bs-admin::icon name="ri-arrow-down-s" class="ms-auto size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0 text-text-muted transition-transform" x-bind:class="open && 'rotate-180'" />
    </button>
    <div id="entry-rev-pop" role="region" aria-label="{{ __('entries.rev.region') }}" x-show="open" x-cloak x-bind:aria-busy="busy ? 'true' : 'false'"
         class="mt-2 flex flex-col gap-2 rounded-md border border-border bg-surface p-2.5">
        <div class="text-caption font-semibold text-text-muted">{{ $revisions['head'] }}</div>
        @if ($revNote !== null)
            <x-bs-admin::alert role="status" color="success" icon="ri-checkbox-circle" class="!py-2 text-small">{{ $revNote['text'] }}</x-bs-admin::alert>
        @endif
        <template x-if="busy">
            <div class="flex flex-col gap-2" aria-hidden="true">
                <div class="h-11 rounded-sm bg-surface-hover"></div>
                <div class="h-11 rounded-sm bg-surface-hover"></div>
            </div>
        </template>
        <span class="text-caption text-text-muted" x-show="busy" x-cloak>{{ __('entries.rev.loading') }}</span>
        <div x-show="failed" x-cloak class="flex flex-col items-start gap-2">
            <x-bs-admin::alert color="danger" icon="ri-wifi-off" class="!py-2 text-small">{{ __('entries.rev.load_err') }}</x-bs-admin::alert>
            <x-bs-admin::button variant="outline" color="neutral" size="sm" x-on:click="load()">
                <x-bs-admin::icon name="ri-refresh" :class="$rowGlyph" /> {{ __('entries.rev.retry') }}
            </x-bs-admin::button>
        </div>
        <div x-show="! busy && ! failed" class="flex flex-col gap-2">
            @if ($revRows === [])
                <div class="flex flex-col gap-1 rounded-md border border-dashed border-border-input p-3 text-small text-text-muted">
                    <b class="flex items-center gap-1.5 font-semibold text-text-strong"><x-bs-admin::icon name="ri-history" :class="$rowGlyph" />{{ __('entries.rev.empty_title') }}</b>
                    <span>{{ $revisions['emptyHint'] }}</span>
                </div>
            @else
                <div>
                    @foreach (array_slice($revRows, 0, 3) as $i => $row)
                        @include('admin::livewire.entries.partials.revision-row', ['row' => $row, 'latest' => $i === 0, 'stacked' => true])
                    @endforeach
                </div>
                <x-bs-admin::button variant="outline" color="neutral" size="sm" class="w-full" wire:click="$set('revAllOpen', true)">
                    <x-bs-admin::icon name="ri-list-check-2" :class="$rowGlyph" /> {{ __('entries.rev.all', ['count' => $revTotal]) }}
                </x-bs-admin::button>
            @endif
        </div>
    </div>
</div>

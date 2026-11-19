{{-- The three history dialogs of board 07 (D1-4c), on the shared form dialog (a sheet from the bottom
     below 600px). «Lịch sử phiên bản»: every row, paged by «Tải thêm». «Bản …»: one version beside the
     form's content, each place that differs marked «Khác hiện tại». «Khôi phục bản …?»: what the restore
     changes; a refusal is said in the question, which then offers only «Đóng». A version opened from the
     list sits over it, and Escape closes only the top one. --}}
@php
    $rowGlyph = 'size-[1.15em] shrink-0';
    $chip = 'inline-flex items-center gap-1 rounded-full px-2 py-px text-caption font-semibold whitespace-nowrap';
    $differs = 'border-[color-mix(in_srgb,var(--color-warning-text)_40%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-warning-text)_8%,var(--color-surface))]';
    $small = 'text-[calc(0.78125rem+var(--user-text-boost))]';
@endphp

<x-bs-admin::modal model="revAllOpen" size="wide" :title="$revisions['allTitle']" :subtitle="$revisions['allSub']" :close-label="__('entries.rev.all_close')" data-entry-rev-all>
    <div class="flex flex-col gap-2.5" x-bind:aria-busy="busy ? 'true' : 'false'"
         x-data="{ busy: false, failed: false, more() { this.busy = true; this.failed = false; $wire.moreRevisions().then(() => { this.busy = false; }, () => { this.busy = false; this.failed = true; }); } }">
        @if ($revNote !== null)
            <x-bs-admin::alert role="status" color="success" icon="ri-checkbox-circle" class="!py-2 text-small">{{ $revNote['text'] }}</x-bs-admin::alert>
        @endif
        @if ($revRows === [])
            <div class="flex flex-col gap-1 rounded-md border border-dashed border-border-input p-3 text-small text-text-muted">
                <b class="flex items-center gap-1.5 font-semibold text-text-strong"><x-bs-admin::icon name="ri-history" :class="$rowGlyph" />{{ __('entries.rev.empty_title') }}</b>
                <span>{{ $revisions['emptyHint'] }}</span>
            </div>
        @else
            <div>
                @foreach ($revRows as $i => $row)
                    @include('admin::livewire.entries.partials.revision-row', ['row' => $row, 'latest' => $i === 0, 'stacked' => false])
                @endforeach
            </div>
            <template x-if="busy">
                <div class="h-11 rounded-sm bg-surface-hover" aria-hidden="true"></div>
            </template>
            <span class="text-caption text-text-muted" x-show="busy" x-cloak>{{ __('entries.rev.more_busy') }}</span>
            <div x-show="failed" x-cloak class="flex flex-col items-start gap-2">
                <x-bs-admin::alert color="danger" icon="ri-wifi-off" class="!py-2 text-small">{{ __('entries.rev.load_err_all') }}</x-bs-admin::alert>
                <x-bs-admin::button variant="outline" color="neutral" size="sm" x-on:click="more()">
                    <x-bs-admin::icon name="ri-refresh" :class="$rowGlyph" /> {{ __('entries.rev.retry') }}
                </x-bs-admin::button>
            </div>
            @if ($revCursor !== null)
                <x-bs-admin::button variant="outline" color="neutral" size="sm" class="self-center" x-show="! busy && ! failed" x-on:click="more()" data-rev-more>
                    <x-bs-admin::icon name="ri-arrow-down" :class="$rowGlyph" /> {{ __('entries.rev.more', ['count' => max(0, $revTotal - count($revRows))]) }}
                </x-bs-admin::button>
            @endif
        @endif
        <p class="m-0 border-t border-border pt-1.5 text-caption text-text-muted">{{ $revisions['legend'] }}</p>
    </div>
</x-bs-admin::modal>

<x-bs-admin::modal model="revViewOpen" size="wider" :title="$revView['title'] ?? ''" :subtitle="$revView['sub'] ?? null" :close-label="__('entries.rev.view_close')" data-entry-rev-view>
    @if ($revView !== null)
        <x-bs-admin::alert role="note" color="info" icon="ri-information" class="!py-2 text-small">{{ $revView['sum'] }}</x-bs-admin::alert>
        @foreach ($revView['fields'] as $field)
            <div class="flex flex-col gap-[3px] rounded-sm border px-2.5 py-2 {{ $field['differs'] ? $differs : 'border-border' }}" data-rev-field data-d="{{ $field['differs'] ? '1' : '0' }}">
                <span class="flex flex-wrap items-center gap-1.5 {{ $small }} font-semibold text-text-muted">{{ $field['label'] }}
                    @if ($field['shared'])
                        <span class="{{ $chip }} bg-surface-hover text-text">{{ __('entries.rev.shared_chip') }}</span>
                    @endif
                    @if ($field['differs'])
                        <span class="{{ $chip }} bg-[color-mix(in_srgb,var(--color-warning-text)_12%,var(--color-surface))] text-warning-text"><x-bs-admin::icon name="ri-edit-circle" :class="$rowGlyph" />{{ __('entries.rev.differs') }}</span>
                    @endif
                </span>
                <span class="text-text-strong [overflow-wrap:anywhere]">{{ $field['value'] !== '' ? $field['value'] : __('entries.rev.empty') }}</span>
                @if ($field['differs'])
                    <span class="{{ $small }} text-text-muted [overflow-wrap:anywhere]">{{ __('entries.rev.was', ['value' => $field['now'] !== '' ? $field['now'] : __('entries.rev.empty')]) }}</span>
                @endif
            </div>
        @endforeach
        <h3 class="m-0 mt-2 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('entries.rev.body_h') }}</h3>
        @if ($revView['lines'] === [])
            <span class="text-caption text-text-muted">{{ __('entries.rev.empty') }}</span>
        @endif
        @foreach ($revView['lines'] as $line)
            <div class="rounded-sm border px-2.5 py-1.5 [overflow-wrap:anywhere] {{ $line['differs'] ? $differs : 'border-transparent' }} {{ $line['k'] === 'h' ? 'font-bold text-text-strong' : '' }} {{ $line['k'] === 'li' ? 'relative ps-[22px] before:absolute before:start-2.5 before:content-[\'•\']' : '' }}" data-rev-line data-d="{{ $line['differs'] ? '1' : '0' }}">
                @if ($line['differs'])
                    <span class="{{ $chip }} me-1.5 bg-[color-mix(in_srgb,var(--color-warning-text)_12%,var(--color-surface))] text-warning-text font-semibold"><x-bs-admin::icon name="ri-edit-circle" :class="$rowGlyph" />{{ __('entries.rev.differs') }}</span>
                @endif{{ $line['t'] }}
            </div>
        @endforeach
        @if ($revView['gone'] !== [])
            <h3 class="m-0 mt-2 text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text-muted">{{ __('entries.rev.gone_h') }}</h3>
            @foreach ($revView['gone'] as $line)
                <div class="rounded-sm border border-[color-mix(in_srgb,var(--color-danger-text)_30%,var(--color-surface))] bg-[color-mix(in_srgb,var(--color-danger-text)_7%,var(--color-surface))] px-2.5 py-1.5 text-text [overflow-wrap:anywhere]" data-rev-gone>
                    <span class="{{ $chip }} me-1.5 bg-surface-hover text-text"><x-bs-admin::icon name="ri-subtract" :class="$rowGlyph" />{{ __('entries.rev.gone_chip') }}</span>{{ $line['t'] }}
                </div>
            @endforeach
        @endif
    @endif
    <x-slot:footer>
        <x-bs-admin::button variant="outline" color="neutral" x-on:click="hide()">{{ __('entries.rev.close') }}</x-bs-admin::button>
        @if (($revView['canRestore'] ?? false) === true)
            <x-bs-admin::button color="primary" wire:click="askRestore('{{ $revView['ulid'] }}')">
                <x-bs-admin::icon name="ri-history" :class="$rowGlyph" /> {{ __('entries.rev.restore_this') }}
            </x-bs-admin::button>
        @endif
    </x-slot:footer>
</x-bs-admin::modal>

{{-- While the restore runs the question cannot be closed (✕, backdrop, Esc): aria-busy holds it --}}
<x-bs-admin::modal model="revAskOpen" size="narrow" compact alert :close-label="__('entries.rev.cancel')" data-entry-rev-ask wire:loading.attr="aria-busy" wire:target="restoreRevision">
    <x-slot:heading>
        <span class="flex items-center gap-2.5">
            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-[color-mix(in_srgb,var(--color-primary-text)_10%,var(--color-surface))] text-primary-text" aria-hidden="true">
                <x-bs-admin::icon name="ri-history" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
            </span>
            <span>{{ $revAsk['title'] ?? '' }}</span>
        </span>
    </x-slot:heading>
    @if ($revAsk !== null)
        @php $line = 'flex items-start gap-2'; $lineGlyph = 'mt-[3px] size-[1.15em] shrink-0 text-text-muted'; @endphp
        <div class="{{ $line }}">
            <x-bs-admin::icon :name="$revAsk['live'] ? 'ri-global' : 'ri-git-branch'" :class="$lineGlyph" /><span>{{ $revAsk['live'] ? $revAsk['liveLine'] : $revAsk['statusLine'] }}</span>
        </div>
        <div class="{{ $line }}"><x-bs-admin::icon name="ri-file-copy-2" :class="$lineGlyph" /><span>{{ __('entries.rev.ask_copy') }}</span></div>
        @if ($revAsk['oneLocale'] !== null)
            <div class="{{ $line }}"><x-bs-admin::icon name="ri-translate-2" :class="$lineGlyph" /><span>{{ $revAsk['oneLocale'] }}</span></div>
        @endif
        @if ($revAsk['shared'])
            <x-bs-admin::alert role="status" color="warning" icon="ri-error-warning" class="!py-2 text-small">{{ __('entries.rev.ask_shared') }}</x-bs-admin::alert>
        @endif
        @if ($revAsk['dirty'])
            <x-bs-admin::alert role="status" color="warning" icon="ri-edit" class="!py-2 text-small">{{ __('entries.rev.ask_dirty') }}</x-bs-admin::alert>
        @endif
        @if ($revAsk['missing'] !== null)
            <div class="{{ $line }}"><x-bs-admin::icon name="ri-image" :class="$lineGlyph" /><span>{{ $revAsk['missing'] }}</span></div>
        @endif
        @if ($revRefusal !== null)
            <x-bs-admin::alert color="danger" icon="ri-close-circle" class="!py-2 text-small" data-rev-refusal>{{ $revRefusal }}</x-bs-admin::alert>
        @endif
    @endif
    <x-slot:footer>
        @if ($revRefusal !== null)
            {{-- the refused restore's button is gone: focus goes to «Đóng», as the board does --}}
            <x-bs-admin::button color="primary" wire:key="rev-ask-close" x-on:click="hide()" x-init="$nextTick(() => $el.focus())">{{ __('entries.rev.close') }}</x-bs-admin::button>
        @else
            <x-bs-admin::button variant="outline" color="neutral" wire:key="rev-ask-cancel" x-on:click="hide()" wire:loading.attr="disabled" wire:target="restoreRevision">{{ __('entries.rev.cancel') }}</x-bs-admin::button>
            {{-- Unsaved changes in the form are taken first and kept on this device once the version is
                 written back (the «Khôi phục» band brings them back), as «Lấy bản mới» keeps them; a
                 refused restore replaced nothing, so nothing is kept. --}}
            <x-bs-admin::button color="primary" data-rev-do wire:loading.attr="aria-disabled" wire:target="restoreRevision"
                                x-on:click="if ($el.getAttribute('aria-disabled') === 'true') { return; }
                                    const k = window.bsKept, key = {{ \Illuminate\Support\Js::from($keptKey) }};
                                    const vals = {{ ($revAsk['dirty'] ?? false) ? 'true' : 'false' }} && k && key ? k.valuesOf($wire) : null;
                                    $wire.restoreRevision().then((done) => {
                                        if (done !== true || ! vals) { return; }
                                        const rec = k.write(key, vals);
                                        if (rec) { window.dispatchEvent(new CustomEvent('bs-entry-kept', { detail: { key: key, rec: rec } })); }
                                    }, () => {});">
                <x-bs-admin::icon name="ri-history" :class="$rowGlyph" wire:loading.remove wire:target="restoreRevision" />
                <x-bs-admin::icon name="ri-loader-4" class="size-[1.15em] shrink-0 animate-spin" wire:loading wire:target="restoreRevision" />
                <span wire:loading.remove wire:target="restoreRevision">{{ __('entries.rev.restore_this') }}</span>
                <span wire:loading wire:target="restoreRevision">{{ __('entries.rev.restoring') }}</span>
            </x-bs-admin::button>
        @endif
    </x-slot:footer>
</x-bs-admin::modal>

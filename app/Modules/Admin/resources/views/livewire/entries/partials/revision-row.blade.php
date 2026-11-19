{{-- One history row (board 07 .rv-row): why it was taken (its explanation as the chip's tip), «Mới
     nhất» on the newest, when and who; «Xem», and «Khôi phục» on every row but the newest. In the
     card's region the buttons sit under the words ($stacked). --}}
@php
    $kind = \App\Modules\Admin\Livewire\Entries\Editor::revisionKind($row['kind']);
    $chip = 'inline-flex items-center gap-1 rounded-full px-2 py-px text-caption font-semibold whitespace-nowrap';
    $rowGlyph = 'size-[1.15em] shrink-0';
@endphp
<div wire:key="rev-{{ $row['ulid'] }}" data-rev-row
     class="flex gap-2 border-b border-border py-2 last:border-b-0 {{ $stacked ? 'flex-col' : 'flex-wrap items-center justify-between' }}">
    <div class="flex min-w-0 flex-col gap-[3px]">
        <span class="flex flex-wrap items-center gap-1.5">
            <span class="{{ $chip }} bg-surface-hover text-text" title="{{ $kind['tip'] }}">
                <x-bs-admin::icon :name="$kind['icon']" :class="$rowGlyph" />{{ $kind['label'] }}<span class="sr-only"> — {{ $kind['tip'] }}</span>
            </span>
            @if ($latest)
                <span class="{{ $chip }} bg-[color-mix(in_srgb,var(--color-success-text)_10%,var(--color-surface))] text-success-text">
                    <x-bs-admin::icon name="ri-checkbox-circle" :class="$rowGlyph" />{{ __('entries.rev.latest') }}
                </span>
            @endif
        </span>
        <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ $row['at'] }} · {{ $row['who'] }}</span>
    </div>
    <div class="flex shrink-0 gap-2">
        <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="viewRevision('{{ $row['ulid'] }}')" aria-label="{{ __('entries.rev.view_aria', ['at' => $row['at']]) }}">
            <x-bs-admin::icon name="ri-eye" :class="$rowGlyph" />{{ __('entries.rev.view') }}
        </x-bs-admin::button>
        @if (! $latest)
            <x-bs-admin::button variant="outline" color="neutral" size="sm" wire:click="askRestore('{{ $row['ulid'] }}')" aria-label="{{ __('entries.rev.restore_aria', ['at' => $row['at']]) }}">
                <x-bs-admin::icon name="ri-history" :class="$rowGlyph" />{{ __('entries.rev.restore') }}
            </x-bs-admin::button>
        @endif
    </div>
</div>

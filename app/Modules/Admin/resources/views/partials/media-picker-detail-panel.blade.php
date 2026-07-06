{{-- Detail panel (handoff screen 8): preview + read-only attributes + editable per-locale alt.
     A 278px right rail ≥900px; below that it overlays the content (absolute, shadowed) so the
     grid isn't crushed. Receives $mp = mediaPickerState(); $mp['detail'] is the Media (with folder). --}}
@php($d = $mp['detail'])
<div class="flex w-[278px] shrink-0 flex-col overflow-auto border-l border-border bg-surface-muted
            max-[900px]:absolute max-[900px]:inset-y-0 max-[900px]:right-0 max-[900px]:z-10 max-[900px]:w-[300px] max-[900px]:max-w-[85%] max-[900px]:shadow-[-8px_0_24px_-12px_rgba(56,65,74,0.3)]">
    <div class="flex items-center justify-between border-b border-border px-4 py-[13px]">
        <span class="text-[13px] font-semibold text-heading">{{ __('media.picker_detail_title') }}</span>
        <button type="button" wire:click="closePickerDetail" title="{{ __('media.picker_cancel') }}"
                class="flex size-[26px] items-center justify-center rounded-md text-body-faint hover:text-body-text">
            <x-admin::icon name="ri-close" class="size-4" />
        </button>
    </div>

    <div class="flex flex-col gap-3.5 p-4">
        <div class="relative aspect-[4/3] overflow-hidden rounded-[10px] border border-border bg-surface-hover">
            @if (str_starts_with($d->mime, 'image/'))
                <img src="{{ $mp['urls']->url($d->storage_key, 640) }}" alt="{{ $d->original_filename }}"
                     class="absolute inset-0 size-full object-cover" onerror="this.style.display='none'">
            @else
                @php($glyph = str_starts_with($d->mime, 'video/') ? ['ri-film', '#299cdb']
                    : (str_starts_with($d->mime, 'audio/') ? ['ri-music-2', '#8b5cf6']
                        : ($d->mime === 'application/pdf' ? ['ri-file-pdf-2', '#f06548'] : ['ri-file-excel-2', '#0ab39c'])))
                <span class="absolute inset-0 flex items-center justify-center" style="background: {{ $glyph[1] }}14;">
                    <x-admin::icon :name="$glyph[0]" class="size-[46px]" style="color: {{ $glyph[1] }}" />
                </span>
            @endif
        </div>

        <div class="break-words text-[13.5px] font-semibold text-heading">{{ $d->original_filename }}</div>

        <div class="flex flex-col gap-[9px]">
            <div class="flex justify-between text-[12.5px]">
                <span class="text-body-faint">{{ __('media.picker_detail_dims') }}</span>
                <span class="text-body-text">{{ $d->width !== null ? $d->width.' × '.$d->height.' px' : '—' }}</span>
            </div>
            <div class="flex justify-between text-[12.5px]">
                <span class="text-body-faint">{{ __('media.picker_detail_size') }}</span>
                <span class="text-body-text">{{ number_format($d->size / 1048576, 1) }} MB</span>
            </div>
            <div class="flex justify-between text-[12.5px]">
                <span class="text-body-faint">{{ __('media.picker_detail_format') }}</span>
                <span class="uppercase text-body-text">{{ $d->extLabel() }}</span>
            </div>
            <div class="flex justify-between gap-3 text-[12.5px]">
                <span class="shrink-0 text-body-faint">{{ __('media.picker_detail_folder') }}</span>
                <span class="truncate text-body-text">{{ $d->folder?->name ?? __('media.picker_all_images') }}</span>
            </div>
        </div>

        <div>
            <label for="picker-detail-alt" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[.03em] text-body-muted">{{ __('media.picker_detail_alt_label') }}</label>
            @can('media.upload')
                <textarea id="picker-detail-alt" wire:model="pickerDetailAlt" rows="3"
                          placeholder="{{ __('media.picker_detail_alt_placeholder') }}"
                          class="block min-h-[64px] w-full resize-y rounded-lg border border-border-strong bg-surface px-[11px] py-[9px] text-[12.5px] text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none"></textarea>
                @if (session('media-status'))
                    <p class="mt-1.5 flex items-center gap-1 text-[11.5px] text-admin-success">
                        <x-admin::icon name="ri-checkbox-circle" class="size-3.5" />{{ session('media-status') }}
                    </p>
                @endif
                <button type="button" wire:click="savePickerDetailAlt"
                        class="mt-2.5 flex w-full items-center justify-center gap-1.5 rounded-lg border border-border-strong bg-surface py-[9px] text-[12.5px] font-medium text-body-text hover:border-admin-primary hover:text-admin-primary">
                    <x-admin::icon name="ri-save" class="size-3.5" />{{ __('media.picker_detail_save') }}
                </button>
            @else
                <p class="rounded-lg bg-surface-hover px-3 py-2 text-[12.5px] text-body-muted">{{ $pickerDetailAlt !== '' ? $pickerDetailAlt : '—' }}</p>
            @endcan
        </div>
    </div>
</div>

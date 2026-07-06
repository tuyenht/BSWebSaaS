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
                {{-- 320w is plenty for a 278px panel — no need to pull the 640 variant. --}}
                <img src="{{ $mp['urls']->url($d->storage_key, 320) }}" alt="{{ $d->original_filename }}"
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
            @if (($mp['detailUploader'] ?? null) !== null)
                <div class="flex justify-between gap-3 text-[12.5px]">
                    <span class="shrink-0 text-body-faint">{{ __('media.picker_detail_uploader') }}</span>
                    <span class="truncate text-body-text">{{ $mp['detailUploader'] }}</span>
                </div>
            @endif
        </div>

        {{-- "Used in": every post/product using this image, each a link opening in a new tab. One
             image can be used by many objects (the reason it's a list). Empty = safe to delete. --}}
        <div>
            <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-[.03em] text-body-muted">{{ __('media.picker_detail_used_in') }}</div>
            @php($usages = $mp['detailUsages'] ?? [])
            @if ($usages === [])
                <p class="rounded-lg bg-surface-hover px-3 py-2 text-[12.5px] text-body-faint">{{ __('media.picker_detail_unused') }}</p>
            @else
                <ul class="flex flex-col gap-1">
                    @foreach ($usages as $u)
                        <li>
                            <a href="{{ $u['url'] }}" target="_blank" rel="noopener"
                               class="flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-[12.5px] text-body-text hover:bg-surface-hover">
                                <x-admin::icon name="{{ $u['kind'] === 'product' ? 'ri-inbox-archive' : 'file-text' }}" class="size-3.5 shrink-0 text-body-faint" />
                                <span class="min-w-0 flex-1 truncate">{{ $u['label'] }}</span>
                                <span class="shrink-0 rounded bg-admin-primary/8 px-1.5 py-0.5 text-[10px] font-semibold text-admin-primary">{{ __('media.picker_kind_'.$u['kind']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div>
            <label for="picker-detail-alt" class="mb-1.5 block text-[11px] font-semibold uppercase tracking-[.03em] text-body-muted">{{ __('media.picker_detail_alt_label') }}</label>
            @can('media.upload')
                <textarea id="picker-detail-alt" wire:model="pickerDetailAlt" rows="3"
                          placeholder="{{ __('media.picker_detail_alt_placeholder') }}"
                          class="block min-h-[64px] w-full resize-y rounded-lg border border-border-strong bg-surface px-[11px] py-[9px] text-[12.5px] text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none"></textarea>
                {{-- Compact action row: a saved-tick on the left, a small filled button on the right
                     (a full-width outline button read heavy for a metadata save). --}}
                <div class="mt-2 flex items-center justify-between gap-2">
                    <span class="flex items-center gap-1 text-[11.5px] text-admin-success" @if (! session('media-status')) hidden @endif>
                        <x-admin::icon name="ri-checkbox-circle" class="size-3.5" />{{ __('media.picker_detail_saved') }}
                    </span>
                    <button type="button" wire:click="savePickerDetailAlt"
                            class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-admin-primary px-3.5 py-2 text-[12.5px] font-semibold text-white transition-colors hover:bg-admin-primary/90">
                        <x-admin::icon name="ri-save" class="size-3.5" />{{ __('media.picker_detail_save') }}
                    </button>
                </div>
            @else
                <p class="rounded-lg bg-surface-hover px-3 py-2 text-[12.5px] text-body-muted">{{ $pickerDetailAlt !== '' ? $pickerDetailAlt : '—' }}</p>
            @endcan
        </div>
    </div>
</div>

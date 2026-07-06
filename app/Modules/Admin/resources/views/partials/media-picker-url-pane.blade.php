{{-- "Ảnh từ URL" source screen (handoff §1b). The preview shown here IS the pick — the modal
     footer's "Chèn ảnh" inserts it (import a copy via register, or hotlink in editor mode).
     Preview <img> loads from the ORIGINAL url (the server has already fetched + sniffed the
     bytes through the SSRF gate; meta below is the server-verified truth, not the browser's). --}}
<div class="flex shrink-0 items-center gap-3 border-b border-border px-[18px] py-[11px]">
    <button type="button" wire:click="backToPickerSources"
            class="inline-flex items-center gap-1.5 rounded-lg border border-border-strong bg-surface px-[11px] py-1.5 text-[12.5px] text-body-text">
        <x-admin::icon name="ri-arrow-left" class="size-3.5" />{{ __('media.picker_back_sources') }}
    </button>
    <span class="flex size-[30px] items-center justify-center rounded-lg" style="background: #6559cc1a; color: #6559cc;">
        <x-admin::icon name="ri-link" class="size-4" />
    </span>
    <span class="text-sm font-semibold text-heading">{{ __('media.picker_url_source') }}</span>
</div>

<div class="mx-auto flex w-full max-w-[560px] flex-col gap-3.5 p-[22px]">
    <div class="flex gap-2">
        <div class="relative min-w-0 flex-1">
            <x-admin::icon name="ri-link" class="absolute left-3 top-1/2 size-[15px] -translate-y-1/2 text-body-faint" />
            <input type="url" wire:model="pickerUrlInput" wire:keydown.enter="previewPickerUrl"
                   placeholder="{{ __('media.picker_url_placeholder') }}"
                   class="block w-full rounded-lg border border-border-strong bg-surface py-2.5 pl-[35px] pr-3 text-[13px] text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none">
        </div>
        <button type="button" wire:click="previewPickerUrl"
                class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg bg-admin-primary px-4 py-2.5 text-[13px] font-semibold text-white">
            <x-admin::icon name="ri-refresh" class="size-3.5" />{{ __('media.picker_url_load_preview') }}
        </button>
    </div>
    @error('pickerUrlInput')
        <div class="flex items-center gap-1.5 text-xs text-admin-danger">
            <x-admin::icon name="ri-error-warning" class="size-3.5 shrink-0" />{{ $message }}
        </div>
    @enderror

    @if ($pickerUrlPreview !== null)
        <div class="overflow-hidden rounded-[13px] border border-border bg-surface">
            <div class="relative aspect-video bg-surface-hover">
                <img src="{{ $pickerUrlPreview['url'] }}" alt="{{ $pickerUrlPreview['filename'] }}"
                     class="absolute inset-0 size-full object-cover"
                     onerror="this.style.display='none'">
                <span class="absolute right-2.5 top-2.5 inline-flex items-center gap-1.5 rounded-[20px] bg-white/90 px-[9px] py-1 text-[11px] font-semibold text-admin-success">
                    <x-admin::icon name="ri-checkbox-circle" class="size-3.5" />{{ __('media.picker_url_selected') }}
                </span>
            </div>
            <div class="flex flex-col gap-2.5 px-[15px] py-[13px]">
                <div class="flex items-center justify-between gap-2.5">
                    <span class="truncate text-[13px] font-semibold text-heading">{{ $pickerUrlPreview['filename'] }}</span>
                    <span class="shrink-0 text-[11.5px] text-body-faint">
                        {{ $pickerUrlPreview['width'] !== null ? $pickerUrlPreview['width'].' × '.$pickerUrlPreview['height'].' · ' : '' }}~{{ number_format($pickerUrlPreview['size'] / 1048576, 1) }} MB
                    </span>
                </div>
                @if ($imagePickerMode === 'editor')
                    @can('media.upload')
                        <button type="button" wire:click="$toggle('pickerUrlSaveCopy')" class="flex items-start gap-[9px] text-left">
                            <span class="mt-px flex size-[19px] shrink-0 items-center justify-center rounded-[5px] border-[1.5px] {{ $pickerUrlSaveCopy ? 'border-admin-primary bg-admin-primary text-white' : 'border-placeholder bg-surface text-transparent' }}">
                                <x-admin::icon name="ri-check" class="size-3" />
                            </span>
                            <span class="text-[12.5px] text-body-text">{{ __('media.picker_url_save_copy') }}
                                <span class="text-body-faint">({{ __('media.picker_url_save_copy_note') }})</span>
                            </span>
                        </button>
                    @endcan
                @else
                    {{-- Gallery rows must reference a library master — the copy is not optional here. --}}
                    <div class="flex items-center gap-[9px] text-[12.5px] text-body-faint">
                        <x-admin::icon name="ri-checkbox-circle" class="size-4 shrink-0 text-admin-success" />{{ __('media.picker_url_save_copy') }}
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="flex flex-col items-center gap-2 rounded-[13px] border-[1.5px] border-dashed border-[#dfe3ea] px-5 py-7 text-center">
            <x-admin::icon name="ri-image-2" class="size-[30px] text-placeholder" />
            <div class="max-w-[340px] text-[12.5px] text-body-muted">{!! __('media.picker_url_hint') !!}</div>
        </div>
    @endif
</div>

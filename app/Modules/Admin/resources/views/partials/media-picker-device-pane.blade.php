{{-- "Thiết bị của tôi" upload pane (handoff screen 3). The island (wire:ignore + x-data) drives
     the shared DAM pipeline (presign → R2 → register) with a custom dropzone + progress rows —
     NOT Uppy's Dashboard. On a finished batch it dispatches `media-uploaded`; the trait's
     onPickerMediaUploaded listener lands on the library tab so the new masters are pickable. --}}
@php($ext = array_keys(\App\Modules\Media\Actions\UploadMedia::MIME_EXT))
<div class="flex shrink-0 items-center gap-3 border-b border-border px-[18px] py-[11px]">
    <button type="button" wire:click="backToPickerSources"
            class="inline-flex items-center gap-1.5 rounded-lg border border-border-strong bg-surface px-[11px] py-1.5 text-[12.5px] text-body-text">
        <x-admin::icon name="ri-arrow-left" class="size-3.5" />{{ __('media.picker_back_sources') }}
    </button>
    <span class="flex size-[30px] items-center justify-center rounded-lg" style="background: #4051891a; color: #405189;">
        <x-admin::icon name="ri-computer" class="size-4" />
    </span>
    <span class="text-sm font-semibold text-heading">{{ __('media.picker_source_device') }}</span>
</div>

{{-- wire:ignore: the island builds progress rows inside [data-progress]; Livewire must not morph them. --}}
<div class="p-6" wire:ignore
     x-data="pickerUploader"
     data-picker-uploader
     data-disk="{{ config('media.disk') }}"
     data-presign-url="{{ route('admin.media.presign') }}"
     data-register-url="{{ route('admin.media.register') }}"
     data-upload-url="{{ route('admin.media.upload') }}"
     data-max-bytes="{{ \App\Modules\Media\Actions\UploadMedia::MAX_KB * 1024 }}"
     data-allowed="{{ implode(',', $ext) }}"
     data-done-label="{{ __('media.picker_device_done') }}"
     data-error-label="{{ __('media.picker_device_error') }}">

    <div data-drop
         class="flex cursor-pointer flex-col items-center gap-3.5 rounded-[14px] border-2 border-dashed border-placeholder bg-surface-muted px-6 py-10 text-center transition-colors hover:border-admin-primary">
        <div class="flex size-16 items-center justify-center rounded-full bg-admin-primary/8">
            <x-admin::icon name="ri-upload-cloud-2" class="size-[30px] text-admin-primary" />
        </div>
        <div>
            <div class="text-base font-semibold text-heading">{{ __('media.picker_drop_title') }}</div>
            <div class="mt-0.5 text-[13px] text-body-muted">{{ __('media.picker_device_or') }}</div>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-lg bg-admin-primary px-[18px] py-[9px] text-[13.5px] font-semibold text-white">
            <x-admin::icon name="ri-folder-open" class="size-[15px]" />{{ __('media.picker_device_browse') }}
        </span>
        <div class="text-[11.5px] text-body-faint">{{ __('media.picker_device_hint', ['size' => \App\Modules\Media\Actions\UploadMedia::MAX_KB / 1024]) }}</div>
    </div>

    <input type="file" data-input multiple accept="image/*,application/pdf" class="hidden">

    <div class="mt-5 flex flex-col gap-[9px]">
        <div data-progress-label data-template="{{ __('media.picker_device_uploading', ['n' => ':n']) }}"
             style="display:none" class="text-[11px] font-semibold uppercase tracking-[.05em] text-body-faint"></div>
        <div data-progress class="flex flex-col gap-[9px]"></div>
    </div>
</div>

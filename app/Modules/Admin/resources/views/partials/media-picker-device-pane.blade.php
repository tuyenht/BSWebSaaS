{{-- "Thiết bị của tôi" upload pane (handoff screen 3, upload list from board 13). The island
     (wire:ignore + x-data) drives the shared DAM pipeline (presign → R2 → register) with a custom
     dropzone + the upload list (a row per file, its reason in plain words, «Thử lại») —
     NOT Uppy's Dashboard. On a finished batch it dispatches `media-uploaded`; the trait's
     onPickerMediaUploaded listener lands on the library tab so the new masters are pickable. --}}
@php
    $ext = array_keys(\App\Modules\Media\Actions\UploadMedia::MIME_EXT);
    $max = \App\Modules\Shared\Support\Bytes::megabytes(\App\Modules\Media\Actions\UploadMedia::MAX_KB * 1024, 0);
    // the sentence for each reason a file is refused (board 13); one with no sentence here keeps the server's
    $upMsgs = [];
    foreach (['unsupported_type', 'unsupported_svg', 'too_large', 'image_too_large', 'file_invalid', 'upload_session_invalid', 'upload_missing', 'storage_failed', 'unexpected'] as $code) {
        $upMsgs[$code] = __('media.up_err_'.$code, ['max' => $max]);
    }
    $upLabels = ['done' => __('media.picker_device_done'), 'failed' => __('media.up_failed'), 'retry' => __('media.up_retry'),
        'stop' => __('media.up_stop', ['name' => ':name']), 'drop' => __('media.up_drop', ['name' => ':name']),
        'meta' => __('media.up_meta', ['size' => ':size', 'w' => ':w', 'h' => ':h']), 'metaNoDim' => __('media.up_meta_nodim', ['size' => ':size']),
        'headRun' => __('media.up_head_run', ['n' => ':n']), 'headDone' => __('media.up_head_done', ['n' => ':n']), 'headErr' => __('media.up_head_err', ['n' => ':n'])];
@endphp
<div class="flex shrink-0 items-center gap-3 border-b border-border px-[18px] py-[11px]">
    <button type="button" wire:click="backToPickerSources"
            class="inline-flex items-center gap-1.5 rounded-lg border border-border-strong bg-surface px-[11px] py-1.5 text-[12.5px] text-body-text">
        <x-bs-admin::icon name="ri-arrow-left" class="size-3.5" />{{ __('media.picker_back_sources') }}
    </button>
    <span class="flex size-[30px] items-center justify-center rounded-lg" style="background: color-mix(in oklab, var(--color-admin-primary) 10%, transparent); color: var(--color-admin-primary);">
        <x-bs-admin::icon name="ri-computer" class="size-4" />
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
     data-msgs="{{ json_encode($upMsgs, JSON_UNESCAPED_UNICODE) }}"
     data-labels="{{ json_encode($upLabels, JSON_UNESCAPED_UNICODE) }}">

    <div data-drop
         class="flex cursor-pointer flex-col items-center gap-3.5 rounded-[14px] border-2 border-dashed border-placeholder bg-surface-muted px-6 py-10 text-center transition-colors hover:border-accent">
        <div class="flex size-16 items-center justify-center rounded-full bg-admin-primary/8">
            <x-bs-admin::icon name="ri-upload-cloud-2" class="size-[30px] text-accent" />
        </div>
        <div>
            <div class="text-base font-semibold text-heading">{{ __('media.picker_drop_title') }}</div>
            <div class="mt-0.5 text-[13px] text-body-muted">{{ __('media.picker_device_or') }}</div>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-lg bg-admin-primary px-[18px] py-[9px] text-[13.5px] font-semibold text-white">
            <x-bs-admin::icon name="ri-folder-open" class="size-[15px]" />{{ __('media.picker_device_browse') }}
        </span>
        <div class="text-[11.5px] text-body-muted">{{ __('media.picker_device_hint', ['size' => \App\Modules\Media\Actions\UploadMedia::MAX_KB / 1024]) }}</div>
    </div>

    <input type="file" data-input multiple accept="image/*,application/pdf" class="hidden">

    {{-- the upload list (board 13): drawn by media-upload-rows.js; the icons it needs, ready to clone --}}
    @foreach (['image' => 'ri-image-2', 'pdf' => 'ri-file-pdf-2', 'err' => 'ri-error-warning', 'running' => 'ri-loader-4', 'done' => 'ri-checkbox-circle', 'failed' => 'ri-close-circle', 'retry' => 'ri-refresh', 'remove' => 'ri-close'] as $key => $icon)
        <template data-icon="{{ $key }}"><x-bs-admin::icon :name="$icon" class="size-[1em] shrink-0" /></template>
    @endforeach
    {{-- the summary line is always in the page (a live region announces only once it is there); empty, the block takes no room --}}
    <div class="mp-upwrap">
        <div data-up-head class="flex flex-wrap items-center gap-2">
            <span data-up-summary role="status" aria-live="polite" class="min-w-0 flex-1 text-small font-semibold text-text-strong"></span>
            <button type="button" data-up-clear hidden class="mp-upx">{{ __('media.up_clear_done') }}</button>
        </div>
        <div data-progress class="flex flex-col gap-2"></div>
    </div>
</div>

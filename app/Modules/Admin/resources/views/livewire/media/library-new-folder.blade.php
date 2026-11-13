{{-- The "Thư mục mới" form of the media library (folder column from 900px, under the folder select
     below it). $fid keeps the two copies' field ids apart. --}}
<div class="flex flex-col gap-2 px-1 py-1">
    <label for="{{ $fid }}" class="text-small font-medium text-text">{{ __('media.lib.folder_name') }}</label>
    <input id="{{ $fid }}" type="text" maxlength="{{ \App\Modules\Admin\Livewire\Media\Library::FOLDER_NAME_MAX }}" placeholder="{{ __('media.lib.folder_name_hint') }}"
           wire:model="newFolderName" wire:keydown.enter="createFolder" wire:keydown.escape="cancelNewFolder"
           aria-invalid="{{ $errors->has('newFolderName') ? 'true' : 'false' }}" @if ($errors->has('newFolderName')) aria-describedby="{{ $fid }}-err" @endif
           class="field w-full rounded-md border border-border-strong bg-surface px-3 text-text aria-[invalid=true]:border-danger-text">
    @error('newFolderName')
        <p id="{{ $fid }}-err" class="m-0 flex items-start gap-1.5 text-small text-danger-text" role="alert"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ $message }}</span></p>
    @enderror
    <div class="flex flex-wrap justify-end gap-2">
        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="cancelNewFolder">{{ __('media.lib.cancel') }}</x-bs-admin::button>
        <x-bs-admin::button size="sm" color="primary" wire:click="createFolder">{{ __('media.lib.create_folder') }}</x-bs-admin::button>
    </div>
</div>

{{-- The "Đổi tên" form of a media library folder: in place of the folder's row in the folder column
     from 900px, under the folder select below it. $fid keeps the two copies' field ids apart; $label
     is the field's name there. The folder's slug never changes with its name. --}}
<div class="flex flex-col gap-2 px-1 py-1">
    <label for="{{ $fid }}" class="text-small font-medium text-text">{{ $label }}</label>
    <input id="{{ $fid }}" type="text" maxlength="{{ \App\Modules\Admin\Livewire\Media\Library::FOLDER_NAME_MAX }}"
           wire:model="renameName" wire:keydown.enter="saveRename" wire:keydown.escape="cancelRename"
           x-init="$nextTick(() => { if ($el.offsetParent !== null) { $el.focus(); $el.select() } })"
           aria-invalid="{{ $errors->has('renameName') ? 'true' : 'false' }}" @if ($errors->has('renameName')) aria-describedby="{{ $fid }}-err" @endif
           class="field w-full rounded-md border border-border-strong bg-surface px-3 text-text aria-[invalid=true]:border-danger-text">
    @error('renameName')
        <p id="{{ $fid }}-err" class="m-0 flex items-start gap-1.5 text-small text-danger-text" role="alert"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ $message }}</span></p>
    @enderror
    <div class="flex flex-wrap justify-end gap-2">
        <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="cancelRename">{{ __('media.lib.cancel') }}</x-bs-admin::button>
        <x-bs-admin::button size="sm" color="primary" wire:click="saveRename">{{ __('media.lib.rename_save') }}</x-bs-admin::button>
    </div>
    <p class="m-0 text-small leading-normal text-text-muted">{{ __('media.lib.rename_note') }}</p>
</div>

{{-- Shared media-library picker modal (WithMediaLibraryPicker trait). Expects from render():
     $pickerMedia (trait pickerMedia()), $urls (MediaUrlResolver) and optionally $pickedIds
     (list<int> already-attached ids to mark ✓/disable — gallery mode). Stays open in gallery
     mode for multi-pick; editor mode closes itself after inserting. --}}
@if ($showImagePicker)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="{{ __('catalog.picker_title') }}">
        <div class="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-md border border-border bg-surface p-4 shadow-card">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h5 class="text-[1rem] font-semibold text-body-text">{{ __('catalog.picker_title') }}</h5>
                <x-admin::button size="sm" variant="soft" color="primary" wire:click="closeImagePicker">{{ __('admin.dismiss') }}</x-admin::button>
            </div>
            @if ($imagePickerMode === 'editor')
                {{-- T5G-parity: paste an external URL OR pick from the library — one dialog.
                     Alt text / size are edited afterwards via double-click (Jodit's own
                     image-properties dialog). --}}
                <div class="mb-4 flex items-end gap-2 border-b border-border pb-4">
                    <div class="grow">
                        <label for="external-image-url" class="mb-1.5 block text-sm font-medium text-body-text">{{ __('catalog.picker_external_label') }}</label>
                        <input type="url" id="external-image-url" wire:model="externalImageUrl" placeholder="https://…"
                               wire:keydown.enter="insertExternalImage"
                               class="block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                        @error('externalImageUrl') <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
                    </div>
                    <x-admin::button size="sm" variant="soft" color="primary" wire:click="insertExternalImage">{{ __('catalog.picker_external_insert') }}</x-admin::button>
                </div>
            @endif
            @if ($pickerMedia->isEmpty())
                <p class="text-sm text-body-faint">{{ __('catalog.picker_empty') }}</p>
            @else
                <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                    @foreach ($pickerMedia as $m)
                        @php($picked = in_array($m->id, $pickedIds ?? [], true))
                        <button type="button" wire:key="pick-{{ $m->id }}" wire:click="pickImage({{ $m->id }})"
                                @disabled($picked)
                                class="relative overflow-hidden rounded border transition-colors {{ $picked ? 'border-admin-primary opacity-60' : 'border-border hover:border-admin-primary' }}"
                                title="{{ $m->original_filename }}">
                            <img src="{{ $urls->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy" class="aspect-square w-full object-cover">
                            @if ($picked)
                                <span class="absolute right-1 top-1 rounded bg-admin-primary px-1.5 py-0.5 text-xs text-white">✓</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif

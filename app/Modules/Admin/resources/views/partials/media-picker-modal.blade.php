{{-- Media picker v2 (docs/design_handoff_media_picker — screens 0/1/8). Included by every host
     component that `use`s WithMediaLibraryPicker; all state/actions live on that trait, so the
     host's own render() needs nothing. Contract kept from v1: openImagePicker(mode) opens it,
     insert dispatches `admin-editor-insert-image` (editor) / addGalleryImage() (gallery).
     $pickedIds (optional, from the host view) still marks already-attached gallery items. --}}
@php($mp = $this->mediaPickerState())
@if ($showImagePicker && ($mp['open'] ?? false))
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
         role="dialog" aria-modal="true" aria-label="{{ __('media.picker_title') }}"
         x-data x-on:keydown.escape.window="$wire.closeImagePicker()"
         wire:click.self="closeImagePicker">
        <div class="flex h-[660px] max-h-[80vh] w-full max-w-[1020px] flex-col overflow-hidden rounded-[14px] border border-border-strong bg-surface text-sm text-body-text shadow-[0_24px_60px_-22px_rgba(56,65,74,0.4)]">

            {{-- Header --}}
            <div class="flex shrink-0 items-center justify-between gap-4 px-5 pb-[9px] pt-[15px]">
                <div class="flex items-center gap-3">
                    <div class="flex size-[34px] items-center justify-center rounded-[9px] bg-admin-primary/8">
                        <x-admin::icon name="ri-image-add" class="size-[19px] text-admin-primary" />
                    </div>
                    <div class="flex flex-col gap-px">
                        <span class="text-[15.5px] font-semibold leading-tight text-heading">{{ __('media.picker_title') }}</span>
                        <span class="text-xs text-body-faint">{{ __('media.picker_subtitle') }}</span>
                    </div>
                </div>
                <button type="button" wire:click="closeImagePicker" title="{{ __('admin.dismiss') }}"
                        class="flex size-8 items-center justify-center rounded-lg bg-surface-hover text-body-muted transition-colors hover:text-body-text">
                    <x-admin::icon name="ri-close" class="size-[17px]" />
                </button>
            </div>

            {{-- Tab bar (underline style) --}}
            <div class="flex shrink-0 gap-6 overflow-x-auto border-b border-border-strong bg-surface px-5">
                <button type="button" wire:click="setPickerTab('upload')"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1 py-3 text-[13.5px] {{ $pickerTab === 'upload' ? 'font-semibold text-admin-primary shadow-[inset_0_-2.5px_0_var(--color-admin-primary)]' : 'font-medium text-body-muted' }}">
                    <x-admin::icon name="ri-upload-2" class="size-4 {{ $pickerTab === 'upload' ? 'text-admin-primary' : 'text-body-faint' }}" />
                    {{ __('media.picker_tab_upload') }}
                </button>
                <button type="button" wire:click="setPickerTab('library')"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1 py-3 text-[13.5px] {{ $pickerTab === 'library' ? 'font-semibold text-admin-primary shadow-[inset_0_-2.5px_0_var(--color-admin-primary)]' : 'font-medium text-body-muted' }}">
                    <x-admin::icon name="ri-image-2" class="size-4 {{ $pickerTab === 'library' ? 'text-admin-primary' : 'text-body-faint' }}" />
                    {{ __('media.picker_tab_library') }}
                    <span class="rounded-[10px] px-[7px] py-px text-[10.5px] font-semibold {{ $pickerTab === 'library' ? 'bg-admin-primary/8 text-admin-primary' : 'bg-surface-hover text-body-muted' }}">{{ $mp['total'] }}</span>
                </button>
            </div>

            {{-- Body --}}
            <div class="flex min-h-0 min-w-0 flex-1">
                <div class="flex min-w-0 flex-1 flex-col overflow-auto bg-surface">
                    @if ($pickerTab === 'upload')
                        @include('admin::partials.media-picker-sources')
                    @else
                        @include('admin::partials.media-picker-library-pane', ['mp' => $mp])
                    @endif
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex shrink-0 items-center gap-3.5 border-t border-border bg-surface px-[18px] py-3"
                 x-data="{ urlOpen: false }">
                @if ($pickerSelected !== [])
                    <div class="flex min-w-0 flex-1 items-center gap-2.5">
                        <div class="flex pl-2">
                            @foreach ($mp['selectedPreview'] as $m)
                                <span class="-ml-2 size-8 overflow-hidden rounded-[7px] border-2 border-white shadow-sm" wire:key="selprev-{{ $m->id }}">
                                    <img src="{{ $mp['urls']->url($m->storage_key, 320) }}" alt="" class="size-full object-cover"
                                         onerror="this.style.display='none'">
                                </span>
                            @endforeach
                        </div>
                        <span class="text-[12.5px] text-body-muted">{{ __('media.picker_footer_selected', ['count' => count($pickerSelected)]) }}</span>
                    </div>
                @else
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span class="text-[12.5px] text-body-faint" x-show="!urlOpen">{{ __('media.picker_footer_hint') }}</span>
                        @if ($imagePickerMode === 'editor')
                            {{-- External-URL insert kept from editor v2 (owner feature) — compact, editor mode only. --}}
                            <button type="button" x-show="!urlOpen" x-on:click="urlOpen = true"
                                    class="text-[12.5px] font-medium text-admin-primary underline">{{ __('media.picker_external_toggle') }}</button>
                            <div class="flex min-w-0 flex-1 items-center gap-2" x-show="urlOpen" x-cloak>
                                <input type="url" wire:model="externalImageUrl" wire:keydown.enter="insertExternalImage"
                                       placeholder="https://…"
                                       class="block w-full min-w-0 rounded-lg border border-border-strong bg-surface px-3 py-1.5 text-[12.5px] text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none">
                                <button type="button" wire:click="insertExternalImage"
                                        class="shrink-0 rounded-lg border border-border-strong bg-surface px-3 py-1.5 text-xs font-medium text-body-text">{{ __('catalog.picker_external_insert') }}</button>
                            </div>
                        @endif
                    </div>
                @endif
                @error('externalImageUrl') <p class="text-xs text-admin-danger">{{ $message }}</p> @enderror
                <button type="button" wire:click="closeImagePicker"
                        class="shrink-0 rounded-lg border border-border-strong bg-surface px-4 py-[9px] text-[13px] font-medium text-body-text">{{ __('media.picker_cancel') }}</button>
                <button type="button" wire:click="insertPicked" @disabled($pickerSelected === [])
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border-0 px-[18px] py-[9px] text-[13px] font-semibold text-white {{ $pickerSelected === [] ? 'cursor-not-allowed bg-placeholder' : 'bg-admin-primary' }}">
                    <x-admin::icon name="ri-check" class="size-[15px]" />
                    {{ $imagePickerMode === 'gallery' && count($pickerSelected) > 1
                        ? __('media.picker_insert_count', ['count' => count($pickerSelected)])
                        : __('media.picker_insert') }}
                </button>
            </div>
        </div>
    </div>
@endif

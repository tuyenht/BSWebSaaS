{{-- /admin/media — full-page media management. Reuses the picker's panes (upload sources +
     library-pane + detail) in MANAGE mode: no modal shell, no "Chèn ảnh" footer. Clicking a tile
     opens its detail; the checkbox selects for bulk move/delete/trash. --}}
@php($mp = $this->mediaPickerState())
<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-heading">{{ __('media.library_title') }}</h1>
        <p class="mt-1 text-sm text-body-muted">{{ __('media.library_subtitle') }}</p>
    </div>

    <div class="flex h-[calc(100dvh-13rem)] min-h-[520px] flex-col overflow-hidden rounded-lg border border-border-strong bg-surface shadow-card">

        {{-- Tab bar (underline) --}}
        <div class="flex shrink-0 gap-6 overflow-x-auto border-b border-border-strong px-5">
            @can('media.upload')
                <button type="button" wire:click="setPickerTab('upload')"
                        class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1 py-3 text-[13.5px] {{ $pickerTab === 'upload' ? 'font-semibold text-admin-primary shadow-[inset_0_-2.5px_0_var(--color-admin-primary)]' : 'font-medium text-body-muted' }}">
                    <x-admin::icon name="ri-upload-2" class="size-4 {{ $pickerTab === 'upload' ? 'text-admin-primary' : 'text-body-muted' }}" />
                    {{ __('media.picker_tab_upload') }}
                </button>
            @endcan
            <button type="button" wire:click="setPickerTab('library')"
                    class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap px-1 py-3 text-[13.5px] {{ $pickerTab === 'library' ? 'font-semibold text-admin-primary shadow-[inset_0_-2.5px_0_var(--color-admin-primary)]' : 'font-medium text-body-muted' }}">
                <x-admin::icon name="ri-image-2" class="size-4 {{ $pickerTab === 'library' ? 'text-admin-primary' : 'text-body-muted' }}" />
                {{ __('media.picker_tab_library') }}
                <span class="rounded-[10px] px-[7px] py-px text-[10.5px] font-semibold {{ $pickerTab === 'library' ? 'bg-admin-primary/8 text-admin-primary' : 'bg-surface-hover text-body-muted' }}">{{ $mp['total'] }}</span>
            </button>
        </div>

        {{-- Body --}}
        <div class="relative flex min-h-0 min-w-0 flex-1">
            <div class="flex min-w-0 flex-1 flex-col overflow-auto bg-surface">
                @if ($pickerTab === 'upload')
                    @if ($pickerSource === 'url')
                        @include('admin::partials.media-picker-url-pane')
                    @elseif ($pickerSource === 'device')
                        @include('admin::partials.media-picker-device-pane')
                    @else
                        @include('admin::partials.media-picker-sources')
                    @endif
                @else
                    @include('admin::partials.media-picker-library-pane', ['mp' => $mp])
                @endif
            </div>
            @if ($pickerTab === 'library' && ($mp['detail'] ?? null))
                @include('admin::partials.media-picker-detail-panel', ['mp' => $mp])
            @endif
        </div>
    </div>
</div>

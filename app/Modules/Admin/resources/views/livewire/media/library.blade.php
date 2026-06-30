<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-800">{{ __('media.library_title') }}</h1>
        <p class="mt-1 text-sm text-gray-500">{{ __('media.library_subtitle') }}</p>
    </div>

    @if (session('media-status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">
            {{ session('media-status') }}
        </div>
    @endif

    @can('media.upload')
        <form wire:submit="save" class="mb-5 rounded-lg border border-gray-200 bg-white p-4 shadow-card">
            <label for="media-upload" class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('media.upload_label') }}</label>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <input type="file" id="media-upload" wire:model="upload"
                       accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
                       class="block w-full text-sm text-gray-600 file:me-3 file:rounded file:border-0 file:bg-admin-primary/10 file:px-3 file:py-2 file:text-sm file:font-medium file:text-admin-primary hover:file:bg-admin-primary/15 sm:max-w-md">
                <button type="submit"
                        class="inline-flex shrink-0 items-center justify-center gap-2 rounded bg-admin-primary px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-admin-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                        wire:loading.attr="disabled" wire:target="upload,save">
                    <span wire:loading.remove wire:target="save">{{ __('media.upload_button') }}</span>
                    <span wire:loading wire:target="save">{{ __('media.uploading') }}</span>
                </button>
            </div>
            <p class="mt-1.5 text-xs text-gray-400">{{ __('media.upload_hint') }}</p>
            <p wire:loading wire:target="upload" class="mt-1.5 text-xs text-gray-400">{{ __('media.uploading') }}</p>
            @error('upload')
                <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </form>
    @endcan

    @if ($media->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-sm text-gray-400">
            {{ __('media.library_empty') }}
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @foreach ($media as $item)
                <div wire:key="media-{{ $item->id }}"
                     class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-card">
                    <div class="flex aspect-square items-center justify-center bg-gray-50">
                        @if (str_starts_with($item->mime, 'image/'))
                            <img src="{{ $urls->url($item->storage_key, 640) }}"
                                 alt="{{ $item->alt['vi'] ?? $item->original_filename }}"
                                 @if ($item->width && $item->height) width="{{ $item->width }}" height="{{ $item->height }}" @endif
                                 loading="lazy" class="h-full w-full object-cover">
                        @else
                            <x-admin::icon name="doc" class="size-10 text-gray-300" />
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                        <span class="truncate text-xs text-gray-600" title="{{ $item->original_filename }}">{{ $item->original_filename ?? $item->slug }}</span>
                        <div class="flex shrink-0 items-center gap-2.5">
                            <a href="{{ $urls->url($item->storage_key) }}" target="_blank" rel="noopener"
                               class="text-xs font-medium text-admin-primary transition-colors hover:underline">{{ __('media.open') }}</a>
                            @can('media.delete')
                                <button type="button"
                                        wire:click="delete('{{ $item->ulid }}')"
                                        wire:confirm="{{ __('media.delete_confirm') }}"
                                        class="text-xs font-medium text-red-600 transition-colors hover:underline">
                                    {{ __('media.delete') }}
                                </button>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-5">
            {{ $media->links() }}
        </div>
    @endif
</div>

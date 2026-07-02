<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-heading">{{ __('media.library_title') }}</h1>
        <p class="mt-1 text-sm text-body-faint">{{ __('media.library_subtitle') }}</p>
    </div>

    @if (session('media-status'))
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">
            {{ session('media-status') }}
        </div>
    @endif

    @can('media.upload')
        <div class="mb-5 rounded-lg border border-border bg-surface p-4 shadow-card">
            <label class="mb-1.5 block text-sm font-medium text-body-text">{{ __('media.upload_label') }}</label>
            {{-- Uppy island: PUTs straight to R2 (presign → register), or proxies through Laravel on a
                 local disk. wire:ignore keeps Livewire's morph off the Uppy DOM across grid refreshes;
                 app.js (re)mounts it on every SPA navigation. --}}
            <div wire:ignore
                 data-media-uploader
                 data-disk="{{ config('media.disk') }}"
                 data-presign-url="{{ route('admin.media.presign') }}"
                 data-register-url="{{ route('admin.media.register') }}"
                 data-upload-url="{{ route('admin.media.upload') }}"
                 data-max-bytes="{{ \App\Modules\Media\Actions\UploadMedia::MAX_KB * 1024 }}"
                 data-allowed="{{ implode(',', array_keys(\App\Modules\Media\Actions\UploadMedia::MIME_EXT)) }}"></div>
            <p class="mt-2 text-xs text-placeholder">{{ __('media.upload_hint') }}</p>
        </div>
    @endcan

    @if ($media->isEmpty())
        <div class="rounded-lg border border-dashed border-border-strong bg-surface px-4 py-12 text-center text-sm text-placeholder">
            {{ __('media.library_empty') }}
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            @foreach ($media as $item)
                <div wire:key="media-{{ $item->id }}"
                     class="overflow-hidden rounded-lg border border-border bg-surface shadow-card">
                    <div class="flex aspect-square items-center justify-center bg-surface-muted">
                        @if (str_starts_with($item->mime, 'image/'))
                            <img src="{{ $urls->url($item->storage_key, 640) }}"
                                 alt="{{ $item->alt['vi'] ?? $item->original_filename }}"
                                 @if ($item->width && $item->height) width="{{ $item->width }}" height="{{ $item->height }}" @endif
                                 loading="lazy" class="h-full w-full object-cover">
                        @else
                            <x-admin::icon name="doc" class="size-10 text-ink-ghost" />
                        @endif
                    </div>
                    <div class="flex items-center justify-between gap-2 px-3 py-2">
                        <span class="truncate text-xs text-body-muted" title="{{ $item->original_filename }}">{{ $item->original_filename ?? $item->slug }}</span>
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

{{-- Video-embed dialog (WithMediaLibraryPicker trait): paste a youtube/vimeo URL (any common
     shape) + optional autoplay — the server builds the canonical responsive embed. --}}
@if ($showVideoDialog)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="{{ __('catalog.video_title') }}">
        <div class="w-full max-w-md rounded-md border border-border bg-surface p-4 shadow-card">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h5 class="text-[1rem] font-semibold text-body-text">{{ __('catalog.video_title') }}</h5>
                <x-admin::button size="sm" variant="soft" color="primary" wire:click="closeVideoDialog">{{ __('admin.dismiss') }}</x-admin::button>
            </div>
            <label for="video-embed-url" class="mb-1.5 block text-sm font-medium text-body-text">{{ __('catalog.video_url_label') }}</label>
            <input type="url" id="video-embed-url" wire:model="videoUrl" wire:keydown.enter="insertVideo"
                   placeholder="https://www.youtube.com/watch?v=… · https://vimeo.com/…"
                   class="block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-accent">
            @error('videoUrl') <p class="mt-1 text-xs text-admin-danger-text">{{ $message }}</p> @enderror
            <label class="mt-3 flex cursor-pointer items-center gap-2 text-sm text-body-text">
                <input type="checkbox" wire:model="videoAutoplay" class="accent-admin-primary">
                {{ __('catalog.video_autoplay') }}
            </label>
            <p class="mt-1 text-xs text-body-muted">{{ __('catalog.video_autoplay_hint') }}</p>
            <div class="mt-4 flex justify-end">
                <x-admin::button color="primary" wire:click="insertVideo" wire:loading.attr="disabled">{{ __('catalog.video_insert') }}</x-admin::button>
            </div>
        </div>
    </div>
@endif

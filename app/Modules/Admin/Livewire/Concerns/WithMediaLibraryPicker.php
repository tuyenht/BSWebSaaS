<?php

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Shared\Support\VideoEmbed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The shared media-library picker for admin Livewire components (rich editors, galleries).
 * Two pick modes: `editor` resolves the media to a CDN url and dispatches
 * `admin-editor-insert-image` back to the rich-editor island (which inserts it at the caret);
 * `gallery` hands the id to the component's own addGalleryImage() hook when it defines one.
 * Every path re-gates media.view (the livewire/update endpoint bypasses route middleware) and
 * only LIVE media of THIS site resolve (the model's global scope filters site + trashed).
 * Render the modal with @include('admin::partials.media-picker-modal').
 */
trait WithMediaLibraryPicker
{
    /** Whether the library picker is open (its media grid is only queried while true). */
    public bool $showImagePicker = false;

    /** Where a picked image goes: `gallery` (component hook) or `editor` (insert at caret). */
    public string $imagePickerMode = 'gallery';

    /** Editor mode also accepts an external image URL (owner: both paths, like the old T5G). */
    public string $externalImageUrl = '';

    public function openImagePicker(string $mode = 'gallery'): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->imagePickerMode = $mode === 'editor' ? 'editor' : 'gallery';
        $this->showImagePicker = true;
    }

    public function closeImagePicker(): void
    {
        $this->showImagePicker = false;
    }

    public function pickImage(int $mediaId): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // Only ids from THIS site's LIVE library — never an arbitrary id from the wire payload.
        $media = Media::query()->whereKey($mediaId)->first();
        if ($media === null) {
            return;
        }

        if ($this->imagePickerMode === 'editor') {
            // Wide enough for article bodies; the CDN resizes on demand anyway.
            $this->dispatch('admin-editor-insert-image', url: app(MediaUrlResolver::class)->url($media->storage_key, 1200));
            $this->showImagePicker = false;

            return;
        }

        $this->addGalleryImage($media->id);
    }

    /** Video-embed dialog (rich editor): paste a youtube/vimeo URL + optional autoplay. */
    public bool $showVideoDialog = false;

    public string $videoUrl = '';

    public bool $videoAutoplay = false;

    public function openVideoDialog(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
        $this->reset('videoUrl', 'videoAutoplay');
        $this->showVideoDialog = true;
    }

    public function closeVideoDialog(): void
    {
        $this->showVideoDialog = false;
    }

    /** Build the canonical responsive embed server-side and hand it to the editor island. */
    public function insertVideo(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $html = VideoEmbed::html($this->videoUrl, $this->videoAutoplay);
        if ($html === null) {
            $this->addError('videoUrl', __('catalog.video_invalid'));

            return;
        }

        $this->dispatch('admin-editor-insert-html', html: $html);
        $this->reset('videoUrl', 'videoAutoplay');
        $this->showVideoDialog = false;
    }

    /** Insert an EXTERNAL image url at the caret (editor mode only — galleries stay library-only). */
    public function insertExternalImage(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        if ($this->imagePickerMode !== 'editor') {
            return;
        }

        $url = trim($this->externalImageUrl);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->addError('externalImageUrl', __('catalog.picker_external_invalid'));

            return;
        }

        $this->dispatch('admin-editor-insert-image', url: $url);
        $this->reset('externalImageUrl');
        $this->showImagePicker = false;
    }

    /** Gallery-mode hook — components that carry a gallery override this; default: no-op. */
    protected function addGalleryImage(int $mediaId): void {}

    /**
     * The picker grid (images only, newest first) — call from render() while the modal is open.
     *
     * @return Collection<int, Media>
     */
    protected function pickerMedia(): Collection
    {
        if (! $this->showImagePicker) {
            /** @var Collection<int, Media> */
            return collect();
        }

        return Media::query()->where('mime', 'like', 'image/%')->orderByDesc('created_at')->limit(60)->get();
    }
}

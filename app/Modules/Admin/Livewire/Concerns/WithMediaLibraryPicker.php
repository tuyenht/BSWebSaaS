<?php

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Actions\RegisterMediaUpload;
use App\Modules\Media\Exceptions\MediaRejectedException;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Media\Services\ExternalImageFetcher;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Shared\Support\VideoEmbed;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use RuntimeException;

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

    /**
     * Full-page management surface (the /admin/media page) vs. the insert-picker modal. In manage
     * mode the modal chrome + "Chèn ảnh" footer are gone, clicking a tile opens its detail (not a
     * pick), and the library content is always shown (no showImagePicker toggle).
     */
    public bool $pickerManageMode = false;

    public function openImagePicker(string $mode = 'gallery'): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->imagePickerMode = $mode === 'editor' ? 'editor' : 'gallery';
        // Fresh modal per open — stale filters/selection from a previous pick would surprise;
        // the view/sort preference survives within the component's lifetime.
        $this->reset('pickerTab', 'pickerType', 'pickerFormat', 'pickerSearch', 'pickerFolderId',
            'pickerTrash', 'pickerSelected', 'pickerPage', 'pickerNewFolderOpen', 'pickerNewFolderName',
            'pickerNewFolderSlug', 'pickerNewFolderSlugEdited', 'pickerNewFolderSlugFallback',
            'pickerSource', 'pickerUrlInput', 'pickerUrlPreview', 'pickerUrlSaveCopy',
            'pickerDetailId', 'pickerDetailAlt');
        $this->showImagePicker = true;
    }

    public function closeImagePicker(): void
    {
        $this->discardStagedUrlPreview();
        $this->showImagePicker = false;
    }

    /** Drop an un-inserted URL-preview's staged bytes eagerly (the _incoming/ lifecycle backstops). */
    private function discardStagedUrlPreview(): void
    {
        if ($this->pickerUrlPreview !== null) {
            Storage::disk(MediaKey::disk())->delete($this->pickerUrlPreview['tempKey']);
            $this->pickerUrlPreview = null;
        }
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

    /** Gallery-mode hook — components that carry a gallery override this; default: no-op. */
    protected function addGalleryImage(int $mediaId): void {}

    /**
     * The picker grid (images only, newest first) — call from render() while the modal is open.
     * Superseded by mediaPickerState() (picker v2); kept so host render() signatures survive
     * until the Phase-6 cleanup.
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

    /*
    |------------------------------------------------------------------------
    | Picker v2 (docs/design_handoff_media_picker) — two-tab modal state
    |------------------------------------------------------------------------
    | Server-side filters/sort/pagination over the site library. Every mutating
    | action re-gates (the livewire/update endpoint bypasses route middleware);
    | every id from the wire is re-resolved through the RLS-scoped query so a
    | foreign site's id can never select, move, delete or insert.
    */

    public string $pickerTab = 'library';

    public string $pickerView = 'grid';

    public string $pickerSort = 'recent';

    public string $pickerType = 'all';

    public string $pickerFormat = 'all';

    public string $pickerSearch = '';

    public ?int $pickerFolderId = null;

    /** Trash view (onlyTrashed) — a virtual "folder", not a media_folders row. */
    public bool $pickerTrash = false;

    /** @var list<int> */
    public array $pickerSelected = [];

    public int $pickerPage = 1;

    public bool $pickerNewFolderOpen = false;

    public string $pickerNewFolderName = '';

    /** The media whose detail panel is open (null = closed). */
    public ?int $pickerDetailId = null;

    /** Editable alt text for the open detail, in the site's default content locale. */
    public string $pickerDetailAlt = '';

    /** Live slug preview under the name field (editable via the pencil). */
    public string $pickerNewFolderSlug = '';

    /** True once the admin hand-edits the slug — stop auto-deriving it from the name. */
    public bool $pickerNewFolderSlugEdited = false;

    /** The name didn't transliterate to Latin (CJK/emoji) → show the amber "sửa tay" hint. */
    public bool $pickerNewFolderSlugFallback = false;

    private const int PICKER_PER_PAGE = 24;

    public function updatedPickerSearch(): void
    {
        $this->pickerPage = 1;
    }

    public function updatedPickerSort(): void
    {
        $this->pickerPage = 1;
    }

    public function updatedPickerType(): void
    {
        $this->pickerPage = 1;
    }

    public function updatedPickerFormat(): void
    {
        $this->pickerPage = 1;
    }

    /** The narrow-screen folder dropdown binds pickerFolderId directly — re-validate + reset page. */
    public function updatedPickerFolderId(mixed $value): void
    {
        $this->pickerFolderId = ($value === null || $value === '')
            ? null
            : MediaFolder::query()->whereKey($value)->first()?->id;
        $this->pickerTrash = false;
        $this->pickerPage = 1;
    }

    public function setPickerTab(string $tab): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->pickerTab = $tab === 'upload' ? 'upload' : 'library';
        $this->pickerSource = null;   // tab switch always lands on the source grid (handoff behavior)
        $this->pickerTrash = false;   // …and out of the trash view
        $this->closePickerDetail();   // …and closes the detail panel
    }

    public function setPickerView(string $view): void
    {
        $this->pickerView = $view === 'list' ? 'list' : 'grid';
    }

    public function setPickerFolder(?int $folderId): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // Re-resolve through the scoped query — a foreign site's folder id falls back to "all".
        $this->pickerFolderId = $folderId === null
            ? null
            : MediaFolder::query()->whereKey($folderId)->first()?->id;
        $this->pickerTrash = false; // leaving the trash view
        $this->pickerSelected = [];
        $this->pickerPage = 1;
        $this->closePickerDetail();
    }

    /** The virtual "Thùng rác" sidebar entry — shows onlyTrashed, swaps the bulk actions. */
    public function openPickerTrash(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->pickerTrash = true;
        $this->pickerFolderId = null;
        $this->pickerSelected = [];
        $this->pickerPage = 1;
        $this->closePickerDetail();
    }

    public function togglePickerItem(int $mediaId): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // Live library resolves live rows; the trash view resolves trashed rows. Either way the id
        // is re-checked against THIS site's scoped query — never trusted from the wire.
        $exists = $this->pickerTrash
            ? Media::onlyTrashed()->whereKey($mediaId)->exists()
            : Media::query()->whereKey($mediaId)->exists();
        if (! $exists) {
            return;
        }

        if (in_array($mediaId, $this->pickerSelected, true)) {
            $this->pickerSelected = array_values(array_diff($this->pickerSelected, [$mediaId]));

            return;
        }

        // Editor mode inserts ONE image — selecting replaces. Gallery + trash (restore/purge) stack.
        $this->pickerSelected = ($this->imagePickerMode === 'editor' && ! $this->pickerTrash)
            ? [$mediaId]
            : [...$this->pickerSelected, $mediaId];
    }

    public function clearPickerSelection(): void
    {
        $this->pickerSelected = [];
    }

    /*
    |------------------------------------------------------------------------
    | Detail panel (handoff screen 8) — preview + per-locale alt text
    |------------------------------------------------------------------------
    */

    public function openPickerDetail(int $mediaId): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        // RLS-scoped: never an arbitrary id from the wire — a foreign/trashed id resolves to null.
        $media = Media::query()->whereKey($mediaId)->first();
        if ($media === null) {
            return;
        }

        $this->pickerDetailId = $media->id;
        $this->pickerDetailAlt = $media->alt[$this->pickerContentLocale()] ?? '';
        $this->resetErrorBag('pickerDetailAlt');   // a refusal shown for the previous image
    }

    public function closePickerDetail(): void
    {
        $this->pickerDetailId = null;
        $this->pickerDetailAlt = '';
    }

    /** Save the alt text for the site's default content locale (metadata write → media.upload). */
    public function savePickerDetailAlt(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        if ($this->pickerDetailId === null) {
            return;
        }
        $media = Media::query()->whereKey($this->pickerDetailId)->first();
        if ($media === null) {
            return;
        }

        $this->resetErrorBag('pickerDetailAlt');
        $text = trim($this->pickerDetailAlt);
        if (mb_strlen($text) > Media::ALT_MAX_LENGTH) {
            $this->addError('pickerDetailAlt', __('media.picker_detail_alt_too_long', ['max' => Media::ALT_MAX_LENGTH]));

            return;
        }

        // Per-locale JSONB — only this locale's key changes; other locales' alt survive.
        $alt = $media->alt;
        $alt[$this->pickerContentLocale()] = $text;
        $media->update(['alt' => $alt]);

        session()->flash('media-status', __('media.picker_detail_saved'));
    }

    /** The site's default content locale — alt is storefront copy, not the admin UI language. */
    private function pickerContentLocale(): string
    {
        return app(SiteLocale::class)->default();
    }

    /*
    |------------------------------------------------------------------------
    | "Ảnh từ URL" source (handoff §1b) — server fetch through the SSRF gate
    |------------------------------------------------------------------------
    */

    /** Upload-tab screen: null = source grid; 'url' now, device/camera/clouds in later phases. */
    public ?string $pickerSource = null;

    public string $pickerUrlInput = '';

    /** @var array{tempKey: string, url: string, filename: string, mime: string, size: int, width: int|null, height: int|null}|null */
    public ?array $pickerUrlPreview = null;

    /** "Lưu bản sao vào Thư viện ảnh" — default ON (hotlinks die when the origin deletes them). */
    public bool $pickerUrlSaveCopy = true;

    public function openPickerSource(string $source): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        if ($source === 'device') {
            abort_unless(Gate::allows('media.upload'), 403); // device upload writes real media
            $this->pickerSource = 'device';

            return;
        }
        if ($source === 'url') { // camera/clouds join in phase 5
            $this->pickerSource = 'url';
        }
    }

    /**
     * The device uploader island finished a batch (browser → R2 → register). Land on the library
     * tab so the freshly-uploaded masters (newest-first) are right there to pick.
     */
    #[On('media-uploaded')]
    public function onPickerMediaUploaded(): void
    {
        if (! $this->showImagePicker) {
            return;
        }
        $this->pickerTab = 'library';
        $this->pickerSource = null;
        $this->pickerTrash = false;
        $this->pickerPage = 1;
        unset($this->mediaPickerState); // drop the request-cached computed so the grid re-queries
    }

    public function backToPickerSources(): void
    {
        $this->discardStagedUrlPreview();
        $this->pickerSource = null;
    }

    /** Server fetches are gated to media.upload and capped per user — see previewPickerUrl. */
    private const int URL_PREVIEW_MAX_PER_MINUTE = 20;

    /**
     * "Xem trước": the SERVER fetches the URL (SSRF-guarded) and stages it. Gated to media.upload,
     * not just view — a fetch spends network egress + a real R2 write and is the SSRF surface, so
     * a view-only actor must not drive it — and rate-limited so it can't be a scanner / egress DoS.
     */
    public function previewPickerUrl(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->resetErrorBag('pickerUrlInput');
        $url = trim($this->pickerUrlInput);

        // Fast client-shape check before any network: public http(s) URL with an image extension.
        if (preg_match('#^https?://\S+\.(jpe?g|png|webp|gif|avif)([?\#]\S*)?$#i', $url) !== 1) {
            $this->addError('pickerUrlInput', __('media.picker_url_invalid'));

            return;
        }

        $rlKey = 'media:url-preview:'.(Auth::id() ?? 'guest');
        if (RateLimiter::tooManyAttempts($rlKey, self::URL_PREVIEW_MAX_PER_MINUTE)) {
            $this->addError('pickerUrlInput', __('media.picker_url_throttled', ['seconds' => RateLimiter::availableIn($rlKey)]));

            return;
        }
        RateLimiter::hit($rlKey, 60);

        // Replacing a previous preview? Its staged bytes are abandoned — clean them eagerly.
        $this->discardStagedUrlPreview();

        try {
            $this->pickerUrlPreview = app(ExternalImageFetcher::class)
                ->handle($url, (string) Site::query()->find(app(CurrentSite::class)->id())?->name);
            // The URL source lives on the upload tab — land there so the preview screen shows.
            $this->pickerTab = 'upload';
            $this->pickerSource = 'url';
        } catch (MediaRejectedException $e) {
            $this->pickerUrlPreview = null;
            $this->addError('pickerUrlInput', $e->userMessage());
        } catch (RuntimeException $e) {
            // Not the link's fault (storage not configured, an SDK error): log it, keep its text out of the screen.
            report($e);
            $this->pickerUrlPreview = null;
            $this->addError('pickerUrlInput', __('media.err_unexpected'));
        }
    }

    /** The URL screen's preview counts as the pick — it feeds the footer's "Chèn ảnh". */
    public function hasUrlPick(): bool
    {
        return $this->pickerTab === 'upload' && $this->pickerSource === 'url' && $this->pickerUrlPreview !== null;
    }

    /** Insert the previewed URL image: import a copy through register, or hotlink (editor only). */
    private function insertUrlPreview(): void
    {
        $preview = $this->pickerUrlPreview;
        if ($preview === null) {
            return;
        }

        // A gallery row must reference a library master — hotlinking is an editor-only choice.
        $saveCopy = $this->imagePickerMode === 'gallery' || $this->pickerUrlSaveCopy;

        if ($saveCopy) {
            abort_unless(Gate::allows('media.upload'), 403);

            try {
                $media = app(RegisterMediaUpload::class)->handle($preview['tempKey'], $preview['filename']);
            } catch (MediaRejectedException $e) {
                $this->addError('pickerUrlInput', $e->userMessage());

                return;
            } catch (RuntimeException $e) {
                report($e);
                $this->addError('pickerUrlInput', __('media.err_unexpected'));

                return;
            }

            $this->imagePickerMode === 'editor'
                ? $this->dispatch('admin-editor-insert-image', url: app(MediaUrlResolver::class)->url($media->storage_key, 1200))
                : $this->addGalleryImage($media->id);
        } else {
            $this->dispatch('admin-editor-insert-image', url: $preview['url']);
            Storage::disk(MediaKey::disk())->delete($preview['tempKey']); // fetched only to validate
        }

        $this->reset('pickerUrlInput', 'pickerUrlPreview', 'pickerUrlSaveCopy');
        $this->showImagePicker = false;
    }

    /** Footer "Chèn ảnh": editor → caret insert (first pick); gallery → host hook, picked order. */
    public function insertPicked(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        if ($this->hasUrlPick()) {
            $this->insertUrlPreview();

            return;
        }

        $items = Media::query()->whereIn('id', $this->pickerSelected)->get()
            ->sortBy(fn (Media $m): int => (int) array_search($m->id, $this->pickerSelected, true))
            ->values();
        if ($items->isEmpty()) {
            return;
        }

        if ($this->imagePickerMode === 'editor') {
            $first = $items->firstOrFail();
            $this->dispatch('admin-editor-insert-image', url: app(MediaUrlResolver::class)->url($first->storage_key, 1200));
        } else {
            foreach ($items as $media) {
                $this->addGalleryImage($media->id);
            }
        }

        $this->showImagePicker = false;
    }

    public function pickerPrev(): void
    {
        $this->pickerPage = max(1, $this->pickerPage - 1);
    }

    public function pickerNext(): void
    {
        $this->pickerPage++; // clamped against the real last page in mediaPickerState()
    }

    /** Soft-delete the selection (Thùng rác) — per-model so observers keep firing. */
    public function pickerBulkDelete(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        Media::query()->whereIn('id', $this->pickerSelected)->get()->each->delete();
        $this->pickerSelected = [];
    }

    /*
    |------------------------------------------------------------------------
    | Trash actions (onlyTrashed) — restore / permanent purge
    |------------------------------------------------------------------------
    | Trash management is gated to media.delete (same as sending to trash).
    | Purge is per-instance so MediaObserver fires (drops attachments + the R2
    | object); a mass forceDelete would bypass those events.
    */

    public function restorePickerSelection(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        // restore() clears deleted_at → the row returns to its original folder (folder_id was
        // kept; if that folder was itself deleted the FK already SET NULL it to the root view).
        Media::onlyTrashed()->whereIn('id', $this->pickerSelected)->get()->each->restore();
        $this->pickerSelected = [];
        session()->flash('media-status', __('media.picker_trash_restored'));
    }

    public function purgePickerSelection(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        Media::onlyTrashed()->whereIn('id', $this->pickerSelected)->get()->each->forceDelete();
        $this->pickerSelected = [];
        session()->flash('media-status', __('media.picker_trash_purged'));
    }

    public function emptyPickerTrash(): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        Media::onlyTrashed()->get()->each->forceDelete();
        $this->pickerSelected = [];
        session()->flash('media-status', __('media.picker_trash_emptied'));
    }

    /** Move the selection into a folder (null = back to the root "Tất cả ảnh" view). */
    public function pickerBulkMove(?int $folderId): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $target = $folderId === null ? null : MediaFolder::query()->whereKey($folderId)->first()?->id;
        if ($folderId !== null && $target === null) {
            return; // foreign/vanished folder — never a cross-site write
        }

        Media::query()->whereIn('id', $this->pickerSelected)->update(['folder_id' => $target]);
        $this->pickerSelected = [];
    }

    /** Typing the name re-derives the slug preview — until the admin hand-edits it (pencil). */
    public function updatedPickerNewFolderName(): void
    {
        if ($this->pickerNewFolderSlugEdited) {
            return;
        }
        $suggested = (new MediaFolder)->suggestSlugBase(trim($this->pickerNewFolderName));
        $this->pickerNewFolderSlug = trim($this->pickerNewFolderName) === '' ? '' : $suggested['slug'];
        $this->pickerNewFolderSlugFallback = $suggested['fallback'];
    }

    /** Opening the slug field (pencil) freezes auto-derivation; the admin owns it from here. */
    public function editPickerFolderSlug(): void
    {
        $this->pickerNewFolderSlugEdited = true;
        $this->pickerNewFolderSlugFallback = false;
    }

    public function createPickerFolder(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $name = trim($this->pickerNewFolderName);
        if ($name === '' || mb_strlen($name) > 100) {
            $this->addError('pickerNewFolderName', __('media.picker_folder_name_required'));

            return;
        }

        // Hand-edited slug wins (sanitized to ASCII); otherwise derive from the name. uniqueSlug
        // forces the [a-z0-9-] shape and appends -2/-3… on a same-site collision — never an error.
        $folder = new MediaFolder;
        $candidate = $this->pickerNewFolderSlugEdited ? $this->pickerNewFolderSlug : $name;
        $slug = $folder->uniqueSlug($candidate, $name);

        $maxPosition = MediaFolder::query()->max('position');
        $folder = MediaFolder::query()->create([
            'name' => $name,
            'slug' => $slug,
            'position' => (is_numeric($maxPosition) ? (int) $maxPosition : 0) + 1,
        ]);

        $this->reset('pickerNewFolderOpen', 'pickerNewFolderName', 'pickerNewFolderSlug',
            'pickerNewFolderSlugEdited', 'pickerNewFolderSlugFallback');
        $this->pickerFolderId = $folder->id;
        $this->pickerPage = 1;
    }

    /**
     * Everything the picker modal renders, in one request-cached bundle (the Blade partial
     * reads $this->mediaPickerState). Empty shell while closed, so hidden modals cost nothing.
     *
     * @return array{open: bool, page?: LengthAwarePaginator<int, Media>, folders?: EloquentCollection<int, MediaFolder>, total?: int, formats?: array<int, string>, selectedPreview?: EloquentCollection<int, Media>, urls?: MediaUrlResolver, detail?: Media|null, detailUsages?: list<array{kind: string, role: string, label: string, url: string}>, detailUploader?: string|null, contentLocale?: string, trashTotal?: int}
     */
    #[Computed]
    public function mediaPickerState(): array
    {
        // The modal is queried only while open; the manage page always shows its content.
        if (! $this->showImagePicker && ! $this->pickerManageMode) {
            return ['open' => false];
        }

        $page = $this->pickerQuery()->paginate(self::PICKER_PER_PAGE, ['*'], 'pickerPage', $this->pickerPage);
        if ($this->pickerPage > $page->lastPage()) {
            // A filter change / bulk delete shrank the set below the current page — snap back.
            $this->pickerPage = max(1, $page->lastPage());
            $page = $this->pickerQuery()->paginate(self::PICKER_PER_PAGE, ['*'], 'pickerPage', $this->pickerPage);
        }

        $formats = Media::query()->select('mime')->distinct()->pluck('mime')
            ->map(fn (mixed $mime): string => is_string($mime) ? (Media::MIME_EXT_LABELS[$mime] ?? Str::after($mime, '/')) : '')
            ->filter(fn (string $ext): bool => $ext !== '')
            ->unique()->sort()->values()->all();

        $detail = $this->pickerDetailId === null ? null : Media::query()->with('folder')->whereKey($this->pickerDetailId)->first();
        $uploaderName = null;
        if ($detail?->created_by !== null) {
            $name = User::query()->whereKey($detail->created_by)->value('name');
            $uploaderName = is_string($name) ? $name : null;
        }

        return [
            'open' => true,
            'page' => $page,
            'folders' => MediaFolder::query()->withCount('media')->orderBy('position')->orderBy('name')->get(),
            'total' => Media::query()->count(),
            'formats' => $formats,
            'selectedPreview' => Media::query()->whereIn('id', array_slice($this->pickerSelected, 0, 3))->get(),
            'urls' => app(MediaUrlResolver::class),
            'detail' => $detail,
            'detailUsages' => $detail === null ? [] : app(MediaUsageLocator::class)->usagesFor($detail, $this->pickerContentLocale()),
            'detailUploader' => $uploaderName,
            'contentLocale' => $this->pickerContentLocale(),
            'trashTotal' => Media::onlyTrashed()->count(),
        ];
    }

    /**
     * The filtered/sorted library query — folder ∧ type ∧ format ∧ search, then one sort.
     *
     * @return Builder<Media>
     */
    protected function pickerQuery(): Builder
    {
        // Trash view: onlyTrashed, flat (no folder), newest-binned first — a distinct surface.
        if ($this->pickerTrash) {
            $query = Media::onlyTrashed();
            $term = trim($this->pickerSearch);
            if ($term !== '') {
                $query->where('original_filename', 'ilike', '%'.addcslashes($term, '%_\\').'%');
            }

            return $query->orderByDesc('deleted_at')->orderByDesc('id');
        }

        $query = Media::query();

        if ($this->pickerFolderId !== null) {
            $query->where('folder_id', $this->pickerFolderId);
        }

        match ($this->pickerType) {
            'image' => $query->where('mime', 'like', 'image/%'),
            'video' => $query->where('mime', 'like', 'video/%'),
            'audio' => $query->where('mime', 'like', 'audio/%'),
            'doc' => $query->where('mime', 'like', 'application/%'),
            'used' => app(MediaUsageLocator::class)->constrain($query, true),
            'unused' => app(MediaUsageLocator::class)->constrain($query, false),
            default => null,
        };

        if ($this->pickerFormat !== 'all') {
            $mimes = array_keys(Media::MIME_EXT_LABELS, $this->pickerFormat, true);
            $mimes === []
                ? $query->where('mime', 'like', '%/'.$this->pickerFormat) // unmapped ext (built from real data)
                : $query->whereIn('mime', $mimes);
        }

        $term = trim($this->pickerSearch);
        if ($term !== '') {
            $query->where('original_filename', 'ilike', '%'.addcslashes($term, '%_\\').'%');
        }

        return match ($this->pickerSort) {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'nameAsc' => $query->orderBy('original_filename'),
            'nameDesc' => $query->orderByDesc('original_filename'),
            'sizeDesc' => $query->orderByDesc('size'),
            'sizeAsc' => $query->orderBy('size'),
            'ext' => $query->orderBy('mime')->orderBy('original_filename'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }
}

<?php

namespace App\Modules\Admin\Livewire\Media;

use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Media library (tenant plane): the site's reusable image/file masters, newest first. RLS- +
 * BelongsToSite-scoped — another site's asset is never visible. Read is gated by media.view,
 * re-checked in boot() because the livewire/update endpoint bypasses route middleware; upload
 * re-gates media.upload and delete re-gates media.delete (the view/write split). Upload routes
 * through the UploadMedia action (magic-byte sniff + content-hash dedup → one R2 master); the
 * extension/size rule here is the friendly first gate, the action's magic-byte sniff the real one.
 * Delete is soft (Thùng rác); a force-purge later cleans the R2 object + attachments via MediaObserver.
 */
#[Layout('admin::layouts.app')]
final class Library extends Component
{
    use WithFileUploads;
    use WithPagination;

    /** The pending upload (validated as an image/pdf ≤10MB; the action re-sniffs the magic bytes). */
    public ?TemporaryUploadedFile $upload = null;

    public function boot(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
    }

    public function save(): void
    {
        abort_unless(Gate::allows('media.upload'), 403);

        $this->validate([
            'upload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:'.UploadMedia::MAX_KB],
        ], [
            'upload.required' => __('media.err_required'),
            'upload.file' => __('media.err_mimes'),
            'upload.mimes' => __('media.err_mimes'),
            'upload.max' => __('media.err_max'),
        ]);

        if ($this->upload === null) {
            return; // validate() guarantees this; the guard narrows the type for the action call.
        }

        $site = Site::query()->find(app(CurrentSite::class)->id());
        $actor = auth()->id();

        try {
            app(UploadMedia::class)->handle(
                $this->upload,
                (string) $site?->name,
                'library',
                is_int($actor) ? $actor : null,
            );
        } catch (RuntimeException $e) {
            // The action rejected on the magic-byte sniff (or size) — surface its plain-language
            // message instead of a 500, so a content/extension mismatch reads as a normal error.
            $this->addError('upload', $e->getMessage());

            return;
        }

        $this->reset('upload');
        $this->resetPage();
        session()->flash('media-status', __('media.upload_success'));
    }

    public function delete(string $ulid): void
    {
        abort_unless(Gate::allows('media.delete'), 403);

        $media = Media::query()->where('ulid', $ulid)->first();
        if ($media instanceof Media) {
            $media->delete(); // soft → Thùng rác (the R2 object survives until a force-purge)
            session()->flash('media-status', __('media.delete_success'));
        }
    }

    public function render(): View
    {
        $media = Media::query()->orderByDesc('created_at')->paginate(24);

        $view = view('admin::livewire.media.library', [
            'media' => $media,
            'urls' => app(MediaUrlResolver::class),
        ]);
        $view->title(__('media.library_title'));

        return $view;
    }
}

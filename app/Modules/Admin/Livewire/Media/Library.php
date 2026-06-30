<?php

namespace App\Modules\Admin\Livewire\Media;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Media library (tenant plane): the site's reusable image/file masters, newest first. RLS- +
 * BelongsToSite-scoped — another site's asset is never visible. Read is gated by media.view,
 * re-checked in boot() because the livewire/update endpoint bypasses route middleware; delete
 * re-gates media.delete. Upload no longer flows through this component: the Uppy island in the view
 * PUTs straight to R2 and finalizes via the media.{presign,register} endpoints (each re-gating
 * media.upload server-side, the real magic-byte sniff in RegisterMediaUpload), then dispatches a
 * `media-uploaded` browser event so the grid here refreshes. Delete is soft (Thùng rác); a
 * force-purge later cleans the R2 object + attachments via MediaObserver.
 */
#[Layout('admin::layouts.app')]
final class Library extends Component
{
    use WithPagination;

    public function boot(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
    }

    /**
     * The Uppy island finished a batch (browser → R2 → register). Re-render to the first page so the
     * newest masters show. The island lives in a wire:ignore block, so this morph never touches it.
     */
    #[On('media-uploaded')]
    public function onMediaUploaded(): void
    {
        $this->resetPage();
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

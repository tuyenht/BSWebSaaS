<?php

namespace App\Modules\Admin\Livewire\Media;

use App\Modules\Admin\Livewire\Concerns\WithMediaLibraryPicker;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Media library management page (/admin/media). The full-page twin of the insert-picker modal: it
 * reuses WithMediaLibraryPicker in MANAGE mode, so folders, filters, grid/list, detail + alt, bulk
 * move/delete, the Trash and the upload tabs are the same code — there is no "insert" here (this is
 * management, not picking). Read is gated by media.view, re-checked in boot() (the livewire/update
 * endpoint bypasses route middleware); every mutating action re-gates inside the trait.
 */
#[Layout('admin::layouts.app')]
final class Library extends Component
{
    use WithMediaLibraryPicker;

    public function mount(): void
    {
        abort_unless(Gate::allows('media.view'), 403);

        $this->pickerManageMode = true;
        $this->showImagePicker = true; // content is always shown (this is a page, not a modal)
        $this->pickerTab = 'library';
    }

    public function boot(): void
    {
        abort_unless(Gate::allows('media.view'), 403);
    }

    public function render(): View
    {
        $view = view('admin::livewire.media.library');
        $view->title(__('media.library_title'));

        return $view;
    }
}

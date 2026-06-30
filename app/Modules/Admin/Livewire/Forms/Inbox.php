<?php

namespace App\Modules\Admin\Livewire\Forms;

use App\Modules\Forms\Models\FormSubmission;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lead inbox (tenant plane): the site's captured form submissions, newest first, filterable by
 * status. site-scoped by BelongsToSite + RLS (a lead from another site is never visible). Gated by
 * forms.view, re-checked in boot() because the livewire/update endpoint bypasses route middleware.
 * The parent form is eager-loaded (no N+1); status changes live in the Detail view (forms.manage).
 */
#[Layout('admin::layouts.app')]
final class Inbox extends Component
{
    use WithPagination;

    /** Filter by submission status (new|read|archived|spam); '' = all. */
    #[Url]
    public string $status = '';

    public function boot(): void
    {
        abort_unless(Gate::allows('forms.view'), 403);
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = FormSubmission::query()->with('form');

        if (in_array($this->status, ['new', 'read', 'archived', 'spam'], true)) {
            $query->where('status', $this->status);
        }

        $submissions = $query->orderByDesc('created_at')->paginate(20);

        $view = view('admin::livewire.forms.inbox', ['submissions' => $submissions]);
        $view->title(__('forms.inbox_title'));

        return $view;
    }
}

<?php

namespace App\Modules\Admin\Livewire\Forms;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A single captured lead (tenant plane). Addressed by ulid (D2). site-scoped by RLS (firstOrFail
 * 404s a lead from another site). Gated by forms.view (re-checked in boot()); a 'new' lead is
 * auto-marked 'read' on first view by a manager. Status changes (read/archived/spam/new) require
 * forms.manage — the view/manage split keeps a read-only role from mutating the inbox.
 */
#[Layout('admin::layouts.app')]
final class Detail extends Component
{
    public FormSubmission $submission;

    public function boot(): void
    {
        abort_unless(Gate::allows('forms.view'), 403);
    }

    public function mount(string $ulid): void
    {
        $this->submission = FormSubmission::query()->where('ulid', $ulid)->firstOrFail();

        // Inbox UX: opening a new lead marks it read — but only for a manager (a read-only
        // viewer must not mutate state).
        if ($this->submission->status === 'new' && Gate::allows('forms.manage')) {
            $this->submission->update(['status' => 'read']);
        }
    }

    public function setStatus(string $status): void
    {
        abort_unless(Gate::allows('forms.manage'), 403);

        if (! in_array($status, ['new', 'read', 'archived', 'spam'], true)) {
            return;
        }

        $this->submission->update(['status' => $status]);
        session()->flash('status', __('forms.status_updated'));
    }

    public function render(): View
    {
        $view = view('admin::livewire.forms.detail', [
            'submission' => $this->submission,
            'siteTime' => app(SiteTime::class),
        ]);
        $view->title(__('forms.lead_title'));

        return $view;
    }
}

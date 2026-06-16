<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Models\Entry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lists the site's `page` entries (site-scoped by BelongsToSite + RLS). Read access
 * is gated by EntryPolicy::viewAny; per-row edit is gated again in the Editor. The
 * default-locale translation is eager-loaded to keep the list at a flat query budget.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    /** Locale shown in the list. GĐ1 = the site default (vi for An Vinh). */
    private const LOCALE = 'vi';

    public function mount(): void
    {
        $this->authorize('viewAny', Entry::class);
    }

    public function render(): View
    {
        $pages = Entry::query()
            ->where('type', 'page')
            ->with('translations')
            ->orderByDesc('id')
            ->get();

        return view('admin::livewire.entries.index', [
            'pages' => $pages,
            'locale' => self::LOCALE,
        ]);
    }
}

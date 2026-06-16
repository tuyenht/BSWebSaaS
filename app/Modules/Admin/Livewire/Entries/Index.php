<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lists the site's `page` entries (site-scoped by BelongsToSite + RLS). Read access
 * is gated by EntryPolicy::viewAny; per-row edit is gated again in the Editor. Only
 * the lightweight translation columns are eager-loaded (no body blob) to keep the
 * list at a flat, cheap query budget.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    public function mount(): void
    {
        $this->authorize('viewAny', Entry::class);
    }

    public function render(): View
    {
        $locale = app(SiteLocale::class)->default();

        $pages = Entry::query()
            ->where('type', 'page')
            ->with('translations:id,entry_id,locale,title,slug,status')
            ->orderByDesc('id')
            ->get();

        return view('admin::livewire.entries.index', [
            'pages' => $pages,
            'locale' => $locale,
        ]);
    }
}

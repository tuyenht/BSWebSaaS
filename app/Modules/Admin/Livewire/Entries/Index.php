<?php

namespace App\Modules\Admin\Livewire\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lists a content_section's entries (site-scoped by BelongsToSite + RLS). The section is
 * resolved from ?section=… (the key AdminNav puts on every nav link); it drives the
 * entry_type filter + the heading/create-link. With no section it falls back to pages.
 * Read access is gated by EntryPolicy::viewAny; per-row edit is gated again in the Editor.
 * Only lightweight translation columns are eager-loaded (no body blob) to keep the list
 * at a flat, cheap query budget.
 */
#[Layout('admin::layouts.app')]
final class Index extends Component
{
    use AuthorizesRequests;

    /** content_section key (?section=…); null → the pages fallback. */
    public ?string $section = null;

    public function mount(?string $section = null): void
    {
        $this->section = $section ?? request()->query('section');
        $this->authorize('viewAny', Entry::class);
    }

    public function render(): View
    {
        $locale = app(SiteLocale::class)->default();
        $section = $this->resolveSection();
        $entryType = $section instanceof ContentSection ? ($section->entry_type ?? 'page') : 'page';

        $entries = Entry::query()
            ->where('type', $entryType)
            ->with('translations:id,entry_id,locale,title,slug,status')
            ->orderByDesc('id')
            ->get();

        return view('admin::livewire.entries.index', [
            'entries' => $entries,
            'locale' => $locale,
            'section' => $section,
            'sectionKey' => $this->section,
        ]);
    }

    private function resolveSection(): ?ContentSection
    {
        if ($this->section === null || $this->section === '') {
            return null;
        }

        return ContentSection::query()->where('key', $this->section)->first();
    }
}

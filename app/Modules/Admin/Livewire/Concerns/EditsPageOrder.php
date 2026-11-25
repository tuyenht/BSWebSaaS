<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Entries\PageMoveWords;
use App\Modules\Cms\Actions\MovePage;
use App\Modules\Cms\Exceptions\PageMoveRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

/**
 * «Thứ tự» in the page editor's «Thuộc tính trang» (board 07): the page's place among the sub-pages of
 * its parent (the top pages when it has none), counted from 1, in the order the Trang list's «Thứ tự
 * trên website» shows them ((position, id), live pages only). The place is shared by every language, so
 * it is changed in the website's default one only, as «Trang cha» is.
 *
 * The field shows where the page is, or, for a new page or after another «Trang cha» is picked, where
 * the save puts it: the last place. A save that carries another number moves the page there through
 * MovePage::handle() — the one definition of a move (the page-tree lock, row locks, the audit), which
 * puts a number past the end of the list at the end. A number that is not a whole number goes back to
 * the place shown; one below 1 is the first place.
 */
trait EditsPageOrder
{
    /** Client-set: the typed place (text, as the number field sends it); normalised as it arrives and again by the save. */
    public string $pageOrder = '';

    /** The place the field showed for the parent as loaded or picked: a save moves the page only when the typed place differs. */
    #[Locked]
    public string $pageOrderLoaded = '';

    /** A place typed in «Thứ tự», as the field is left: a whole number from 1, in the website's default language only. */
    public function updatedPageOrder(mixed $value): void
    {
        $this->authorizeEditing();
        if ($this->type !== 'page' || $this->locale !== app(SiteLocale::class)->default()) {
            $this->pageOrder = $this->pageOrderLoaded;

            return;
        }
        $this->pageOrder = $this->normalPageOrder($value);
    }

    /** The typed place as a whole number from 1, or the place shown when it is not a number. */
    private function normalPageOrder(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';
        if (preg_match('/^[+-]?\d{1,9}$/', $text) !== 1) {
            return $this->pageOrderLoaded;
        }

        return (string) max(1, (int) $text);
    }

    /** The page's place now among the live pages beside it (1: the first). */
    private function loadPageOrder(Entry $page): void
    {
        $position = $page->position;
        $before = $this->besidePages($page->parent_id)
            ->where(fn (Builder $q) => $q->where('position', '<', $position)
                ->orWhere(fn (Builder $same) => $same->where('position', $position)->where('id', '<', $page->id)))
            ->count();
        $this->pageOrder = $this->pageOrderLoaded = (string) ($before + 1);
    }

    /**
     * The place shown once the parent is $parentId (a new page, or another «Trang cha» picked): where
     * the page is when that is its parent now, else the last place there. A place the member typed
     * stays typed. The place shown is not a change of its own: the saved values take it, so a pick of
     * «Trang cha» counts once among the unsaved changes, and a typed place still counts.
     */
    private function placePageOrder(?int $parentId): void
    {
        $typed = $this->pageOrder !== $this->pageOrderLoaded;
        $page = $this->ulid === null ? null : Entry::withTrashed()->where('ulid', $this->ulid)->first(['id', 'site_id', 'parent_id', 'position']);
        $typedPlace = $this->pageOrder;
        if ($page instanceof Entry && $page->parent_id === $parentId) {
            $this->loadPageOrder($page);
        } else {
            $others = $this->besidePages($parentId)->when($page instanceof Entry, fn (Builder $q) => $q->whereKeyNot($page?->id))->count();
            $this->pageOrder = $this->pageOrderLoaded = (string) ($others + 1);
        }
        $this->saved['pageOrder'] = $this->pageOrderLoaded;
        if ($typed) {
            $this->pageOrder = $typedPlace;
        }
    }

    /** @return Builder<Entry> the live pages under $parentId (the top pages when null) */
    private function besidePages(?int $parentId): Builder
    {
        return Entry::query()->where('type', 'page')
            ->when($parentId === null, fn (Builder $q) => $q->whereNull('parent_id'), fn (Builder $q) => $q->where('parent_id', $parentId));
    }

    /** Does this save move the page to the place typed in «Thứ tự»? */
    private function reordering(): bool
    {
        return $this->type === 'page' && $this->pageOrderLoaded !== ''
            && $this->locale === app(SiteLocale::class)->default()
            && $this->normalPageOrder($this->pageOrder) !== $this->pageOrderLoaded;
    }

    /** The typed place as MovePage takes it (0: the first), or null when this save does not reorder the page. */
    private function typedPageIndex(): ?int
    {
        return $this->reordering() ? (int) $this->normalPageOrder($this->pageOrder) - 1 : null;
    }

    /**
     * Before the page's text is written (a new page: once its row is made): the page goes to the typed
     * place under the parent it has now, through MovePage, and the field shows the place it took. With no
     * place typed, a new page ($made) shows where it was made. False, with the reason under the field,
     * when the tree refused the move. A new «Trang cha» with a typed place is one move (moveToParent).
     */
    private function applyPageOrder(Entry $page, bool $made): bool
    {
        if (! $this->reordering()) {
            if ($made) {
                $this->loadPageOrder($page);
            }

            return true;
        }
        $parent = null;
        if ($page->parent_id !== null) {
            $parent = Entry::query()->whereKey($page->parent_id)->where('type', 'page')->first();
            if (! $parent instanceof Entry) {
                // the parent went to the Trash meanwhile: never read as «no parent» (a move to the top)
                return $this->refusePageOrder(new PageMoveRejectedException('parent_invalid'));
            }
        }
        try {
            $place = app(MovePage::class)->handle($page, $parent, (int) $this->typedPageIndex());
        } catch (PageMoveRejectedException $refused) {
            return $this->refusePageOrder($refused);
        }
        $page->setRawAttributes(['parent_id' => $place['parent'], 'position' => $place['position']] + $page->getAttributes(), true);
        $this->pageOrder = $this->pageOrderLoaded = (string) ($place['position'] + 1);

        return true;
    }

    /**
     * A new page made, its typed place refused (applyPageOrder): the rest of the save stands, and the form
     * says so as a refused save does — the error, the reason under the field kept, and the typed place
     * still unsaved against the place the page was made at, so the next save asks for it again.
     */
    private function keepRefusedPageOrder(Entry $page): void
    {
        $typed = $this->pageOrder;
        $this->loadPageOrder($page);
        $this->saved['pageOrder'] = $this->pageOrderLoaded;
        $this->pageOrder = $typed;
        $this->saveFailed = true;
    }

    /** A refused «Thứ tự» on save: the reason under the field and in a message that stays. */
    private function refusePageOrder(PageMoveRejectedException $refused): bool
    {
        $this->saveFailed = true;
        if ($refused->reason === 'page_invalid') {
            // the page itself went to the Trash meanwhile: said as for any save of a page that is gone
            $this->staleRow();

            return false;
        }
        $why = $refused->reason === 'parent_invalid' ? Cast::string(__('entries.ed.par_gone')) : PageMoveWords::why($refused);
        $this->addError('pageOrder', $why);
        $this->dispatch('bs-toast', text: $why, stay: true, close: Cast::string(__('admin.close')));

        return false;
    }
}

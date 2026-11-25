<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Entries\PageStartTemplates;
use App\Modules\Admin\Support\EntryProseBody;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use Livewire\Attributes\Locked;

/**
 * «Bắt đầu từ mẫu» above the writing area of a new page (board 07): while the page has never been saved,
 * its body is written text and still empty, in the website's default language, a card offers the starts
 * of PageStartTemplates. A template writes its headings and suggested text into the body (and its name
 * as the title of a page that has none, the link following it); «Trang trống» writes nothing. Either way
 * the card goes. Nothing is saved: the page is saved as any other edit.
 */
trait StartsPageFromTemplate
{
    /** A start was picked: the card is not offered again on this form. */
    #[Locked]
    public bool $startDone = false;

    /** A start picked on the card. */
    public function pickStartTemplate(string $key): void
    {
        $this->authorizeEditing();
        if ($this->ulid !== null || $this->type !== 'page' || $this->startDone || ! $this->prose
            || ! EntryProseBody::isBlank($this->bodyHtml) || $this->locale !== app(SiteLocale::class)->default()) {
            return;
        }
        $start = PageStartTemplates::content($key, $this->locale);
        if ($start === null) {
            return;
        }
        $this->startDone = true;
        if ($start['html'] === '') {
            $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.start_blank_done')));

            return;
        }
        if (trim($this->title) === '') {
            $this->title = $start['title'];
            $this->updatedTitle();
        }
        $this->bodyHtml = $start['html'];
        $this->dispatch('admin-editor-set-html', target: 'bodyHtml', html: $start['html']);
        $this->dispatch('bs-toast', text: Cast::string(__('entries.ed.start_used', ['name' => __('entries.ed.start_'.$key)])));
    }
}

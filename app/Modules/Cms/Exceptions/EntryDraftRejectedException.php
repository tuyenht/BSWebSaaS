<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A pending change to live content (EntryDraft) refused for a reason the person can act on, shown
 * in their own language. Its own type so no broad `catch (RuntimeException)` swallows it and no
 * unexpected failure's text reaches the screen. The message IS the reason key, one of REASONS:
 *
 *  - change_locked: the change was sent for review; it can be edited again once it is returned.
 *  - change_pending: the change is waiting for review, so it cannot be thrown away now.
 *  - change_stale: the change was edited, moved or handled since this screen loaded it.
 *  - not_live: the entry is not on the website (or is in the Trash), so edits go straight onto it.
 *  - content_invalid: the change does not pass the editor's checks (title, address, layout,
 *    category).
 *  - category_governed: the chosen category would take the post out of its approval workflow.
 *  - locale_disabled: the change's language is switched off on the site.
 *  - slug_taken: the change's address belongs to other content now.
 *  - workflow_governed: an approval workflow governs the entry; the change must be approved.
 */
final class EntryDraftRejectedException extends DomainException
{
    public const array REASONS = [
        'change_locked', 'change_pending', 'change_stale', 'not_live', 'content_invalid',
        'category_governed', 'locale_disabled', 'slug_taken', 'workflow_governed',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** The sentence to show, in the current locale. */
    public function userMessage(): string
    {
        return __('entries.change_err_'.$this->reason);
    }
}

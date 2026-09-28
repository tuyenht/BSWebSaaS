<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A revision restore refused for a reason the person restoring can act on, shown in their own
 * language. Its own type so no broad `catch (RuntimeException)` swallows it and no unexpected
 * failure's text reaches the screen. The message IS the reason key, one of REASONS:
 *
 *  - slug_taken: the old address now belongs to other content.
 *  - locale_disabled: the revision's language is switched off on the site.
 *  - snapshot_incompatible: the old content no longer passes today's checks (e.g. a block type
 *    that is no longer offered) — writing it would put a broken layout online.
 *  - entry_trashed: the entry is in the Trash; restore the entry first.
 */
final class RevisionRestoreRejectedException extends DomainException
{
    public const array REASONS = ['slug_taken', 'locale_disabled', 'snapshot_incompatible', 'entry_trashed'];

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** The sentence to show, in the current locale. */
    public function userMessage(): string
    {
        return __('entries.revision_err_'.$this->reason);
    }
}

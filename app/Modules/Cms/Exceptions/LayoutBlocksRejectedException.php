<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A slot's blocks refused before anything is written, for a reason the screen can say in plain
 * words. `reason` is one of: slot_invalid (no such slot) · locale_invalid (not one of the site's
 * languages) · blocks_invalid (not a block list, or a block the page body check refuses: an
 * unknown block, a bad link, another site's page or file…) · blocks_too_many (more blocks than the
 * slot holds). `slot` and `locale` say where; the message is the plain-language text to show.
 */
final class LayoutBlocksRejectedException extends DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $slot,
        public readonly ?string $locale,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** Where the refusal is about, as "slot" or "slot.locale". */
    public function field(): string
    {
        return $this->locale === null ? $this->slot : "{$this->slot}.{$this->locale}";
    }
}

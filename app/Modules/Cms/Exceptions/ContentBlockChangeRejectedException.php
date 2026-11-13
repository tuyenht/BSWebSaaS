<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A change to the website's reusable blocks refused for a BUSINESS reason the caller should explain
 * in plain words. Its own type so no broad `catch (RuntimeException)` swallows it. `reason` is one
 * of: name_invalid (empty or longer than ContentBlock::NAME_MAX) · region_invalid (not one of
 * ContentBlock::REGIONS) · locale_invalid (not one of the website's languages) · body_invalid (not a
 * block list, or a block the page body check refuses; the message is that check's plain-language
 * text) · library_full (the website already keeps `cms.blocks.max_per_site` blocks) · block_missing
 * (the block is not on this website, or was deleted meanwhile) · version_stale (the block changed
 * since the screen read it: someone else saved it) · key_exhausted (no free reference code left for
 * that name).
 */
final class ContentBlockChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason, ?string $message = null)
    {
        parent::__construct($message ?? $reason);
    }
}

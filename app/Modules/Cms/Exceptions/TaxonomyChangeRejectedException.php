<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/**
 * A change to a site's categories refused for a BUSINESS reason the caller should explain in plain
 * words. Its own type so no broad `catch (RuntimeException)` swallows it. The message IS the reason
 * key, one of: taxonomy_invalid · vocabulary_invalid · name_invalid · slug_invalid · slug_taken ·
 * locale_invalid · template_invalid · parent_invalid · depth_exceeded · vocabulary_mismatch ·
 * target_invalid · has_children · has_items · section_bound · section_changed · workflow_governed ·
 * path_taken · has_scoped_members.
 */
final class TaxonomyChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

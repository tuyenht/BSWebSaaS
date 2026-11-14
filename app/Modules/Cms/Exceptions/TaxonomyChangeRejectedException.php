<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;
use Throwable;

/**
 * A change to a site's categories refused for a BUSINESS reason the caller should explain in plain
 * words. Its own type so no broad `catch (RuntimeException)` swallows it. The message IS the reason
 * key, one of: taxonomy_invalid · vocabulary_invalid · name_invalid · slug_invalid · slug_taken ·
 * locale_invalid · template_invalid · parent_invalid · depth_exceeded · vocabulary_mismatch ·
 * target_invalid · has_children · has_items · section_bound · section_changed · workflow_governed ·
 * path_taken · has_scoped_members · outside_category_limits (a member limited to some categories
 * reached outside them) · changes_member_limits (a move would change another member's limits) ·
 * description_invalid · seo_invalid · card_label_invalid · cover_invalid (not a picture of this site's library) ·
 * move_stale (an undo of a move after the tree changed since). A category's block slots add
 * slot_invalid · blocks_invalid · blocks_too_many (and locale_invalid), with the plain-language text
 * and the slot in the previous exception (LayoutBlocksRejectedException).
 */
final class TaxonomyChangeRejectedException extends DomainException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}

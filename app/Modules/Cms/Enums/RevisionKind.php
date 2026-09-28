<?php

declare(strict_types=1);

namespace App\Modules\Cms\Enums;

/**
 * Why a revision was taken — the entry_revisions_kind_check set.
 *
 *  - Baseline: the first snapshot of content that was already public before history existed
 *    (cms:snapshot-published).
 *  - Publish: the entry went public, by any road (publish, scheduler, workflow approval).
 *  - Edit: content saved while the entry is public.
 *  - Restore: an earlier revision was written back.
 */
enum RevisionKind: string
{
    case Baseline = 'baseline';
    case Publish = 'publish';
    case Edit = 'edit';
    case Restore = 'restore';
}

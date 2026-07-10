<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Exceptions;

use RuntimeException;

/**
 * Internal control-flow signal for a data-migration action's dry-run mode: thrown at the end of
 * a preview pass to force Laravel's DB::transaction() to roll back every write that pass made,
 * then caught by the same method that threw it. This guarantees a "preview" exercises the exact
 * real write path (so it never drifts from what a live run does) while persisting nothing — a
 * single behavior with no duplicated code paths, rather than a parallel read-only simulation.
 * Never escapes its owning action; if this propagates out, that is a bug, not a real error.
 */
final class DryRunRollback extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Dry run — rolling back all changes made during the preview pass.');
    }
}

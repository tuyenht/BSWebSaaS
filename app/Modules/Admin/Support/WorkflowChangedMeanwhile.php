<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use RuntimeException;

/**
 * A staged workflow change was made against a state of its section that is no longer the saved
 * one: someone else saved the section after this page last read it. Thrown inside the save
 * transaction so the whole batch rolls back; the message is plain language for the member.
 */
final class WorkflowChangedMeanwhile extends RuntimeException
{
    public static function forSection(string $label): self
    {
        return new self(__('admin.wf.save_stale', ['name' => $label]));
    }
}

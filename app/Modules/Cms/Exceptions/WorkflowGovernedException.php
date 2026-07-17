<?php

namespace App\Modules\Cms\Exceptions;

use RuntimeException;

/**
 * A straight-through publish was attempted on content an active workflow governs. Not a failure
 * of the caller so much as the gate doing its job: the move belongs to
 * ApplyEntryWorkflowTransition, which the editor's workflow panel drives. Carries a
 * plain-language message — an editor may see this.
 */
final class WorkflowGovernedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Nội dung này đang theo quy trình duyệt — hãy dùng các thao tác duyệt thay vì đăng trực tiếp.');
    }
}

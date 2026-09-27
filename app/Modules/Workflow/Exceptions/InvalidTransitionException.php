<?php

namespace App\Modules\Workflow\Exceptions;

use RuntimeException;

/**
 * The requested move is not an edge this workflow defines from the subject's current state —
 * a stale editor tab, a hand-crafted request, or a workflow reconfigured mid-edit. Carries a
 * plain-language message: it surfaces to an editor, not just a log.
 */
final class InvalidTransitionException extends RuntimeException
{
    public static function notDefined(string $transitionKey): self
    {
        return new self(__('workflow.err_transition_unknown', ['transition' => $transitionKey]));
    }

    public static function wrongOrigin(string $transitionKey, string $currentState): self
    {
        return new self(__('workflow.err_transition_wrong_state', ['transition' => $transitionKey, 'state' => $currentState]));
    }

    public static function noteRequired(string $transitionKey): self
    {
        return new self(__('workflow.err_note_required', ['transition' => $transitionKey]));
    }
}

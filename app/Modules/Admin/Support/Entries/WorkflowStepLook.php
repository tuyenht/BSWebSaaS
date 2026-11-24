<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Workflow\Models\WorkflowState;

/**
 * How a step of a content workflow looks wherever a post's place in it is drawn («Hộp duyệt»): the
 * colour of its dot and the icon on its node, read from the workflow's own configuration.
 *
 * The colour follows the step's key, as the design's status table names them (content-workflow.js
 * `UI`); a step a website named itself falls back by what the step does: going live reads as
 * published, the first step as a draft, a closing one as taken down, and any other review step as
 * «Đang phản biện» (the design's `UI._step`). The tone is a status of <x-bs-admin::status-pill>, so the
 * one table of status colours stays in that component.
 *
 * The icon follows what the step does (the design's `stepIcon`): live, first, closing, or a review.
 */
final class WorkflowStepLook
{
    /** Step keys the design gives a colour of their own (the status pill's tones of the same name). */
    private const array KEYED = ['published', 'scheduled', 'pending', 'reviewing', 'draft', 'private', 'archived'];

    public static function tone(?WorkflowState $state, string $key): string
    {
        return match (true) {
            in_array($key, self::KEYED, true) => $key,
            $state?->is_published === true => 'published',
            $state?->is_initial === true => 'draft',
            $state?->is_terminal === true => 'archived',
            default => 'reviewing',
        };
    }

    public static function icon(?WorkflowState $state): string
    {
        return match (true) {
            $state?->is_published === true => 'ri-global',
            $state?->is_initial === true => 'ri-draft',
            $state?->is_terminal === true => 'ri-archive',
            default => 'ri-user-search',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowState;
use App\Modules\Workflow\Models\WorkflowTransition;
use App\Modules\Workflow\Services\WorkflowEngine;

/**
 * The card above a post list that tells a member where they stand: their highest role, how the
 * section publishes (straight, or through the approval workflow switched on for it) and what they
 * may do here ("Được đăng · sửa được mọi bài"). Every word comes from what the server enforces — the
 * member's permissions, their category limits and the section's active workflow — never from the
 * role's name, so a custom role reads as truly as a built-in one.
 *
 * What a member may do through a workflow is named by the workflow's own first step out of its
 * starting stage ("Gửi biên tập" → "Được gửi biên tập"), so a site that renames its steps reads its
 * own words here.
 */
final class PostRoleNote
{
    /**
     * @return array{role: string, governed: bool, text: string, summary: string}
     */
    public static function for(User $user, string $sectionKey, bool $scoped): array
    {
        $workflow = app(WorkflowEngine::class)->activeFor($sectionKey);
        $governed = $workflow instanceof Workflow;

        if ($workflow instanceof Workflow) {
            $text = Cast::string(__('entries.role_governed', ['name' => $workflow->name]));
            $step = self::firstStep($workflow);
            $may = match (true) {
                $user->can('entries.approve') => __('entries.role_may_approve'),
                $step instanceof WorkflowTransition && $user->can($step->required_permission) => __('entries.role_may_step', ['step' => mb_strtolower(mb_substr($step->label, 0, 1)).mb_substr($step->label, 1)]),
                default => __('entries.role_view_only'),
            };
        } else {
            $text = Cast::string(__('entries.role_direct'));
            $may = match (true) {
                $user->can('entries.publish') => __('entries.role_may_publish'),
                $user->can('entries.submit') => __('entries.role_may_submit'),
                default => __('entries.role_view_only'),
            };
        }

        $edit = match (true) {
            $user->can('entries.edit.any') => $scoped ? __('entries.role_edit_any_scoped') : __('entries.role_edit_any'),
            $user->can('entries.edit.own') => $scoped ? __('entries.role_edit_own_scoped') : __('entries.role_edit_own'),
            default => __('entries.role_edit_none'),
        };

        return [
            'role' => RoleLabel::topOf($user) ?? Cast::string(__('entries.role_member')),
            'governed' => $governed,
            'text' => $text,
            'summary' => Cast::string($may).' · '.Cast::string($edit),
        ];
    }

    /** The workflow's first step out of its starting stage that does not publish (null: it has none). */
    private static function firstStep(Workflow $workflow): ?WorkflowTransition
    {
        $states = $workflow->states()->get(['key', 'is_initial', 'is_published'])->keyBy('key');
        $initial = $states->first(fn (WorkflowState $state): bool => $state->is_initial);
        if (! $initial instanceof WorkflowState) {
            return null;
        }

        return $workflow->transitions()->where('from_state', $initial->key)->orderBy('id')->get()
            ->first(function (WorkflowTransition $step) use ($states): bool {
                $to = $states->get($step->to_state);

                return $to instanceof WorkflowState && ! $to->is_published;
            });
    }
}

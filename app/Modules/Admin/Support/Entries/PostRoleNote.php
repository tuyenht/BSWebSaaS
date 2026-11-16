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
 * What a member may do through a workflow is read from the steps the workflow lets them take, so a
 * site that renames its steps or guards them with its own permissions reads its own words here.
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
            $may = self::throughWorkflow($workflow, $user)
                ?? ($user->can('entries.edit.any') || $user->can('entries.edit.own') ? __('entries.role_no_step') : __('entries.role_view_only'));
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

    /**
     * What the member may move through the workflow, in the workflow's own terms: publishing out of a
     * later stage reads "Được duyệt đăng"; out of the starting stage, publishing reads "Được đăng" and
     * any other step its own label ("Gửi biên tập" → "Được gửi biên tập"). Each step is allowed by its
     * own required permission, exactly as the engine checks it (null: no step is theirs).
     */
    private static function throughWorkflow(Workflow $workflow, User $user): ?string
    {
        $states = $workflow->states()->get(['key', 'is_initial', 'is_published'])->keyBy('key');
        $initial = $states->first(fn (WorkflowState $state): bool => $state->is_initial);
        $published = fn (WorkflowTransition $step): bool => ($states->get($step->to_state)?->is_published) === true;
        $mine = $workflow->transitions()->orderBy('id')->get()
            ->filter(fn (WorkflowTransition $step): bool => $user->can($step->required_permission));

        if ($mine->contains(fn (WorkflowTransition $step): bool => $published($step) && $step->from_state !== $initial?->key)) {
            return Cast::string(__('entries.role_may_approve'));
        }
        $first = $mine->where('from_state', $initial?->key);
        if ($first->contains($published)) {
            return Cast::string(__('entries.role_may_publish'));
        }
        $step = $first->first();
        if (! $step instanceof WorkflowTransition) {
            return null;
        }

        return Cast::string(__('entries.role_may_step', ['step' => mb_strtolower(mb_substr($step->label, 0, 1)).mb_substr($step->label, 1)]));
    }
}

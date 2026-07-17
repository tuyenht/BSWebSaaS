<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Registry;

/**
 * The platform-curated approval templates (design.md §15.4). A template is pure data: states +
 * transitions with permission guards. `InstantiateWorkflow` materializes one into rows for a
 * site, and a site may then edit those rows — the template is only the starting point, so a
 * change here never rewrites a site that already customized its flow.
 *
 * Every template obeys the engine's invariants (checked by test): exactly one `is_initial` state,
 * at least one `is_published`, and every transition endpoint is a defined state of the same
 * template. Guards are permissions already in PermissionRegistry (entries.submit/review/approve).
 *
 * The `template` values line up with the workflows.template CHECK: 'none' means no workflow, so
 * it is intentionally absent here.
 */
final class WorkflowTemplateRegistry
{
    /**
     * @return array<string, array{
     *     name: string,
     *     states: list<array{key: string, label: string, is_initial?: bool, is_published?: bool, is_terminal?: bool}>,
     *     transitions: list<array{key: string, label: string, from: string, to: string, permission: string, note?: bool}>,
     * }>
     */
    public static function all(): array
    {
        return [
            // Single gate: a submitter parks it, an approver publishes. The lightest workflow that
            // is still more than tier-1 — one explicit review before the public sees anything.
            'admin_approval' => [
                'name' => 'Duyệt trước khi đăng',
                'states' => [
                    ['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true],
                    ['key' => 'pending', 'label' => 'Chờ duyệt'],
                    ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true],
                    ['key' => 'archived', 'label' => 'Lưu trữ', 'is_terminal' => true],
                ],
                'transitions' => [
                    ['key' => 'submit', 'label' => 'Gửi duyệt', 'from' => 'draft', 'to' => 'pending', 'permission' => 'entries.submit'],
                    ['key' => 'approve', 'label' => 'Duyệt đăng', 'from' => 'pending', 'to' => 'published', 'permission' => 'entries.approve'],
                    ['key' => 'reject', 'label' => 'Trả lại', 'from' => 'pending', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'retract', 'label' => 'Gỡ bài', 'from' => 'published', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'archive', 'label' => 'Lưu trữ', 'from' => 'published', 'to' => 'archived', 'permission' => 'entries.approve'],
                ],
            ],

            // Newsroom flow (design.md §15.4 phóng viên→biên tập→giám đốc): author writes, editor
            // reviews, an approver signs off. Separates who may edit from who may approve.
            'editorial' => [
                'name' => 'Quy trình biên tập',
                'states' => [
                    ['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true],
                    ['key' => 'pending', 'label' => 'Chờ biên tập'],
                    ['key' => 'reviewing', 'label' => 'Đang phản biện'],
                    ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true],
                    ['key' => 'archived', 'label' => 'Lưu trữ', 'is_terminal' => true],
                ],
                'transitions' => [
                    ['key' => 'submit', 'label' => 'Gửi biên tập', 'from' => 'draft', 'to' => 'pending', 'permission' => 'entries.submit'],
                    ['key' => 'to_review', 'label' => 'Chuyển phản biện', 'from' => 'pending', 'to' => 'reviewing', 'permission' => 'entries.review'],
                    ['key' => 'approve', 'label' => 'Duyệt đăng', 'from' => 'reviewing', 'to' => 'published', 'permission' => 'entries.approve'],
                    ['key' => 'request_changes', 'label' => 'Yêu cầu sửa', 'from' => 'reviewing', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'send_back', 'label' => 'Trả lại tác giả', 'from' => 'pending', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'retract', 'label' => 'Gỡ bài', 'from' => 'published', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'archive', 'label' => 'Lưu trữ', 'from' => 'published', 'to' => 'archived', 'permission' => 'entries.approve'],
                ],
            ],
        ];
    }

    /**
     * The template keys an operator may pick (workflows.template minus the sentinel 'none').
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}

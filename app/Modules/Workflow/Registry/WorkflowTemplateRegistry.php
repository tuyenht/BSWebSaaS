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
 *
 * Names and labels are lang/*.json keys: localized() resolves them for one language — the site's
 * default when a template is copied into a site's rows (a site's stages then read in its own
 * language and stay editable), the reader's for a screen that previews the templates.
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
                'name' => 'workflow.template_admin_approval',
                'states' => [
                    ['key' => 'draft', 'label' => 'workflow.state_draft', 'is_initial' => true],
                    ['key' => 'pending', 'label' => 'workflow.state_pending'],
                    ['key' => 'published', 'label' => 'workflow.state_published', 'is_published' => true],
                    ['key' => 'archived', 'label' => 'workflow.state_archived', 'is_terminal' => true],
                ],
                'transitions' => [
                    ['key' => 'submit', 'label' => 'workflow.action_submit', 'from' => 'draft', 'to' => 'pending', 'permission' => 'entries.submit'],
                    ['key' => 'approve', 'label' => 'workflow.action_approve', 'from' => 'pending', 'to' => 'published', 'permission' => 'entries.approve'],
                    ['key' => 'reject', 'label' => 'workflow.action_reject', 'from' => 'pending', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'retract', 'label' => 'workflow.action_retract', 'from' => 'published', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'archive', 'label' => 'workflow.action_archive', 'from' => 'published', 'to' => 'archived', 'permission' => 'entries.approve'],
                ],
            ],

            // Newsroom flow (design.md §15.4 phóng viên→biên tập→giám đốc): author writes, editor
            // reviews, an approver signs off. Separates who may edit from who may approve.
            'editorial' => [
                'name' => 'workflow.template_editorial',
                'states' => [
                    ['key' => 'draft', 'label' => 'workflow.state_draft', 'is_initial' => true],
                    ['key' => 'pending', 'label' => 'workflow.state_pending_edit'],
                    ['key' => 'reviewing', 'label' => 'workflow.state_reviewing'],
                    ['key' => 'published', 'label' => 'workflow.state_published', 'is_published' => true],
                    ['key' => 'archived', 'label' => 'workflow.state_archived', 'is_terminal' => true],
                ],
                'transitions' => [
                    ['key' => 'submit', 'label' => 'workflow.action_submit_edit', 'from' => 'draft', 'to' => 'pending', 'permission' => 'entries.submit'],
                    ['key' => 'to_review', 'label' => 'workflow.action_to_review', 'from' => 'pending', 'to' => 'reviewing', 'permission' => 'entries.review'],
                    ['key' => 'approve', 'label' => 'workflow.action_approve', 'from' => 'reviewing', 'to' => 'published', 'permission' => 'entries.approve'],
                    ['key' => 'request_changes', 'label' => 'workflow.action_request_changes', 'from' => 'reviewing', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'send_back', 'label' => 'workflow.action_send_back', 'from' => 'pending', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'retract', 'label' => 'workflow.action_retract', 'from' => 'published', 'to' => 'draft', 'permission' => 'entries.review', 'note' => true],
                    ['key' => 'archive', 'label' => 'workflow.action_archive', 'from' => 'published', 'to' => 'archived', 'permission' => 'entries.approve'],
                ],
            ],
        ];
    }

    /**
     * Every template with its name and labels resolved in $locale.
     *
     * @return array<string, array{
     *     name: string,
     *     states: list<array{key: string, label: string, is_initial?: bool, is_published?: bool, is_terminal?: bool}>,
     *     transitions: list<array{key: string, label: string, from: string, to: string, permission: string, note?: bool}>,
     * }>
     */
    public static function localized(string $locale): array
    {
        $text = static fn (string $key): string => (string) __($key, [], $locale);

        return array_map(static fn (array $template): array => [
            ...$template,
            'name' => $text($template['name']),
            'states' => array_map(static fn (array $state): array => [...$state, 'label' => $text($state['label'])], $template['states']),
            'transitions' => array_map(static fn (array $transition): array => [...$transition, 'label' => $text($transition['label'])], $template['transitions']),
        ], self::all());
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

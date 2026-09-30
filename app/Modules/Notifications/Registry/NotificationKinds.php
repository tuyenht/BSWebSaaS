<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Registry;

/**
 * Every kind of in-app notification, with its default channels (design.md §15, decision Q5). The
 * `site_notifications.kind` CHECK lists the same keys — a test keeps them in step. Adding a kind =
 * a row here + the CHECK in a migration.
 *
 *  - in_app / mail: what a person gets until they choose otherwise (users.preferences).
 *  - locked: security notices cannot be switched off — someone changing your account must not be
 *    able to silence the warning first.
 *  - mail is only honoured where the kind's sender can mail (security notices, today).
 */
final class NotificationKinds
{
    /**
     * @return array<string, array{group: string, in_app: bool, mail: bool, locked: bool}>
     */
    public static function all(): array
    {
        return [
            'workflow.assigned' => ['group' => 'workflow', 'in_app' => true, 'mail' => false, 'locked' => false],
            'workflow.returned' => ['group' => 'workflow', 'in_app' => true, 'mail' => false, 'locked' => false],
            'workflow.published' => ['group' => 'workflow', 'in_app' => true, 'mail' => false, 'locked' => false],
            'workflow.pending_review' => ['group' => 'workflow', 'in_app' => true, 'mail' => false, 'locked' => false],
            'lead.new' => ['group' => 'lead', 'in_app' => true, 'mail' => false, 'locked' => false],
            'security.new_device_login' => ['group' => 'security', 'in_app' => true, 'mail' => true, 'locked' => true],
            'security.two_factor_disabled' => ['group' => 'security', 'in_app' => true, 'mail' => true, 'locked' => true],
            'security.support_sign_in' => ['group' => 'security', 'in_app' => true, 'mail' => true, 'locked' => true],
            'system.storage_near_limit' => ['group' => 'system', 'in_app' => true, 'mail' => true, 'locked' => false],
        ];
    }

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::all());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Notifications\Models\SiteNotification;
use Illuminate\Support\Str;

/**
 * Where a notification in the bell leads, and the words on its link ("Mở bài", "Xem liên hệ"…).
 * Built from the facts the notification stores — the subject's ulid, never a bigint id — and only
 * to screens that exist; a kind with nowhere to go (the support sign-in notice: no activity log
 * on the website's admin yet) has no link and is only marked read.
 */
final class NotificationLink
{
    /**
     * @return array{url: string, act: string}|null
     */
    public static function for(SiteNotification $notification): ?array
    {
        $data = $notification->data;
        $entry = self::ulid($data['entry'] ?? null);

        return match ($notification->kind) {
            'workflow.pending_review' => ($data['change'] ?? false) === true
                ? self::to(route('admin.entries.review', absolute: false), 'review')
                : ($entry !== null ? self::to(route('admin.entries.edit', ['ulid' => $entry], false), 'open_entry') : null),
            'workflow.assigned', 'workflow.published' => $entry !== null
                ? self::to(route('admin.entries.edit', ['ulid' => $entry], false), 'open_entry') : null,
            'workflow.returned' => $entry !== null
                ? self::to(route('admin.entries.edit', ['ulid' => $entry], false), 'edit_entry') : null,
            'lead.new' => ($lead = self::ulid($data['submission'] ?? null)) !== null
                ? self::to(route('admin.forms.show', ['ulid' => $lead], false), 'lead') : null,
            'system.domain_problem', 'security.domain_taken_over' => self::to(route('admin.settings.domains', absolute: false), 'domains'),
            'security.two_factor_disabled' => self::to(route('admin.settings.security', absolute: false), 'two_factor'),
            'security.new_device_login' => self::to(route('admin.settings.security', absolute: false), 'devices'),
            'system.storage_near_limit' => self::to(route('admin.media.index', absolute: false), 'media'),
            default => null,
        };
    }

    /** @return array{url: string, act: string} */
    private static function to(string $url, string $act): array
    {
        return ['url' => $url, 'act' => __('admin.bell.act.'.$act)];
    }

    private static function ulid(mixed $value): ?string
    {
        return is_string($value) && Str::isUlid($value) ? $value : null;
    }
}

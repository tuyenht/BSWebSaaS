<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Registry\NotificationKinds;
use App\Modules\Tenancy\Context\SiteTime;
use Illuminate\Support\Collection;

/**
 * Notifications as the bell's panel and the Notifications page draw them (design 01 .ntf-*,
 * c-thong-bao .tb-*): grouped by the site's day ("Hôm nay", "Hôm qua", then the date), newest first,
 * each with its sentence, round icon and colour, how long ago, and where it leads.
 */
final class NotificationRows
{
    /**
     * @param  Collection<int, SiteNotification>  $rows
     * @return list<array{label: string, items: list<array{ulid: string, kind: string, group: string, text: string, note: string, icon: string, tone: string, ago: string, unread: bool, url: string|null, act: string|null}>}>
     */
    public static function days(Collection $rows): array
    {
        $time = app(SiteTime::class);
        $locale = app()->getLocale();
        $now = $time->now();
        $kinds = NotificationKinds::all();
        $days = [];

        foreach ($rows as $row) {
            $at = $time->forDisplay($row->created_at) ?? $now;
            $label = match (true) {
                $at->isSameDay($now) => __('admin.bell.today'),
                $at->isSameDay($now->subDay()) => __('admin.bell.yesterday'),
                default => $time->day($row->created_at, $locale),
            };
            $minutes = (int) floor($at->diffInMinutes($now, true));
            $link = NotificationLink::for($row);
            [$icon, $tone] = self::look($row);
            $note = $row->data['note'] ?? '';

            $item = [
                'ulid' => $row->ulid,
                'kind' => $row->kind,
                'group' => $kinds[$row->kind]['group'] ?? 'system',
                'text' => $row->summary(),
                // The reviewer's reason when a post is sent back ("Lý do: …" under the sentence).
                'note' => $row->kind === 'workflow.returned' && is_string($note) ? trim($note) : '',
                'icon' => $icon,
                'tone' => $tone,
                'ago' => match (true) {
                    $minutes < 1 => __('admin.bell.just_now'),
                    $minutes < 60 => __('admin.bell.minutes', ['count' => $minutes]),
                    $at->isSameDay($now) => $at->format('H:i'),
                    default => $label.' · '.$at->format('H:i'),
                },
                'unread' => $row->read_at === null,
                'url' => $link['url'] ?? null,
                'act' => $link['act'] ?? null,
            ];

            $last = array_key_last($days);
            if ($last !== null && $days[$last]['label'] === $label) {
                $days[$last]['items'][] = $item;
            } else {
                $days[] = ['label' => $label, 'items' => [$item]];
            }
        }

        return $days;
    }

    /**
     * The round icon of a row and its colour, as the boards draw each kind.
     *
     * @return array{0: string, 1: string}
     */
    private static function look(SiteNotification $row): array
    {
        $sentence = $row->data['sentence'] ?? null;

        return match ($row->kind) {
            'workflow.pending_review' => ['ri-git-pull-request', 'brand'],
            'workflow.assigned' => ['ri-user-received-2', 'brand'],
            'workflow.returned' => ['ri-arrow-go-back', 'warn'],
            'workflow.published' => ['ri-global', 'ok'],
            'lead.new' => ['ri-mail', 'ok'],
            'security.new_device_login' => ['ri-device', 'danger'],
            'security.two_factor_disabled' => ['ri-shield-cross', 'danger'],
            'security.support_sign_in' => ['ri-user-shared', 'danger'],
            'security.domain_taken_over' => ['ri-error-warning', 'danger'],
            'system.storage_near_limit' => ['ri-hard-drive-3', 'warn'],
            'system.domain_problem' => ['ri-global', $sentence === 'recovered' ? 'ok' : 'danger'],
            default => ['ri-information', 'info'],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Notifications\Channels\SiteDatabaseChannel;
use App\Modules\Notifications\Contracts\ToSiteDatabase;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Security notice to each owner of a website: platform staff just signed in as one of its members
 * (design.md v2.34) — who, as whom, when, and why. In the bell AND by mail, and it cannot be
 * switched off (NotificationKinds: locked). Queued like the other tenant mails.
 */
final class SupportSignInNotification extends Notification implements ShouldQueue, ToSiteDatabase
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    public function __construct(
        private readonly string $staff,
        private readonly string $member,
        private readonly string $reason,
        private readonly string $at,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function failed(Throwable $e): void
    {
        Audit::record('support.session.notice_failed', [
            'meta' => ['site_id' => $this->tenantSiteId, 'error' => $e::class],
        ]);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [SiteDatabaseChannel::class, 'mail'];
    }

    public function toSiteDatabase(object $notifiable): array
    {
        return ['kind' => 'security.support_sign_in', 'data' => $this->facts()];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();

        return (new MailMessage)
            ->subject(__('notifications.mail.support_sign_in_subject'))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__('notifications.mail.support_sign_in_intro', array_map(MailText::plain(...), $this->facts())))
            ->line(__('notifications.mail.support_sign_in_action'));
    }

    /**
     * @return array{staff: string, platform: string, member: string, reason: string, at: string}
     */
    private function facts(): array
    {
        return [
            'staff' => $this->staff,
            'platform' => Cast::string(config('admin.brand.name')),
            'member' => $this->member,
            'reason' => $this->reason,
            'at' => $this->at,
        ];
    }
}

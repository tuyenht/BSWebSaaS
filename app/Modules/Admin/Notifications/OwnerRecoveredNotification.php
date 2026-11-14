<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Security notice by mail to every owner a website had, and to its new owner: the platform gave the
 * website an owner again (site:recover-owner) — who, when, and why. Mail only: the owners it is
 * meant for may be the ones who cannot sign in. Queued like the other tenant mails.
 */
final class OwnerRecoveredNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    public function __construct(
        private readonly string $site,
        private readonly string $email,
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
        Audit::record('rbac.owner.recovered_notice_failed', [
            'site_id' => $this->tenantSiteId,
            'meta' => ['error' => $e::class],
        ]);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();

        return (new MailMessage)
            ->subject(__('notifications.mail.owner_recovered_subject'))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__('notifications.mail.owner_recovered_intro', array_map(MailText::plain(...), $this->facts())))
            ->line(__('notifications.mail.owner_recovered_action'));
    }

    /**
     * @return array{platform: string, site: string, email: string, reason: string, at: string}
     */
    public function facts(): array
    {
        return [
            'platform' => Cast::string(config('admin.brand.name')),
            'site' => $this->site,
            'email' => $this->email,
            'reason' => $this->reason,
            'at' => $this->at,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Notifications\Channels\SiteDatabaseChannel;
use App\Modules\Notifications\Contracts\ToSiteDatabase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Security notice: two-step sign-in was just switched off on the account. It goes to the account
 * itself — in the bell AND by mail, and cannot be switched off (NotificationKinds: locked) — so
 * someone who took over a session cannot quietly weaken the account without its owner hearing.
 *
 * Queued like the other tenant mails (WithinSite isolation + re-applied tenant mailer); the
 * in-app row is written by the worker inside that same site context.
 */
final class TwoFactorDisabledNotification extends Notification implements ShouldQueue, ToSiteDatabase
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    public function __construct(private readonly string $disabledAt) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function failed(Throwable $e): void
    {
        Audit::record('auth.2fa.disabled_notice_failed', [
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
        return ['kind' => 'security.two_factor_disabled', 'data' => ['at' => $this->disabledAt]];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();

        return (new MailMessage)
            ->subject(__('notifications.mail.two_factor_disabled_subject'))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__('notifications.mail.two_factor_disabled_intro', ['at' => $this->disabledAt]))
            ->line(__('notifications.mail.two_factor_disabled_action'));
    }
}

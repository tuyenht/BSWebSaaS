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
 * Security notice: two-step sign-in was just switched off on the account — or, with $turnedOn,
 * just switched on (a new sign-in factor). It goes to the account itself — in the bell AND by
 * mail, and cannot be switched off (NotificationKinds: locked) — so someone who took over a
 * session cannot quietly change how the account signs in without its owner hearing. Both read
 * the one locked kind `security.two_factor_disabled`; switching on reads its `enabled` sentence.
 *
 * Queued like the other tenant mails (WithinSite isolation + re-applied tenant mailer); the
 * in-app row is written by the worker inside that same site context.
 */
final class TwoFactorDisabledNotification extends Notification implements ShouldQueue, ToSiteDatabase
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    public function __construct(
        private readonly string $disabledAt,
        private readonly bool $turnedOn = false,
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
        Audit::record($this->turnedOn ? 'auth.2fa.enabled_notice_failed' : 'auth.2fa.disabled_notice_failed', [
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
        $sentence = $this->turnedOn ? ['sentence' => 'enabled'] : [];

        return ['kind' => 'security.two_factor_disabled', 'data' => [...$sentence, 'at' => $this->disabledAt]];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();

        $mail = $this->turnedOn ? 'two_factor_enabled' : 'two_factor_disabled';

        return (new MailMessage)
            ->subject(__("notifications.mail.{$mail}_subject"))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__("notifications.mail.{$mail}_intro", ['at' => $this->disabledAt]))
            ->line(__("notifications.mail.{$mail}_action"));
    }
}

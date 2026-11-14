<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Errors\SupportChannels;
use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Audit\Facades\Audit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Security notice to the OLD address once the sign-in email changed: when, the new address
 * masked, and how to reach support if it was not the member. Mail only (no bell row) and sent on
 * demand to the old address, so no notification choice can turn it off and the account's new
 * address never receives it in the old one's place.
 */
final class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    public ?int $actorId = null;

    public function __construct(
        private readonly string $changedAt,
        private readonly string $maskedNewEmail,
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
        Audit::record('identity.email.notice_failed', [
            'actor_id' => $this->actorId,
            'meta' => ['site_id' => $this->tenantSiteId, 'error' => $e::class],
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
        $support = SupportChannels::all();
        $contact = implode(' · ', array_filter([$support['email'], $support['phone']], static fn (string $way): bool => $way !== ''));

        return (new MailMessage)
            ->subject(__('notifications.mail.email_changed_subject'))
            ->greeting(__('rbac.invitation.mail_greeting'))
            ->line(__('notifications.mail.email_changed_intro', ['email' => $this->maskedNewEmail, 'at' => $this->changedAt]))
            ->line($contact !== ''
                ? __('notifications.mail.email_changed_action', ['contact' => $contact])
                : __('notifications.mail.email_changed_action_admin'));
    }
}

<?php

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Admin\Support\Cast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the email second-factor OTP — branded + i18n. Queued (ShouldQueue) so the login
 * response never blocks on SMTP; the worker re-enters the sending site's isolation (WithinSite)
 * and re-applies the resolved mailer before building the message (see QueuesTenantMail).
 */
final class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public function __construct(
        private readonly string $code,
        private readonly int $minutes,
    ) {}

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
            ->subject(__('auth.otp_email_subject'))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('auth.otp_email_intro'))
            ->line('# '.$this->code)
            ->line(__('auth.otp_email_outro', ['minutes' => $this->minutes]));
    }
}

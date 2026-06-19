<?php

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Support\Cast;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Delivers the email second-factor OTP — branded + i18n, sent via the resolved tenant
 * mailer (the caller configures the mailer before dispatch).
 */
final class EmailOtpNotification extends Notification
{
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
        return (new MailMessage)
            ->subject(__('auth.otp_email_subject'))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('auth.otp_email_intro'))
            ->line('# '.$this->code)
            ->line(__('auth.otp_email_outro', ['minutes' => $this->minutes]));
    }
}

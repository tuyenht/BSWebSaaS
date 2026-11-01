<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A website owner asks the support team to point a domain for them (design.md §28, "Nhờ hỗ trợ
 * trỏ giúp"). Goes to the support mailbox on the platform's own mail account: it is sent with no
 * site, so the worker never uses the customer's mail settings. A reply reaches the member who
 * asked.
 */
final class DomainHelpRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    /**
     * @param  list<array{label: string, kind: string, target: string}>  $records
     */
    public function __construct(
        public readonly string $site,
        public readonly string $domain,
        public readonly string $memberName,
        public readonly string $memberEmail,
        public readonly array $records,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->applyTenantMailer();
        $facts = ['site' => $this->site, 'domain' => $this->domain, 'name' => $this->memberName, 'email' => $this->memberEmail];

        $mail = (new MailMessage)
            ->subject(__('notifications.mail.domain_help_subject', $facts))
            ->replyTo($this->memberEmail, $this->memberName)
            ->line(__('notifications.mail.domain_help_intro', $facts))
            ->line(__('notifications.mail.domain_help_records'));

        foreach ($this->records as $record) {
            $mail->line($record['label'].' · '.$record['kind'].' → '.$record['target']);
        }

        return $mail->line(__('notifications.mail.domain_help_action', $facts));
    }
}

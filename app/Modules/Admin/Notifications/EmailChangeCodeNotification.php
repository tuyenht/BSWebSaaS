<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Shared\Support\Cast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * A code of a sign-in email change (EmailChange): to the current address when it is the change's
 * second step (no authenticator app), or to the new address to prove the member reads it. Sent
 * on demand to the address captured when the code was made, never through the account, so a
 * queued mail cannot follow the account to another address. Queued and retried like the sign-in
 * code (EmailOtpNotification); a final failure goes on record.
 */
final class EmailChangeCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    /** Actor captured at dispatch, for a worker-side failed() that has no request. */
    public ?int $actorId = null;

    public function __construct(
        private readonly string $code,
        private readonly int $minutes,
        private readonly bool $toNewAddress,
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
        Audit::record('identity.email.code_send_failed', [
            'actor_id' => $this->actorId,
            'meta' => ['site_id' => $this->tenantSiteId, 'error' => $e::class, 'to' => $this->toNewAddress ? 'new' : 'current'],
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
        $which = $this->toNewAddress ? 'new' : 'current';

        return (new MailMessage)
            ->subject(__("notifications.mail.email_change_{$which}_subject"))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__("notifications.mail.email_change_{$which}_intro"))
            ->line('# '.$this->code)
            ->line(__("notifications.mail.email_change_{$which}_outro", ['minutes' => $this->minutes]));
    }
}

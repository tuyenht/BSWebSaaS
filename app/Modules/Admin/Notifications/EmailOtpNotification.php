<?php

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Admin\Support\Cast;
use App\Modules\Audit\Facades\Audit;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Delivers the email second-factor OTP — branded + i18n. Queued (ShouldQueue) so the login
 * response never blocks on SMTP; the worker re-enters the sending site's isolation (WithinSite)
 * and re-applies the resolved mailer before building the message (see QueuesTenantMail).
 *
 * Because delivery is deferred to a worker (Horizon runs tries=1), a transient SMTP/worker blip
 * would otherwise silently lose a login code. So retry a few times with short backoffs, but never
 * past the code's own validity window (mailing an expired code is pointless), and audit a final
 * failure so an undelivered code is visible.
 */
final class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    public function __construct(
        private readonly string $code,
        private readonly int $minutes,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function retryUntil(): DateTimeInterface
    {
        // Evaluated once at dispatch and stored on the payload — so this is an absolute deadline
        // (dispatch time + the code's TTL), not a moving one.
        return now()->addMinutes($this->minutes);
    }

    public function failed(Throwable $e): void
    {
        // Best-effort (CLAUDE.md §2.6): record the class only, never the message (may carry SMTP detail).
        Audit::record('auth.otp.send_failed', ['meta' => ['error' => $e::class]]);
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
            ->subject(__('auth.otp_email_subject'))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('auth.otp_email_intro'))
            ->line('# '.$this->code)
            ->line(__('auth.otp_email_outro', ['minutes' => $this->minutes]));
    }
}

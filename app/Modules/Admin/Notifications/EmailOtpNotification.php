<?php

namespace App\Modules\Admin\Notifications;

use App\Modules\Admin\Notifications\Concerns\QueuesTenantMail;
use App\Modules\Admin\Support\Cast;
use App\Modules\Audit\Facades\Audit;
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
 * would otherwise silently lose a login code. So retry a few times with short backoffs (which finish
 * well inside the code's TTL), and audit a final failure so an undelivered code is visible.
 */
final class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    /**
     * Actor captured at dispatch so a worker-side failed() — which has no request/Auth — can still
     * attribute the undelivered code (the site comes from the trait's tenantSiteId).
     */
    public ?int $actorId = null;

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

    // Deliberately NO retryUntil(): when both are defined Laravel's retryUntil WINS and $tries is
    // ignored, so a deadline == the 10-min TTL would retry every ~20s (~30 SMTP attempts). $tries +
    // backoff finish in ~25s — far inside the TTL — so a stale code is never mailed anyway.

    public function failed(Throwable $e): void
    {
        // Best-effort (CLAUDE.md §2.6): attribute to actor + site (the worker has no Auth); class only,
        // never the message (may carry SMTP detail — the full exception lands in failed_jobs).
        Audit::record('auth.otp.send_failed', [
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

        return (new MailMessage)
            ->subject(__('auth.otp_email_subject'))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('auth.otp_email_intro'))
            ->line('# '.$this->code)
            ->line(__('auth.otp_email_outro', ['minutes' => $this->minutes]));
    }
}

<?php

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
 * Delivers the password-reset link — branded + i18n, queued (ShouldQueue) so the forgot-password
 * reply returns in constant time whether or not the address exists: a synchronous SMTP send is a
 * timing oracle for account existence even when the response body is uniform.
 *
 * The reset URL is composed AT DISPATCH, inside the request — a queue worker has no request host,
 * so it could pick neither the right plane (admin ↔ platform route names) nor the right absolute
 * host for the link. Worker plumbing mirrors EmailOtpNotification: WithinSite isolation +
 * re-applied tenant mailer + bounded retries + audited final failure.
 */
final class ResetPasswordLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use QueuesTenantMail;

    public int $tries = 3;

    /** Requester captured at dispatch — a worker-side failed() has no request/Auth to ask. */
    public ?int $actorId = null;

    public function __construct(private readonly string $resetUrl) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 20];
    }

    public function failed(Throwable $e): void
    {
        // Best-effort (CLAUDE.md §2.6): class only, never the message — the full exception
        // lands in failed_jobs; SMTP detail must not leak into the audit trail.
        Audit::record('auth.password.reset_send_failed', [
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
            ->subject(__('auth.reset_email_subject'))
            ->greeting(Cast::string(config('admin.brand.name')))
            ->line(__('auth.reset_email_intro'))
            ->action(__('auth.reset_password'), $this->resetUrl)
            ->line(__('auth.reset_email_outro'));
    }
}

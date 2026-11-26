<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications\Concerns;

use App\Modules\Admin\Support\Mail\MailLogWriter;
use App\Modules\Admin\Support\Mail\MailQuota;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Jobs\WithinSite;
use Throwable;

/**
 * Shared plumbing for a tenant notification mail that is queued (ShouldQueue).
 *
 * A queued notification is handled by a worker that has NONE of the request's live
 * state — in particular TenantMailer::apply() ran against the request's config repo,
 * which the worker never sees. So the worker must, before it sends:
 *   1. re-enter the sending site's isolation (CurrentSite + RLS app.current_site_id),
 *      done by returning WithinSite job middleware (design.md §5 — the single audited
 *      tenant entry point, identical to HTTP);
 *   2. re-apply the resolved mailer, done by calling applyTenantMailer() at the top of
 *      the notification's toMail(), which now runs inside that WithinSite context.
 *
 * Bind the site at DISPATCH time (in the request, where CurrentSite is resolved) with
 * forCurrentSite(). The platform plane has no site → no middleware is added and the
 * worker resolves the platform-default mailer (TenantMailer falls back tenant → platform).
 */
trait QueuesTenantMail
{
    /** Site this mail is sent for; null on the platform plane (operator console). */
    public ?int $tenantSiteId = null;

    /**
     * Capture the resolved site from the current request so the worker can re-enter it.
     * Fluent for dispatch: $user->notify((new X(...))->forCurrentSite()).
     */
    public function forCurrentSite(): static
    {
        $current = app(CurrentSite::class);
        $this->tenantSiteId = $current->hasId() ? $current->id() : null;

        return $this;
    }

    /**
     * The channel the queued job carrying this copy sends on. Laravel queues one job per channel,
     * each with its own copy of the notification, and asks that copy for its middleware with the
     * channel (NotificationSender::queueNotification): noted there, so failed() — told only the
     * exception — knows whether a mail failed or the bell. Null on a job queued before it was noted.
     */
    public ?string $queuedChannel = null;

    /**
     * Job middleware for the queued send. On a tenant site, WithinSite restores the same
     * isolation an HTTP request gets (transaction + SET LOCAL app.current_site_id +
     * CurrentSite), and on the mail channel MailQuota (after it, inside the site) may hold an
     * ordinary mail back to the next day when the website's shared-outbox ceiling is reached.
     * No site (platform plane) → no middleware → platform-default mailer.
     *
     * @return array<int, object>
     */
    public function middleware(?object $notifiable = null, ?string $channel = null): array
    {
        if ($channel !== null) {
            $this->queuedChannel = $channel;
        }

        if ($this->tenantSiteId === null) {
            return [];
        }

        return $channel === 'mail'
            ? [new WithinSite($this->tenantSiteId), new MailQuota]
            : [new WithinSite($this->tenantSiteId)];
    }

    /**
     * Log a mail that failed for good in the website's «Thư gần đây» list and day count. Call from
     * the notification's failed(); best-effort, never throws. Nothing for the platform plane (no
     * website) or for a job of another channel (the bell).
     */
    protected function recordMailFailure(Throwable $e): void
    {
        if ($this->tenantSiteId === null || ($this->queuedChannel !== null && $this->queuedChannel !== 'mail')) {
            return;
        }

        app(MailLogWriter::class)->queuedFailed($this->tenantSiteId, static::class, $this->id, $e);
    }

    /**
     * Re-apply the resolved (tenant override → platform default → log) mailer onto the
     * worker's live mail config. Call at the top of toMail(), inside the WithinSite
     * context so tenant settings resolve under the right site + RLS.
     */
    protected function applyTenantMailer(): void
    {
        app(TenantMailer::class)->apply();
    }
}

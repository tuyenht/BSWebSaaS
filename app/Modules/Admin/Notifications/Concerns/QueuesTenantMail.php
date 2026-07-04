<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications\Concerns;

use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Jobs\WithinSite;

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
     * Job middleware for the queued send. On a tenant site, WithinSite restores the same
     * isolation an HTTP request gets (transaction + SET LOCAL app.current_site_id +
     * CurrentSite). No site (platform plane) → no middleware → platform-default mailer.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->tenantSiteId !== null ? [new WithinSite($this->tenantSiteId)] : [];
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

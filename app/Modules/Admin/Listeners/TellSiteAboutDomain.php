<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Notifications\DomainNoticeNotification;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

/**
 * Tells everyone who manages a website's domains (`domains.manage`, the owner included) that one
 * of its own domains needs them (design.md §28). Queued: the daily sweep and a takeover happen
 * outside the site they concern, so the site is entered here, in a worker of its own.
 */
final class TellSiteAboutDomain implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /** A notice that never went out leaves a trace on the site's trail. */
    public function failed(DomainNeedsAttention $event, Throwable $e): void
    {
        Audit::record('tenancy.domain.notice_failed', [
            'site_id' => $event->siteId,
            'actor_label' => 'system',
            'meta' => ['variant' => $event->variant, 'hostnames' => $event->hostnames, 'error' => $e::class],
        ]);
    }

    public function handle(DomainNeedsAttention $event): void
    {
        /** @var array{0: Collection<int, User>, 1: string|null} $found */
        $found = $this->tenancy->enter($event->siteId, function () use ($event): array {
            try {
                $managers = User::permission('domains.manage')->get();
            } catch (PermissionDoesNotExist) {
                $managers = new Collection;   // a site whose permissions were never synced has nobody to tell
            }

            $until = $event->until?->setTimezone(app(SiteTime::class)->zone())->format('d/m/Y');

            return [$managers, $until];
        });

        [$managers, $until] = $found;

        if ($managers->isEmpty()) {
            return;
        }

        $notice = new DomainNoticeNotification($event->variant, $event->hostnames, $until);
        $notice->tenantSiteId = $event->siteId;

        Notification::send($managers, $notice);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Notifications\DomainNoticeNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Tells everyone who manages a website's domains (`domains.manage`, the owner included) that one
 * of its own domains needs them (design.md §28). Queued: the daily sweep and a takeover happen
 * outside the site they concern, so the site is entered here, in a worker of its own.
 */
final readonly class TellSiteAboutDomain implements ShouldQueue
{
    public function __construct(private Tenancy $tenancy) {}

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

<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Channels;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Contracts\ToSiteDatabase;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Registry\NotificationKinds;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Notifications\Notification;

/**
 * Stores a notification in the reader's in-app feed on the CURRENT site. It writes inside the
 * sender's tenant transaction (RLS), so a transition that rolls back takes its notifications with
 * it; with no site context — or for an unknown kind — nothing is stored (fail-closed).
 */
final readonly class SiteDatabaseChannel
{
    public function __construct(private CurrentSite $current) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof ToSiteDatabase || ! $notifiable instanceof User || ! $this->current->hasId()) {
            return;
        }

        ['kind' => $kind, 'data' => $data] = $notification->toSiteDatabase($notifiable);
        if (! NotificationKinds::has($kind)) {
            return;
        }

        SiteNotification::query()->create([
            'user_id' => $notifiable->getKey(),
            'kind' => $kind,
            'data' => $data,
        ]);
    }
}

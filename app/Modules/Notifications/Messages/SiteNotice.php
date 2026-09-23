<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Messages;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Channels\SiteDatabaseChannel;
use App\Modules\Notifications\Contracts\ToSiteDatabase;
use App\Modules\Notifications\Support\NotificationPreferences;
use Illuminate\Notifications\Notification;

/**
 * An in-app-only notice — what content modules send (workflow steps, new leads). Sent
 * synchronously so it is written inside the sender's tenant transaction; the person's
 * preferences decide whether it is stored at all.
 */
final class SiteNotice extends Notification implements ToSiteDatabase
{
    /**
     * @param  array<string, mixed>  $data  the facts the sentence is built from
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $data,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User && NotificationPreferences::wants($notifiable, $this->kind, 'in_app')
            ? [SiteDatabaseChannel::class]
            : [];
    }

    public function toSiteDatabase(object $notifiable): array
    {
        return ['kind' => $this->kind, 'data' => $this->data];
    }
}

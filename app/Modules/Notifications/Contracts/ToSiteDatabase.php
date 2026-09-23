<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

/**
 * A notification that can be stored in the reader's in-app feed (SiteDatabaseChannel).
 */
interface ToSiteDatabase
{
    /**
     * The kind (NotificationKinds) and the facts its sentence is built from — never the finished
     * sentence: it is rendered in the reader's language when shown.
     *
     * @return array{kind: string, data: array<string, mixed>}
     */
    public function toSiteDatabase(object $notifiable): array;
}

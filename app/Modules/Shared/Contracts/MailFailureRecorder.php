<?php

declare(strict_types=1);

namespace App\Modules\Shared\Contracts;

use Throwable;

/**
 * Records a website mail that could not be sent (its «Thư gần đây» row and its day's failed count).
 * Lives in Shared\Contracts so a feature module that sends synchronously (Forms' lead notice) can
 * record its failure without depending on the Admin module that owns the mail log. Best-effort:
 * never throws, does nothing without a current website.
 */
interface MailFailureRecorder
{
    /**
     * @param  class-string  $notificationClass  the notification that failed
     * @param  list<string>  $recipients  the addresses it was for
     */
    public function mailFailed(string $notificationClass, Throwable $e, array $recipients = []): void;
}

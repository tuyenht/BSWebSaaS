<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Thrown by the MessageSending listener when a website's mail of an ordinary kind must wait for the
 * next day: past its abuse ceiling (ten times its daily limit, or no place at all when the limit is
 * 0), or the platform's own daily ceiling. Only ever thrown while the MailQuota job middleware is
 * around the send (MailSendContext::$guarded), which catches it and queues the same mail again for
 * $until; nothing has left, nothing is counted as sent.
 */
final class MailHeld extends RuntimeException
{
    /**
     * @param  'site'|'platform'  $ceiling
     */
    public function __construct(public readonly string $ceiling, public readonly CarbonImmutable $until)
    {
        parent::__construct("Mail held back until {$until->toIso8601String()} ({$ceiling} daily ceiling reached).");
    }
}

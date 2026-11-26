<?php

declare(strict_types=1);

namespace App\Modules\Shared\Events;

/**
 * A mail count crossed one of its thresholds, at most once per threshold per day (dispatched after
 * the count committed). Lives in Shared so the module that tells someone (the operator's notices in
 * Monitoring, a website's bell) needs no part of the Admin module that counts.
 *
 *  - `site_80`, `site_100`: a website's mails through the shared outbox reached 80% / 100% of its
 *    daily limit today (its day, in its time zone): its managers should connect their own outbox.
 *  - `site_abuse`: a website reached its abuse ceiling (ten times its limit); its ordinary mails
 *    wait for the next day. For the platform's operators.
 *  - `platform_90`: all websites together reached 90% of the platform's daily ceiling of the shared
 *    outbox (UTC day); `siteId` is null.
 */
final readonly class MailQuotaReached
{
    /**
     * @param  'site_80'|'site_100'|'site_abuse'|'platform_90'  $threshold
     */
    public function __construct(
        public string $threshold,
        public ?int $siteId,
        public string $day,
        public int $count,
        public int $limit,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Listeners;

use App\Modules\Monitoring\Support\NoticeMailer;
use App\Modules\Shared\Events\MailQuotaReached;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails the platform's operator, on the monitoring mailer, when a website reaches its abuse ceiling
 * of the shared outbox (its ordinary mails now wait for the next day) or when all websites together
 * reach 90% of the platform's daily ceiling. The counting side sends each at most once a day
 * (MailQuotaReached); a website's own 80% / 100% thresholds are not for the operator.
 * Never throws: the mail that crossed the threshold has been sent already.
 */
final readonly class TellOperatorAboutMailQuota
{
    public function __construct(private NoticeMailer $mailer) {}

    public function handle(MailQuotaReached $event): void
    {
        if (! in_array($event->threshold, ['site_abuse', 'platform_90'], true) || ! NoticeMailer::enabled()) {
            return;
        }

        try {
            $replace = [
                'site' => (string) $event->siteId,
                'day' => $event->day,
                'count' => $this->mailer->number($event->count),
                'limit' => $this->mailer->number($event->limit),
            ];
            $this->mailer->sendPlain(
                $this->mailer->t("monitoring.mail_quota.subject.{$event->threshold}", $replace),
                $this->mailer->t("monitoring.mail_quota.body.{$event->threshold}", $replace),
            );
        } catch (Throwable $e) {
            Log::warning('monitoring: mail quota notice could not be sent', ['threshold' => $event->threshold, 'error' => $e::class]);
        }
    }
}

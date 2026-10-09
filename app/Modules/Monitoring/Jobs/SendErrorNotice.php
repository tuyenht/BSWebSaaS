<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Jobs;

use App\Modules\Monitoring\Support\ErrorEvent;
use App\Modules\Monitoring\Support\NoticeMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one error notice, off the request path: a send waits on the mail host (up to the
 * `monitoring` mailer's timeout), and on an Octane worker that wait would hold the next visitor.
 * It runs on its own queue (Horizon's `supervisor-monitoring`) on the Valkey connection — pushing
 * it runs no SQL — and is pushed at once, never after the transaction commits: the request that
 * failed rolls its transaction back.
 *
 * The payload is the event's ALLOW-LISTED fields only (ErrorEvent::toArray()), never the
 * exception. A failure to send is written to the log and dropped: the job never fails, so it can
 * never report (and mail) itself. A dead Horizon holds these back; the daily summary, sent by the
 * scheduler, still covers the day.
 */
final class SendErrorNotice implements ShouldQueue
{
    use Queueable;

    public const string CONNECTION = 'redis';

    public const string QUEUE = 'monitoring';

    public int $tries = 1;

    public int $timeout = 30;

    /** @param  array<string, int|string|null>  $event  ErrorEvent::toArray() */
    public function __construct(public array $event, public string $reason, public int $count)
    {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
        $this->beforeCommit();
    }

    public function handle(NoticeMailer $mailer): void
    {
        try {
            $event = ErrorEvent::fromArray($this->event);

            if ($event === null) {
                Log::warning('monitoring: notice not sent', ['error' => 'unreadable notice']);

                return;
            }

            $mailer->sendNotice($event, $this->reason, $this->count);
        } catch (Throwable $e) {
            try {
                Log::warning('monitoring: notice not sent', ['exception_class' => $e::class, 'error' => $e->getMessage()]);
            } catch (Throwable) {
                // the log itself is unavailable: nothing left to tell
            }
        }
    }
}

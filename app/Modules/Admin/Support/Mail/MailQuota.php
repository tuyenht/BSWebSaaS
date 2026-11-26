<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job middleware of a queued website mail (the `mail` channel only; QueuesTenantMail::middleware()),
 * placed after WithinSite so it runs inside the website's context and transaction. It marks the send
 * as one that may be held (MailSendContext::$guarded); when the MessageSending listener holds the mail
 * back (MailHeld), it queues the same notification again — same id, same connection and queue, the
 * same middleware (WithinSite + MailQuota) — for the next day, counts it as held, and lets the job end
 * normally. release() is not used: it would spend one of the mail's tries.
 */
final readonly class MailQuota
{
    public function handle(object $job, Closure $next): mixed
    {
        $context = app(MailSendContext::class);
        $context->guarded = true;

        try {
            return $next($job);
        } catch (MailHeld $held) {
            $this->hold($job, $held);

            return null;
        } finally {
            $context->guarded = false;
        }
    }

    private function hold(object $job, MailHeld $held): void
    {
        if (! $job instanceof SendQueuedNotifications) {
            throw $held;
        }

        $connection = Cast::string($job->connection ?? config('queue.default'));
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            // A sync queue cannot wait until tomorrow: the copy would run (and be held) again at once.
            Log::warning('mail: held mail dropped on a sync queue', ['notification' => $job->notification::class]);
        } else {
            $copy = (new SendQueuedNotifications($job->notifiables, $job->notification, $job->channels))
                ->onConnection($job->connection)
                ->onQueue($job->queue)
                ->delay($held->until)
                ->through($job->middleware)
                ->afterCommit();
            app(Dispatcher::class)->dispatch($copy);
        }

        try {
            DB::transaction(fn (): int => MailLogWriter::countDay(app(CurrentSite::class)->id(), 'platform', 'held'));
        } catch (Throwable $e) {
            report($e);
        }
    }
}

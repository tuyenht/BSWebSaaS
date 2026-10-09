<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The scheduler's heartbeat: a bare GET to monitoring.heartbeat_url (healthchecks.io or similar)
 * every 5 minutes. It carries no data; the watcher outside raises the alarm when the pings stop —
 * the one fault the app cannot report itself (scheduler, container or host down). A ping that does
 * not get through is only logged: the missing ping IS the signal.
 */
final class PingHeartbeat extends Command
{
    #[\Override]
    protected $signature = 'monitoring:heartbeat';

    #[\Override]
    protected $description = 'Ping the heartbeat address so an outside watcher knows the scheduler is alive';

    public function handle(): int
    {
        $url = config('monitoring.heartbeat_url');

        if (! is_string($url) || $url === '') {
            return self::SUCCESS;
        }

        $timeout = config('monitoring.heartbeat_timeout', 5);

        try {
            Http::timeout(is_int($timeout) ? $timeout : 5)->get($url);
        } catch (Throwable $e) {
            Log::warning('monitoring: heartbeat not delivered', ['exception_class' => $e::class]);
        }

        return self::SUCCESS;
    }
}

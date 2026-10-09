<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

use App\Modules\Shared\Support\Cast;
use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Redis as PhpRedis;
use RuntimeException;

/**
 * The counters behind the error notices, in Valkey — outside the request's database transaction,
 * so nothing is lost when a faulty request rolls back. Platform-level keys, every one expiring
 * after monitoring.retention_days. Atomic commands only (several workers see the same fault at
 * once): SET NX decides the first sighting, HINCRBY hands out each count exactly once.
 *
 *   {p}seen:{fp}              first sighting in the last 24 hours (SET NX EX)
 *   {p}{day}:count            fp → count for the counting day (DayWindow)
 *   {p}{day}:first / :last    fp → first / last seen (unix time)
 *   {p}{day}:sample           fp → one ErrorEvent as JSON — the notice's fields, nothing more
 *   {p}{day}:sites            "fp<TAB>site" → count (site id, or "platform")
 *   {p}{day}:commands         scheduled command → failures
 *   {p}mails:{hour}           immediate notices sent in that clock hour
 *   {p}command-reported:{cmd} a command's own process reported its exception (cross-process de-dup)
 */
final readonly class ErrorCounter
{
    private const int COMMAND_REPORTED_TTL = 600;

    public function __construct(private DayWindow $days) {}

    /**
     * Count one occurrence and keep its sample.
     *
     * @return array{first: bool, count: int}
     */
    public function record(ErrorEvent $event, bool $commandFailure = false): array
    {
        $day = $this->dayKey($event->occurredAt);
        $fingerprint = $event->fingerprint;
        $ttl = $this->ttl();
        $site = $event->siteId !== null ? (string) $event->siteId : 'platform';

        $results = $this->pipeline(function (PhpRedis $pipe) use ($event, $day, $fingerprint, $ttl, $site, $commandFailure): void {
            $pipe->set($this->prefix().'seen:'.$fingerprint, '1', ['nx', 'ex' => $this->firstSeenSeconds()]);
            $pipe->hIncrBy($day.'count', $fingerprint, 1);
            $pipe->hSetNx($day.'first', $fingerprint, (string) $event->occurredAt);
            $pipe->hSet($day.'last', $fingerprint, (string) $event->occurredAt);
            $pipe->hSetNx($day.'sample', $fingerprint, (string) json_encode($event->toArray()));
            $pipe->hIncrBy($day.'sites', $fingerprint."\t".$site, 1);

            if ($commandFailure && $event->command !== null) {
                $pipe->hIncrBy($day.'commands', $event->command, 1);
                $pipe->expire($day.'commands', $ttl);
            }

            foreach (['count', 'first', 'last', 'sample', 'sites'] as $key) {
                $pipe->expire($day.$key, $ttl);
            }
        });

        return [
            'first' => ($results[0] ?? false) === true,
            'count' => is_int($results[1] ?? null) ? $results[1] : 0,
        ];
    }

    /** A scheduled command failed whose exception its own process already counted. */
    public function recordCommandFailure(string $command, int $at): void
    {
        $key = $this->dayKey($at).'commands';

        $this->pipeline(function (PhpRedis $pipe) use ($key, $command): void {
            $pipe->hIncrBy($key, $command, 1);
            $pipe->expire($key, $this->ttl());
        });
    }

    /** Called in a command's own process when it reports an exception. */
    public function markCommandReported(string $command): void
    {
        Redis::setex($this->prefix().'command-reported:'.$command, self::COMMAND_REPORTED_TTL, '1');
    }

    /** True (once) when the command's own process already reported the exception it died of. */
    public function takeCommandReported(string $command): bool
    {
        return (int) Redis::del($this->prefix().'command-reported:'.$command) > 0;
    }

    /** One of the hour's immediate notices; false once the hourly ceiling is reached. */
    public function claimMailSlot(int $at): bool
    {
        $key = $this->prefix().'mails:'.Carbon::createFromTimestamp($at, 'UTC')->format('YmdH');

        $results = $this->pipeline(function (PhpRedis $pipe) use ($key): void {
            $pipe->incr($key);
            $pipe->expire($key, 7_200);
        });

        $sent = is_int($results[0] ?? null) ? $results[0] : PHP_INT_MAX;
        $ceiling = config('monitoring.hourly_ceiling', 5);

        return $sent <= (is_int($ceiling) ? $ceiling : 5);
    }

    /**
     * Everything counted on one counting day, for the daily summary.
     *
     * @return array{counts: array<string, int>, samples: array<string, ErrorEvent>, sites: array<string, list<string>>, commands: array<string, int>}
     */
    public function window(string $id): array
    {
        $day = $this->prefix().$id.':';

        $counts = [];
        foreach ($this->hash($day.'count') as $fingerprint => $count) {
            $counts[$fingerprint] = (int) $count;
        }
        arsort($counts);

        $samples = [];
        foreach ($this->hash($day.'sample') as $fingerprint => $json) {
            $data = json_decode($json, true);
            $event = is_array($data) ? ErrorEvent::fromArray($data) : null;

            if ($event !== null) {
                $samples[$fingerprint] = $event;
            }
        }

        $sites = [];
        foreach (array_keys($this->hash($day.'sites')) as $field) {
            [$fingerprint, $site] = array_pad(explode("\t", $field, 2), 2, '');
            $sites[$fingerprint][] = $site;
        }

        $commands = [];
        foreach ($this->hash($day.'commands') as $command => $count) {
            $commands[$command] = (int) $count;
        }

        return ['counts' => $counts, 'samples' => $samples, 'sites' => $sites, 'commands' => $commands];
    }

    /** @return array<string, string> */
    private function hash(string $key): array
    {
        $values = Redis::hgetall($key);
        $hash = [];

        foreach (is_array($values) ? $values : [] as $field => $value) {
            $hash[(string) $field] = Cast::string($value);
        }

        return $hash;
    }

    /**
     * Run the commands in one round trip and return their replies, in order. The stack pins the
     * phpredis client (config/database.php); any other client is a misconfiguration the caller
     * logs and survives.
     *
     * @param  Closure(PhpRedis): void  $commands
     * @return list<mixed>
     */
    private function pipeline(Closure $commands): array
    {
        $connection = Redis::connection();

        if (! $connection instanceof PhpRedisConnection) {
            throw new RuntimeException('Error notices need the phpredis client.');
        }

        $results = $connection->pipeline($commands);

        return is_array($results) ? array_values($results) : [];
    }

    private function dayKey(int $at): string
    {
        return $this->prefix().$this->days->id(Carbon::createFromTimestamp($at, 'UTC')).':';
    }

    private function prefix(): string
    {
        $prefix = config('monitoring.key_prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix : 'monitoring:';
    }

    private function ttl(): int
    {
        $days = config('monitoring.retention_days', 8);

        return (is_int($days) ? $days : 8) * 86_400;
    }

    private function firstSeenSeconds(): int
    {
        $seconds = config('monitoring.first_seen_seconds', 86_400);

        return is_int($seconds) ? $seconds : 86_400;
    }
}

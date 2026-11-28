<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Jobs\CheckEmailDns;
use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DnsLookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The SPF / DKIM / DMARC check of a website's «Nhà cung cấp khác» sending domain, kept in its own
 * `email` settings under `dns` (site_settings, so per website through RLS and BelongsToSite):
 *
 *   {token, domain, provider, started_at, until, running, checked_at, next_at, rows: {spf, dkim, dmarc}}
 *
 * «Kiểm tra & lưu kết nối» starts a check (start(), at most PRESSES a PRESS_SECONDS per website):
 * a new token, the domain and service of the stored setup, and a deadline WINDOW_HOURS away. The
 * queued CheckEmailDns runs it (run()); while a record is still missing it queues itself again
 * RECHECK_MINUTES later, never past the deadline. A run whose token was replaced (pressed again),
 * or whose setup no longer sends from that domain through that service, does nothing and queues
 * nothing, so one chain at most lives per website and every chain ends by its deadline.
 *
 * The lookups run with no transaction open: a short tenant step reads the setup, the DNS answers
 * come in, another short tenant step writes the result under the row's lock.
 */
final readonly class MailDnsCheck
{
    public const int RECHECK_MINUTES = 5;

    public const int WINDOW_HOURS = 24;

    public const int PRESSES = 3;

    public const int PRESS_SECONDS = 600;

    /** How long a queued check counts as under way when its result never came (a lost job). */
    public const int RUNNING_SECONDS = 600;

    /** Queue drivers that hold a delayed job back until it is due. */
    private const array DELAYING_DRIVERS = ['redis', 'database', 'sqs', 'beanstalkd'];

    public function __construct(
        private Tenancy $tenancy,
        private SettingsRepository $settings,
        private DnsLookup $dns,
    ) {}

    /**
     * Start a check of the current website's stored setup (call inside its tenant context). Null
     * when started (or there is nothing to check: no «Nhà cung cấp khác», no domain); otherwise the
     * seconds until the press limit lets another one start.
     */
    public function start(int $siteId): ?int
    {
        $key = self::pressKey($siteId);
        if (RateLimiter::tooManyAttempts($key, self::PRESSES)) {
            return max(1, RateLimiter::availableIn($key));
        }
        $target = self::target(Cast::arr($this->settings->get('email')));
        if ($target === null) {
            return null;
        }
        RateLimiter::hit($key, self::PRESS_SECONDS);

        $token = (string) Str::ulid();
        $now = CarbonImmutable::now('UTC');
        $this->settings->change('email', 'dns', fn (): array => [
            'token' => $token,
            ...$target,
            'started_at' => $now->toIso8601String(),
            'until' => $now->addHours(self::WINDOW_HOURS)->toIso8601String(),
            'running' => true,
            'checked_at' => null,
            'next_at' => null,
            'rows' => [],
        ]);
        // sent once the request's transaction commits (afterCommit), so the row is there to read
        CheckEmailDns::dispatch($siteId, $token);

        return null;
    }

    /** Seconds until the press limit lets the website start another check (0 = it may now). */
    public static function pressWait(int $siteId): int
    {
        $key = self::pressKey($siteId);

        return RateLimiter::tooManyAttempts($key, self::PRESSES) ? max(1, RateLimiter::availableIn($key)) : 0;
    }

    /**
     * One run of the check $token belongs to, for $siteId (call outside any tenant context). Returns
     * when the next run is due (it is queued), or null when the chain ends here.
     */
    public function run(int $siteId, string $token): ?CarbonImmutable
    {
        $target = $this->tenancy->enter($siteId, fn (): ?array => $this->current($token));
        if ($target === null) {
            return null;
        }

        $rows = (new MailDnsRecords($this->dns))->check($target['domain'], $target['provider']);

        $next = null;
        $this->tenancy->enter($siteId, function () use ($token, $target, $rows, &$next): void {
            /** @param array<string, mixed>|null $dns */
            $this->settings->change('email', 'dns', function (?array $dns) use ($token, $target, $rows, &$next): ?array {
                if ($dns === null || ! self::belongs($dns, $token, $target)) {
                    return null;
                }
                $now = CarbonImmutable::now('UTC');
                $until = CarbonImmutable::parse(Cast::string($dns['until'] ?? null));
                $due = $now->addMinutes(self::RECHECK_MINUTES);
                $waiting = array_filter($rows, static fn (array $row): bool => in_array($row['code'], MailDnsRecords::FAILING, true)) !== [];
                $next = $waiting && $due->lessThanOrEqualTo($until) && self::canWait() ? $due : null;

                return [...$dns, 'running' => false, 'checked_at' => $now->toIso8601String(), 'next_at' => $next?->toIso8601String(), 'rows' => $rows];
            });
        });

        if ($next !== null) {
            CheckEmailDns::dispatch($siteId, $token)->delay($next);
        }

        return $next;
    }

    /** A run that could not finish (the job failed or ran out of time): the chain ends, shown as checked. */
    public function stop(int $siteId, string $token): void
    {
        $this->tenancy->enter($siteId, fn () => $this->settings->change('email', 'dns',
            static fn (?array $dns): ?array => is_array($dns) && ($dns['token'] ?? null) === $token
                ? [...$dns, 'running' => false, 'next_at' => null]
                : null));
    }

    /**
     * What the screen shows for the setup it draws ($domain through $provider): null when no check
     * of that setup is kept (none yet, or one of another domain or service). One settings read.
     *
     * @return array{phase: string, checked_at: CarbonImmutable|null, rows: array<string, array{code: string, why?: string, host?: string}>}|null phase: running | auto (re-checking) | done
     */
    public function state(string $domain, string $provider): ?array
    {
        $dns = $this->settings->get('email', 'dns');
        if ($domain === '' || ! is_array($dns) || ($dns['domain'] ?? null) !== $domain || ($dns['provider'] ?? null) !== $provider) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        $at = static fn (mixed $iso): ?CarbonImmutable => is_string($iso) && $iso !== '' ? CarbonImmutable::parse($iso) : null;
        $started = $at($dns['started_at'] ?? null);
        $next = $at($dns['next_at'] ?? null);

        // a result that never came, or a re-check long overdue, no longer counts as under way
        $phase = match (true) {
            ($dns['running'] ?? false) === true && $started !== null && $started->addSeconds(self::RUNNING_SECONDS)->isAfter($now) => 'running',
            $next !== null && $next->addSeconds(self::RUNNING_SECONDS)->isAfter($now) => 'auto',
            default => 'done',
        };
        $checked = $at($dns['checked_at'] ?? null);
        if ($phase === 'done' && $checked === null) {
            return null; // the first run failed or was lost: nothing to show, press again
        }
        $rows = [];
        foreach (['spf', 'dkim', 'dmarc'] as $name) {
            $row = Cast::arr(Cast::arr($dns['rows'] ?? null)[$name] ?? null);
            if (isset($row['code']) && is_string($row['code'])) {
                $rows[$name] = array_filter([
                    'code' => $row['code'],
                    'why' => is_string($row['why'] ?? null) ? $row['why'] : null,
                    'host' => is_string($row['host'] ?? null) ? $row['host'] : null,
                ], static fn (?string $v): bool => $v !== null);
            }
        }

        /** @var array<string, array{code: string, why?: string, host?: string}> $rows */
        return ['phase' => $phase, 'checked_at' => $checked, 'rows' => $rows];
    }

    /**
     * The domain and service a stored setup is checked for; null unless it sends through
     * «Nhà cung cấp khác» from a domain name.
     *
     * @param  array<string, mixed>  $cfg
     * @return array{domain: string, provider: string}|null
     */
    public static function target(array $cfg): ?array
    {
        if ($cfg === [] || EmailSettings::outboxOf($cfg) !== 'other') {
            return null;
        }
        $domain = MailDnsProviders::domainOf($cfg);

        return $domain === '' ? null : ['domain' => $domain, 'provider' => MailDnsProviders::ofConfig($cfg)];
    }

    /**
     * The tenant step before the lookups: what $token checks, while it is still the website's
     * check and its setup still sends from that domain through that service; null otherwise.
     *
     * @return array{domain: string, provider: string}|null
     */
    private function current(string $token): ?array
    {
        $target = self::target(Cast::arr($this->settings->get('email')));
        $dns = $this->settings->get('email', 'dns');
        if ($target === null || ! self::belongs($dns, $token, $target)) {
            return null;
        }
        $until = Cast::string($dns['until'] ?? null);

        return $until !== '' && CarbonImmutable::parse($until)->isAfter(CarbonImmutable::now('UTC')->subMinute()) ? $target : null;
    }

    /**
     * @param  array<string, mixed>|null  $dns
     * @param  array{domain: string, provider: string}  $target
     */
    private static function belongs(?array $dns, string $token, array $target): bool
    {
        return is_array($dns) && ($dns['token'] ?? null) === $token
            && ($dns['domain'] ?? null) === $target['domain'] && ($dns['provider'] ?? null) === $target['provider'];
    }

    /**
     * Whether the queue holds a job back until later. The sync, deferred and background queues (and
     * a failover one, which may fall back to them) run a delayed job at once, so a re-check there
     * would run again straight away, over and over: only these drivers honour the delay.
     */
    private static function canWait(): bool
    {
        $connection = Cast::string(config('queue.default'));

        return in_array(config('queue.connections.'.$connection.'.driver'), self::DELAYING_DRIVERS, true);
    }

    private static function pressKey(int $siteId): string
    {
        return 'mail-dns-check:'.$siteId;
    }
}

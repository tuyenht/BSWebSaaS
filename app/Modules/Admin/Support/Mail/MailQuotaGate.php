<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Shared\Events\MailQuotaReached;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The daily mail limit of the platform's shared outbox (`mail.platform_daily`, 30 by default).
 *
 * Every mail a website sends through the shared outbox is counted on its day (`mail_daily`, the
 * website's time zone) and on the platform's day (a Valkey counter per UTC day). Only the ordinary
 * kinds (MailKinds::HOLDABLE) sent by a queued job under the MailQuota middleware are checked:
 *  - reserve() takes the mail's place on the website's day in one statement that refuses to go past
 *    the abuse ceiling (limit × mail.quota.abuse_multiplier; a limit of 0 gives no place at all), and
 *    refuses while the platform's day has reached its ceiling (platform setting `email`/`daily_cap`
 *    {"cap": n}, else config mail.quota.platform_daily_cap; none = no ceiling). Refused → MailHeld.
 *  - The place is taken inside the job's transaction: a send that throws rolls it back, so a failed
 *    attempt gives its place back by itself. The website's row stays locked from the reservation to
 *    the job's commit (the SMTP send included): the shared-outbox mails of ONE website go one after
 *    the other, other websites are not touched. Accepted at a few hundred such mails a day.
 * Security mails, the contact-form notice and mail through the website's own outbox are never held.
 *
 * Thresholds are told once per day each (MailQuotaReached, after the count commits): 80% and 100% of
 * the website's limit, its abuse ceiling, and 90% of the platform's ceiling.
 */
final readonly class MailQuotaGate
{
    public const string FEATURE = 'mail.platform_daily';

    private const int NOTICE_TTL_SECONDS = 172_800;

    /** @throws MailHeld */
    public function reserve(MailSendContext $context): void
    {
        $siteId = app(CurrentSite::class)->id();

        // Every read runs in a savepoint: a failed statement must not abort the job's transaction
        // (the mail would leave, then the commit fail and the mail be sent again on the retry).
        try {
            [$limit, $day, $cap] = DB::transaction(fn (): array => [
                $this->limit(),
                app(SiteTime::class)->now()->toDateString(),
                $this->platformCap(),
            ]);
        } catch (Throwable $e) {
            // The count must never stop a mail: without it the mail leaves uncounted, and is logged.
            report($e);

            return;
        }

        $ceiling = $limit * max(1, Cast::int(config('mail.quota.abuse_multiplier'), 10));
        if ($ceiling <= 0) {
            throw new MailHeld('site', $this->nextLocalMidnight());
        }

        if ($cap !== null && Cast::int(Cache::get($this->platformKey())) >= $cap) {
            throw new MailHeld('platform', $this->nextUtcMidnight());
        }

        try {
            $row = DB::transaction(fn (): mixed => DB::selectOne(
                "insert into mail_daily (site_id, day, via, sent) values (?, ?::date, 'platform', 1)
                on conflict (site_id, day, via) do update set sent = mail_daily.sent + 1
                where mail_daily.sent < ?
                returning sent",
                [$siteId, $day, $ceiling],
            ));
        } catch (Throwable $e) {
            // A lock conflict (another job of this website holds its day, past a lock_timeout, or a
            // deadlock) fails this attempt before the mail leaves: the queue tries it again. Sending
            // it uncounted instead would let two jobs racing for the last place both get through.
            if (self::lockConflict($e)) {
                throw $e;
            }
            report($e);

            return;
        }

        if (! is_object($row)) {
            $this->notice('site_abuse', $siteId, $day, $ceiling, $limit);

            throw new MailHeld('site', $this->nextLocalMidnight());
        }

        $context->reserved = true;
        $this->tell($siteId, $day, Cast::int($row->sent ?? null), $limit);
    }

    private static function lockConflict(Throwable $e): bool
    {
        return $e instanceof DeadlockException
            || ($e instanceof QueryException && in_array($e->errorInfo[0] ?? null, ['55P03', '40P01'], true));
    }

    /** The website's limit for the day (its override, else its plan, else the default). */
    public function limit(): int
    {
        return max(0, Cast::int(app(Entitlement::class)->featureValue(self::FEATURE)));
    }

    /** Tell the thresholds the current website's sent count of its shared outbox has reached today. */
    public function thresholds(int $sent): void
    {
        try {
            [$limit, $day] = DB::transaction(fn (): array => [$this->limit(), app(SiteTime::class)->now()->toDateString()]);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->tell(app(CurrentSite::class)->id(), $day, $sent, $limit);
    }

    private function tell(int $siteId, string $day, int $sent, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        if ($sent >= $limit) {
            $this->notice('site_100', $siteId, $day, $sent, $limit);
        } elseif ($sent * 100 >= $limit * 80) {
            $this->notice('site_80', $siteId, $day, $sent, $limit);
        }
    }

    /** One more mail left through the shared outbox today (UTC), whoever's and whatever its kind. */
    public function countPlatform(): void
    {
        try {
            $key = $this->platformKey();
            Cache::add($key, 0, now()->addDays(2));
            $count = Cast::int(Cache::increment($key));
            $cap = DB::transaction(fn (): ?int => $this->platformCap());
            if ($cap !== null && $cap > 0 && $count * 100 >= $cap * 90) {
                $this->notice('platform_90', null, now('UTC')->toDateString(), $count, $cap);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The platform's daily ceiling for the shared outbox, or null for none. */
    public function platformCap(): ?int
    {
        $stored = app(PlatformSettings::class)->get('email', 'daily_cap');
        $cap = is_array($stored) && array_key_exists('cap', $stored) ? $stored['cap'] : config('mail.quota.platform_daily_cap');

        return is_numeric($cap) && (int) $cap >= 0 ? (int) $cap : null;
    }

    private function platformKey(): string
    {
        return 'mail-platform-day:'.now('UTC')->toDateString();
    }

    /**
     * @param  'site_80'|'site_100'|'site_abuse'|'platform_90'  $threshold
     */
    private function notice(string $threshold, ?int $siteId, string $day, int $count, int $limit): void
    {
        // After the count commits: a send that rolls back tells nobody, and its mark is not taken.
        DB::afterCommit(function () use ($threshold, $siteId, $day, $count, $limit): void {
            try {
                if (! Cache::add(self::noticeKey($threshold, $siteId, $day), 1, self::NOTICE_TTL_SECONDS)) {
                    return;
                }
                if ($threshold === 'site_100') {
                    // 100% passed by a jump: the 80% notice is not sent after it.
                    Cache::add(self::noticeKey('site_80', $siteId, $day), 1, self::NOTICE_TTL_SECONDS);
                }

                event(new MailQuotaReached($threshold, $siteId, $day, $count, $limit));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * The once-a-day mark of a threshold: `mail-quota-notified:{site}:{day}:{80|100|abuse}`, and
     * `mail-quota-notified:platform:{UTC day}:90` for the platform's ceiling.
     */
    public static function noticeKey(string $threshold, ?int $siteId, string $day): string
    {
        $suffix = match ($threshold) {
            'site_80' => '80',
            'site_100' => '100',
            'site_abuse' => 'abuse',
            default => '90',
        };

        return 'mail-quota-notified:'.($siteId ?? 'platform').":{$day}:{$suffix}";
    }

    /** The next midnight of the website's day, plus up to 15 minutes so held mails do not leave at once. */
    private function nextLocalMidnight(): CarbonImmutable
    {
        return app(SiteTime::class)->now()->addDay()->startOfDay()->addSeconds(random_int(0, 900))->utc();
    }

    private function nextUtcMidnight(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDay()->startOfDay()->addSeconds(random_int(0, 900));
    }
}

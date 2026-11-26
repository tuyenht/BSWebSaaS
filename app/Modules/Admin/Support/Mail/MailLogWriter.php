<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Mail;

use App\Modules\Admin\Models\MailLog;
use App\Modules\Admin\Support\TenantMailer;
use App\Modules\Shared\Contracts\MailFailureRecorder;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes the mail log of the current website: one `mail_logs` row per mail, and its day's count in
 * `mail_daily` (the website's local day). Best-effort, never in a mail's way: every write runs in its
 * own savepoint (a nested transaction), and any failure (a month table missing, a lock timeout) is
 * reported and swallowed, so the request or job around it carries on and the mail is still sent.
 * Inside a queued mail's job the rows commit with the job: a send that throws takes its rows back.
 */
final readonly class MailLogWriter implements MailFailureRecorder
{
    /** The longest a mail count waits for another job's lock on the same day row. */
    public const string LOCK_WAIT = '2s';

    /**
     * A mail that left. $countDay is false when MailQuotaGate already counted it on reservation.
     * Returns the day's new sent count of that outbox, or null when it was not counted.
     *
     * @param  list<string>  $recipients
     */
    public function sent(string $kind, string $via, array $recipients, ?string $notificationId, bool $countDay): ?int
    {
        return $this->write($kind, $via, 'sent', null, $recipients, $notificationId, $countDay ? 'sent' : null);
    }

    /** @param  list<string>  $recipients */
    public function failed(string $kind, string $via, string $reason, array $recipients, ?string $notificationId): void
    {
        $this->write($kind, $via, 'failed', in_array($reason, MailReason::CODES, true) ? $reason : 'other', $recipients, $notificationId, 'failed');
    }

    /** A queued mail of $siteId failed for good (its notification's failed()): logged in a context of its own. */
    public function queuedFailed(int $siteId, string $notificationClass, ?string $notificationId, Throwable $e): void
    {
        try {
            $record = function () use ($notificationClass, $notificationId, $e): void {
                // The attempt's own context is gone with its job: name the outbox its next try would use.
                $via = DB::transaction(fn (): string => app(TenantMailer::class)->outbox());
                $this->failed(MailKinds::of($notificationClass), $via, MailReason::of($e), [], $notificationId);
            };

            $current = app(CurrentSite::class);
            if (! $current->hasId()) {
                app(Tenancy::class)->enter($siteId, $record);
            } elseif ($current->id() === $siteId) {
                // A sync queue runs the job (and its failed()) inside the caller's context: stay in it,
                // since entering again would clear that context when it ends.
                $record();
            }
            // Inside another website's context: never write across websites, nothing is logged.
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /** A mail sent in this request failed (the lead notice); the outbox is the one apply() set up. */
    public function mailFailed(string $notificationClass, Throwable $e, array $recipients = []): void
    {
        try {
            $context = app(MailSendContext::class);
            $via = $context->via ?? DB::transaction(fn (): string => app(TenantMailer::class)->outbox());
            $this->failed(MailKinds::of($notificationClass), $via, MailReason::of($e), $recipients, null);
        } catch (Throwable $failure) {
            report($failure);
        }
    }

    /**
     * Runs $write (a savepoint of its own) waiting at most LOCK_WAIT for a row lock, then puts the
     * transaction's lock_timeout back. Another job of the same website keeps its day row locked until
     * it commits (through its SMTP send), so an unbounded wait could hold a visitor's form submit or a
     * security mail that long; past the bound the write fails and the caller decides.
     *
     * @template T
     *
     * @param  Closure():T  $write
     * @return T
     */
    public static function withLockWait(Closure $write, string $wait = self::LOCK_WAIT): mixed
    {
        $before = Cast::string(DB::scalar("select current_setting('lock_timeout')"));
        DB::select("select set_config('lock_timeout', ?, true)", [$wait]);

        try {
            return $write();
        } finally {
            DB::select("select set_config('lock_timeout', ?, true)", [$before]);
        }
    }

    /**
     * Adds one to the day's $column of the outbox (sent, failed or held) for the current website and
     * returns the new value. The caller runs it inside a savepoint.
     *
     * @param  'sent'|'failed'|'held'  $column
     */
    public static function countDay(int $siteId, string $via, string $column): int
    {
        $day = app(SiteTime::class)->now()->toDateString();
        $row = DB::selectOne(
            "insert into mail_daily (site_id, day, via, {$column}) values (?, ?::date, ?, 1)
            on conflict (site_id, day, via) do update set {$column} = mail_daily.{$column} + 1
            returning {$column} as n",
            [$siteId, $day, $via],
        );

        return Cast::int(is_object($row) ? ($row->n ?? null) : null);
    }

    /**
     * @param  list<string>  $recipients
     * @param  'sent'|'failed'|null  $dayColumn
     */
    private function write(string $kind, string $via, string $status, ?string $reason, array $recipients, ?string $notificationId, ?string $dayColumn): ?int
    {
        $current = app(CurrentSite::class);
        if (! $current->hasId()) {
            return null;
        }
        $siteId = $current->id();

        try {
            DB::transaction(function () use ($siteId, $kind, $via, $status, $reason, $recipients, $notificationId): void {
                $first = $recipients[0] ?? null;
                MailLog::query()->create([
                    'site_id' => $siteId,
                    'notification_id' => is_string($notificationId) && preg_match('/^[0-9a-f-]{36}$/i', $notificationId) === 1 ? $notificationId : null,
                    'kind' => in_array($kind, MailKinds::all(), true) ? $kind : MailKinds::OTHER,
                    'via' => $via,
                    'recipient_count' => max(1, min(count($recipients), 32_767)),
                    'to_first' => is_string($first) ? mb_substr($first, 0, 255) : null,
                    'status' => $status,
                    'reason_code' => $reason,
                ]);

            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($dayColumn === null) {
            return null;
        }

        // Counted apart from the log row: a count that waits too long is lost, the record is kept.
        try {
            return self::withLockWait(fn (): int => DB::transaction(fn (): int => self::countDay($siteId, $via, $dayColumn)));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}

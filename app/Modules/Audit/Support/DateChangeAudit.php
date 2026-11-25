<?php

declare(strict_types=1);

namespace App\Modules\Audit\Support;

use App\Modules\Audit\Facades\Audit;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The lasting history of the dates a reader sees or that decide when an item is public: the day a
 * post shows as published, its publish time and the time it leaves the website. Revision history is
 * pruned (and holds no publish or end time), so every change of one of these writes an audit row:
 * one row per field, `meta = {field, from, to, via, ulid}`, who did it (the signed-in member, else
 * the road: «content_pack», «scheduler», «system») and when. The audit log is append-only for the app and kept at least 13 months.
 *
 * The model calls record() from its `updated` event, so every road that changes a date is covered
 * (the editors, a status move, a workflow approval, a restored revision, a content pack). It runs in
 * the caller's transaction and uses recordOrFail: a change that cannot be recorded is not saved.
 *
 * `via` names the road when nobody is signed in: a caller that knows it says so with during() (the
 * content pack, the scheduler); otherwise «admin» with a member signed in, «system» without one.
 * Scoped per request (Octane), and during() puts the previous value back, so nothing leaks.
 */
final class DateChangeAudit
{
    public const string VIA_ADMIN = 'admin';

    public const string VIA_SYSTEM = 'system';

    public const string VIA_CONTENT_PACK = 'content_pack';

    public const string VIA_SCHEDULER = 'scheduler';

    private ?string $via = null;

    /**
     * Run $work with every date change in it recorded as made by $via.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function during(string $via, Closure $work): mixed
    {
        $before = $this->via;
        $this->via = $via;

        try {
            return $work();
        } finally {
            $this->via = $before;
        }
    }

    /**
     * One audit row per changed field. $changes maps a field to its stored value before and after
     * (a time, a Y-m-d day, or null); a field whose two values read the same is left out.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function record(Model $subject, string $action, array $changes, ?string $ulid = null): void
    {
        foreach ($changes as $field => [$from, $to]) {
            $from = self::text($from);
            $to = self::text($to);
            if ($from === $to) {
                continue;
            }

            $member = Auth::check();
            Audit::recordOrFail($action, [
                'auditable_type' => $subject::class,
                'auditable_id' => $subject->getKey(),
                'actor_label' => $member ? null : ($this->via ?? self::VIA_SYSTEM),
                'meta' => [
                    'field' => $field,
                    'from' => $from,
                    'to' => $to,
                    'via' => $this->via ?? ($member ? self::VIA_ADMIN : self::VIA_SYSTEM),
                    'ulid' => $ulid,
                ],
            ]);
        }
    }

    /** A time as ISO 8601 in UTC, a day or other text as it is, nothing as null. */
    private static function text(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->utc()->toIso8601String(),
            is_string($value) && $value !== '' => $value,
            default => null,
        };
    }
}

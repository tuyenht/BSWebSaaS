<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;

/**
 * One audit line per public-status change of an entry, whichever road made it: publish,
 * submit, the row status menu, the scheduler, a workflow transition, the one-off retirement of
 * `private` (cms:retire-private-status). The homepage incident of
 * 02/08 (a page set private under a shared auto-login, found six weeks later) was unanswerable
 * because tier-1 status changes left no trace — `updated_at` is overwritten by the next save
 * and the audit trail only knew auth/role events. Best-effort like every Audit record: it never
 * blocks the change it describes — except a `$strict` caller (the system's one-off move, which has no
 * member behind it), where a failed write throws and rolls the change back.
 */
final class EntryStatusAudit
{
    public const string ACTION = 'entries.status.changed';

    /**
     * @param  string  $via  publish|submit|status-menu|scheduler|workflow|retire-private
     * @param  array<string, mixed>  $extra  e.g. transition key, publish_at
     * @param  string|null  $actorLabel  who made it when no member did (e.g. 'system' for a one-off command)
     * @param  bool  $strict  the change must not happen without its line: a failed write throws (and rolls the change back)
     */
    public static function record(Entry $entry, string $from, string $to, string $via, ?User $actor = null, array $extra = [], ?string $actorLabel = null, bool $strict = false): void
    {
        $context = [
            'auditable_type' => Entry::class,
            'auditable_id' => $entry->getKey(),
            // The scheduler has no authenticated user; a caller that knows the actor names it so
            // the row is right even where Auth::user() is someone else (a queued job, a CLI).
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor !== null ? $actor->email : $actorLabel,
            'meta' => ['from' => $from, 'to' => $to, 'via' => $via, 'entry_ulid' => $entry->ulid] + $extra,
        ];

        if ($strict) {
            Audit::recordOrFail(self::ACTION, $context);

            return;
        }

        Audit::record(self::ACTION, $context);
    }
}

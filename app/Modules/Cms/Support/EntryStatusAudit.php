<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;

/**
 * One audit line per public-status change of an entry, whichever road made it: publish,
 * submit, the row status menu, the scheduler, a workflow transition. The homepage incident of
 * 02/08 (a page set private under a shared auto-login, found six weeks later) was unanswerable
 * because tier-1 status changes left no trace — `updated_at` is overwritten by the next save
 * and the audit trail only knew auth/role events. Best-effort like every Audit record: it never
 * blocks the change it describes.
 */
final class EntryStatusAudit
{
    public const string ACTION = 'entries.status.changed';

    /**
     * @param  string  $via  publish|submit|status-menu|scheduler|workflow
     * @param  array<string, mixed>  $extra  e.g. transition key, publish_at
     */
    public static function record(Entry $entry, string $from, string $to, string $via, ?User $actor = null, array $extra = []): void
    {
        Audit::record(self::ACTION, [
            'auditable_type' => Entry::class,
            'auditable_id' => $entry->getKey(),
            // The scheduler has no authenticated user; a caller that knows the actor names it so
            // the row is right even where Auth::user() is someone else (a queued job, a CLI).
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor?->email,
            'meta' => ['from' => $from, 'to' => $to, 'via' => $via, 'entry_ulid' => $entry->ulid] + $extra,
        ]);
    }
}

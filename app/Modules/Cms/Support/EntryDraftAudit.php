<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;

/**
 * One audit line when a pending change to live content ends (design.md §8): applied to the website
 * (approved, or applied by a publisher), thrown away, or folded into the entry as it left the
 * website. The content itself lives on in the entry and its revisions; this line says who ended
 * the change and how. Best-effort like every Audit record.
 */
final class EntryDraftAudit
{
    public const string APPLIED = 'entries.change.applied';

    public const string DISCARDED = 'entries.change.discarded';

    public const string FOLDED = 'entries.change.folded';

    /**
     * @param  self::APPLIED|self::DISCARDED|self::FOLDED  $action
     * @param  list<string>  $locales  the languages the change held, sorted
     * @param  array<string, mixed>  $extra
     */
    public static function record(string $action, Entry $entry, ?User $actor, array $locales, ?string $workflowState, array $extra = []): void
    {
        Audit::record($action, [
            'auditable_type' => Entry::class,
            'auditable_id' => $entry->getKey(),
            'actor_id' => $actor?->getKey(),
            'actor_label' => $actor?->email,
            'meta' => ['entry_ulid' => $entry->ulid, 'locales' => $locales, 'workflow_state' => $workflowState] + $extra,
        ]);
    }
}

<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only trail of applied transitions — who moved what, when, with which note/assignment.
 * Subject is TYPED (`entry_id`), not polymorphic: see the migration header.
 *
 * @property int $site_id
 * @property int|null $workflow_id
 * @property int|null $entry_id
 * @property string|null $locale
 * @property string $transition_key
 * @property string $from_state
 * @property string $to_state
 * @property array{transition?: string, from?: string|null, to?: string} $labels
 * @property int|null $actor_id
 * @property int|null $assigned_to
 * @property string|null $note
 */
class WorkflowLog extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $table = 'workflow_log';

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return ['labels' => 'array'];
    }

    /** A trail row is never edited — it records a moment, so only created_at exists. */
    public const UPDATED_AT = null;
}

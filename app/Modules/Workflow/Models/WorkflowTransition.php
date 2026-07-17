<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A legal edge between two states. `required_permission` IS the guard (design.md §15.4): who may
 * move content is answered by RBAC, never hard-coded here — which is what lets a site redefine
 * its stages without a deploy.
 *
 * @property int $site_id
 * @property int $workflow_id
 * @property string $key
 * @property string $label
 * @property string $from_state
 * @property string $to_state
 * @property string $required_permission
 * @property bool $requires_note
 */
class WorkflowTransition extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return ['requires_note' => 'boolean'];
    }
}

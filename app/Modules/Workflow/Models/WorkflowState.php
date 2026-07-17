<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of a workflow. Only an `is_published` state may expose its subject publicly — the
 * engine reads this rather than letting a stage name imply visibility.
 *
 * @property int $site_id
 * @property int $workflow_id
 * @property string $key
 * @property string $label
 * @property int $sort_order
 * @property bool $is_initial
 * @property bool $is_published
 * @property bool $is_terminal
 */
class WorkflowState extends Model
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
        return [
            'is_initial' => 'boolean',
            'is_published' => 'boolean',
            'is_terminal' => 'boolean',
        ];
    }
}

<?php

namespace App\Modules\Workflow\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-(site, content type) approval workflow (design.md §15.4). `is_active` is false until an
 * operator opts in, so tier-1 permission-driven publishing stays the default everywhere.
 *
 * @property int $site_id
 * @property string $key
 * @property string $name
 * @property string $applies_to_type a content_sections.key
 * @property string $template none|admin_approval|editorial
 * @property bool $is_active
 */
class Workflow extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<WorkflowState, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(WorkflowState::class);
    }

    /**
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class);
    }

    /** The state a subject enters on creation. */
    public function initialState(): ?WorkflowState
    {
        return $this->states()->where('is_initial', true)->first();
    }

    /**
     * The (first) stage that exposes a subject publicly — where content that was already live
     * when the flow was switched on belongs, so that "submit" never means "hide".
     */
    public function publishedState(): ?WorkflowState
    {
        return $this->states()->where('is_published', true)->orderBy('sort_order')->orderBy('id')->first();
    }

    #[\Override]
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

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

    /**
     * The stage helpers below read the `states` relation when it is already loaded. A caller
     * that resolves many subjects (the review inbox, a bulk move) loads it once; querying here
     * regardless would put a query behind every subject and make those screens scale with the
     * size of the queue.
     */
    public function initialState(): ?WorkflowState
    {
        return $this->relationLoaded('states')
            ? $this->states->firstWhere('is_initial', true)
            : $this->states()->where('is_initial', true)->first();
    }

    /**
     * The (first) stage that exposes a subject publicly — where content that was already live
     * when the flow was switched on belongs, so that "submit" never means "hide".
     */
    public function publishedState(): ?WorkflowState
    {
        if ($this->relationLoaded('states')) {
            return $this->states->sortBy([['sort_order', 'asc'], ['id', 'asc']])->firstWhere('is_published', true);
        }

        return $this->states()->where('is_published', true)->orderBy('sort_order')->orderBy('id')->first();
    }

    /** Whether this workflow defines a stage — used to distrust a stale pointer on a subject. */
    public function definesState(string $key): bool
    {
        return $this->relationLoaded('states')
            ? $this->states->contains('key', $key)
            : $this->states()->where('key', $key)->exists();
    }

    #[\Override]
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}

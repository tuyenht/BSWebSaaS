<?php

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Concerns\HasCategoryTaxonomies;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Content entry (design.md §8). type ∈ {page, post}; the display layout/body lives
 * per-locale in entry_translations. Soft-deletable (Trash). RLS isolates by site;
 * deleted_at is an app-layer scope so Trash can withTrashed.
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property string $type
 * @property string $status
 * @property string|null $template_override
 * @property int|null $author_id
 * @property Carbon|null $published_at
 * @property string|null $workflow_state the stage of the section's approval workflow, null until it joins one
 * @property int|null $assigned_to
 * @property array<string, mixed> $meta
 */
class Entry extends Model
{
    use BelongsToSite;
    use HasCategoryTaxonomies;
    use HasUlidColumn;
    use SoftDeletes;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<EntryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(EntryTranslation::class);
    }

    /**
     * The user who created the entry (the list's "Người tạo" column). A soft reference —
     * author_id is nullable (SET NULL if the user is removed), so this can be null.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The parent page (self-referential; null at the top level). Drives a page's nested URL
     * path and the parent/child grouping in the admin list.
     *
     * @return BelongsTo<Entry, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'parent_id');
    }

    /**
     * Canonical routes (one per locale). Lets callers link an entry without recomposing
     * its path (the post-list components read the stored path).
     *
     * @return HasMany<SiteRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(SiteRoute::class);
    }

    /**
     * @return BelongsToMany<Taxonomy, $this>
     */
    public function taxonomies(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, 'entry_taxonomies')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /**
     * The entry's sector terms only (the `sector` vocabulary) — eager-loadable for a card
     * badge without pulling every category. A project carries one; ordered by pivot position.
     *
     * @return BelongsToMany<Taxonomy, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Taxonomy::class, 'entry_taxonomies')
            ->where('taxonomies.vocabulary', 'sector')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function translationFor(string $locale): ?EntryTranslation
    {
        // Use the eager-loaded relation when present (avoids an N+1 in any caller that
        // already loaded translations); otherwise query the single locale.
        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->first();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'position' => 'integer',
            'author_id' => 'integer',
            'view_count' => 'integer',
            'meta' => 'array',
        ];
    }
}

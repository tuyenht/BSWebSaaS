<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A taxonomy term / category (design.md §9). Tree via parent_id (+ ltree `path` for
 * fast subtree queries); localized name/slug in taxonomy_translations. The category's
 * templates (list/detail) drive storefront display per §8. RLS isolates by site.
 *
 * @property int $id
 * @property int $site_id
 * @property string $vocabulary
 * @property int|null $parent_id
 * @property string|null $path
 * @property string|null $list_template
 * @property string|null $detail_template
 * @property int $position
 * @property array<string, mixed> $meta
 */
class Taxonomy extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<TaxonomyTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(TaxonomyTranslation::class);
    }

    public function translationFor(string $locale): ?TaxonomyTranslation
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->first();
    }

    /**
     * @return BelongsToMany<Entry, $this>
     */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'entry_taxonomies')->withPivot('position');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'position' => 'integer',
            'meta' => 'array',
        ];
    }
}

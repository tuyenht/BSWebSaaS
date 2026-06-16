<?php

namespace App\Modules\Cms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * @property array<string, mixed> $meta
 */
class Entry extends Model
{
    use BelongsToSite;
    use HasUlidColumn;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return HasMany<EntryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(EntryTranslation::class);
    }

    public function translationFor(string $locale): ?EntryTranslation
    {
        return $this->translations()->where('locale', $locale)->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'position' => 'integer',
            'meta' => 'array',
        ];
    }
}

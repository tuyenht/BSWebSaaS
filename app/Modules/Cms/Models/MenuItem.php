<?php

namespace App\Modules\Cms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One menu entry (design.md §13). Tree via parent_id + position. link_type picks the
 * target: entry|taxonomy → polymorphic routable_* (a page/post entry or a category);
 * url → an external link; dynamic → a computed link (dynamic_key, e.g. 'home' → '/').
 * The target is polymorphic (D3: no FK — resolve-time orphan skip, observer/sweep later).
 * Per-locale label in menu_item_translations (empty → routable's translated title).
 * RLS isolates by site.
 *
 * A removed item waits in the trash (deleted_at) before it is purged; the trash is the
 * SoftDeletes scope, RLS stays on site_id only, so the trash list and restore still see it.
 * `ulid` is the key screens put into the page (D2); rows made before it existed are filled by
 * `cms:fill-ulids`, so it can still be null until a later deploy makes it NOT NULL.
 *
 * @property int $id
 * @property string|null $ulid
 * @property int $site_id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property int $position
 * @property string $link_type
 * @property string|null $routable_type
 * @property int|null $routable_id
 * @property string|null $url
 * @property string|null $dynamic_key
 * @property array<string, mixed> $meta
 * @property Carbon|null $deleted_at
 */
class MenuItem extends Model
{
    use BelongsToSite;
    use HasUlidColumn;
    use SoftDeletes;

    #[\Override]
    protected $guarded = [];

    /**
     * The polymorphic target (entry|taxonomy), resolved via the morph map (CmsServiceProvider).
     *
     * @return MorphTo<Model, $this>
     */
    public function routable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<MenuItemTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(MenuItemTranslation::class);
    }

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id');
    }

    public function translationFor(string $locale): ?MenuItemTranslation
    {
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
            'position' => 'integer',
            'meta' => 'array',
        ];
    }
}

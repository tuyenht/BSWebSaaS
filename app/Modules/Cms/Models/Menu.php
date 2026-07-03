<?php

namespace App\Modules\Cms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A navigation menu (design.md §13). Many per site; `handle` is the stable key. An Vinh
 * runs model-B i18n — multiple menus, one bound per (region × locale) via menu_assignments.
 * `locales` (JSONB, NULL = all) is admin metadata; the storefront selects the menu by
 * assignment, not by scanning this column. RLS isolates by site.
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property string $handle
 * @property string $name
 * @property list<string>|null $locales
 * @property array<string, mixed> $meta
 */
class Menu extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<MenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /**
     * @return HasMany<MenuAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(MenuAssignment::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'locales' => 'array',
            'meta' => 'array',
        ];
    }
}

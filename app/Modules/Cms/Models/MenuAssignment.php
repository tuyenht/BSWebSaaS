<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Binds a menu into a theme region per locale (region × locale → menu) — design.md §13.
 * One menu per (site, region_key, locale). The storefront resolves a region's menu for the
 * active locale via this table (falling back to the site's default locale). The menu is
 * guaranteed same-site by a composite FK. RLS isolates by site.
 *
 * An area either shows a menu (menu_id) or follows another area of the same theme
 * (same_as_region, e.g. the phone menu follows the main menu); a CHECK keeps exactly one set.
 *
 * @property int $site_id
 * @property string $region_key
 * @property string $locale
 * @property int|null $menu_id
 * @property string|null $same_as_region
 */
class MenuAssignment extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['menu_id' => 'integer'];
    }
}

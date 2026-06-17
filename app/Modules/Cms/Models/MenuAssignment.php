<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Binds a menu into a theme region per locale (region × locale → menu) — design.md §13.
 * One menu per (site, region_key, locale). The storefront resolves a region's menu for the
 * active locale via this table (falling back to the site's default locale). The menu is
 * guaranteed same-site by a composite FK. RLS isolates by site.
 *
 * @property int $site_id
 * @property string $region_key
 * @property string $locale
 * @property int $menu_id
 */
class MenuAssignment extends Model
{
    use BelongsToSite;

    protected $guarded = [];
}

<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale label for a menu item. Empty/absent → the storefront falls back to the
 * routable target's translated title (design.md §13). RLS isolates by site.
 *
 * @property int $site_id
 * @property int $menu_item_id
 * @property string $locale
 * @property string $label
 */
class MenuItemTranslation extends Model
{
    use BelongsToSite;

    protected $guarded = [];
}

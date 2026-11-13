<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One place a reusable block is used: exactly one owner, a page or post body (entry_id), a
 * category's slot (taxonomy_id + slot), the website's default slot (site_setting_id + slot) or a
 * menu item's large panel (menu_item_id). `locale` is the owner's language, null for a menu item. A
 * materialized index, rewritten in the same transaction as the owner it describes; the owner keys
 * cascade when the owner is deleted for good. Read by ContentBlockUsages ("Dùng ở N nơi"). RLS
 * isolates by site.
 *
 * @property int $id
 * @property int $site_id
 * @property int $content_block_id
 * @property string|null $locale
 * @property int|null $entry_id
 * @property int|null $taxonomy_id
 * @property int|null $site_setting_id
 * @property int|null $menu_item_id
 * @property string|null $slot
 * @property Carbon|null $created_at
 */
class BlockUsage extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    #[\Override]
    protected $guarded = [];

    /**
     * @return BelongsTo<ContentBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }
}

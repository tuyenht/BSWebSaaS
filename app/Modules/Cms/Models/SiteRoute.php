<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resolved full URL path → exactly one target (entry/product/taxonomy). Named
 * SiteRoute to avoid colliding with the Route facade; table is `routes`.
 *
 * @property int $site_id
 * @property string $locale
 * @property string $path
 * @property int|null $entry_id
 * @property int|null $taxonomy_id
 */
class SiteRoute extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $table = 'routes';

    #[\Override]
    protected $guarded = [];

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * @return BelongsTo<Taxonomy, $this>
     */
    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class);
    }
}

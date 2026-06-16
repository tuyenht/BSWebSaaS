<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A 301/302 history row: an old path → its current target within a (site, locale).
 * Created when a slug changes (design.md §213); served by the storefront before it
 * 404s, so links and search engines follow content as it moves. RLS isolates by site.
 *
 * @property int $site_id
 * @property string $locale
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property int|null $entry_id
 */
class Redirect extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'entry_id' => 'integer',
        ];
    }
}

<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale content. body = Page-JSON ({"blocks":[…]}) for a page, or {"html":…}
 * for a post. slug is localized; the full resolved path lives in routes.
 *
 * @property int $site_id
 * @property int $entry_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property array<string, mixed> $body
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string $status
 */
class EntryTranslation extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'array',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * A site's content locales (i18n). `enabled` = editors write in it; `is_public` = visitors can
 * open it (storefront prefix, hreflang, sitemap). Public implies enabled, and the default locale
 * is always both — CHECK-enforced. GĐ1 (An Vinh reference site): vi (default) + en + ja.
 *
 * @property int $site_id
 * @property string $locale
 * @property bool $is_default
 * @property bool $enabled
 * @property bool $is_public
 */
class SiteLocale extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'enabled' => 'boolean',
            'is_public' => 'boolean',
        ];
    }
}

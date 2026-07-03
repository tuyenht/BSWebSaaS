<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Enabled locales for a site (i18n). GĐ1 (An Vinh reference site): vi (default) + en + ja.
 *
 * @property int $site_id
 * @property string $locale
 * @property bool $is_default
 * @property bool $enabled
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
        ];
    }
}

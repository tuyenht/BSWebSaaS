<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Grouped key/value site configuration (groups: general|seo|scripts|smtp|payment|…).
 * Open values live in JSONB; a field that becomes hot graduates to a real column.
 *
 * @property int $site_id
 * @property string $group_name
 * @property string $key
 * @property array<string, mixed> $value
 */
class SiteSetting extends Model
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
            'value' => 'array',
        ];
    }
}

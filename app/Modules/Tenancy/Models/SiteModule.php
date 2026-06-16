<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Entitlement: which modules a site may use (defense layer #1). Toggled by the
 * platform per plan/contract.
 *
 * @property int $site_id
 * @property string $module_key
 * @property bool $enabled
 * @property array<string, mixed> $settings
 */
class SiteModule extends Model
{
    use BelongsToSite;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'settings' => 'array',
        ];
    }
}

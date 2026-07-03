<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Localized site profile fields (per locale).
 *
 * @property int $site_id
 * @property int $site_profile_id
 * @property string $locale
 */
class SiteProfileTranslation extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];
}

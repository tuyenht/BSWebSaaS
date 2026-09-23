<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * NAP / LocalBusiness identity for a site (feeds JSON-LD + legal footer). One row
 * per site; localized fields live in site_profile_translations.
 *
 * @property int $site_id
 * @property string|null $legal_name
 * @property string|null $tax_code
 * @property array<string, mixed> $meta
 */
class SiteProfile extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<SiteProfileTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(SiteProfileTranslation::class);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'meta' => 'array',
            'social_links' => 'array',
            'business_hours' => 'array',
            'license_issued_at' => 'immutable_date',
            'moit_registered' => 'boolean',
        ];
    }
}

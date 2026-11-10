<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use App\Modules\Tenancy\Concerns\RecordsWhoSaved;
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
 * @property int|null $logo_media_id
 * @property int|null $logo_dark_media_id the logo on a dark background; empty = the normal logo
 * @property int|null $favicon_media_id
 * @property int|null $og_image_media_id the image a shared link carries
 * @property int|null $updated_by the account that saved last (RecordsWhoSaved)
 */
class SiteProfile extends Model
{
    use BelongsToSite;
    use RecordsWhoSaved;

    /** The brand image slots → their media id columns. */
    public const array IMAGE_COLUMNS = [
        'logo' => 'logo_media_id',
        'logo_dark' => 'logo_dark_media_id',
        'favicon' => 'favicon_media_id',
        'og_image' => 'og_image_media_id',
    ];

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
            'logo_media_id' => 'integer',
            'logo_dark_media_id' => 'integer',
            'favicon_media_id' => 'integer',
            'og_image_media_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}

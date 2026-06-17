<?php

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale name + slug for a taxonomy term. slug is citext (case-insensitive within
 * a locale); the storefront URL path is composed from the slug chain (PathBuilder).
 *
 * @property int $site_id
 * @property int $taxonomy_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 */
class TaxonomyTranslation extends Model
{
    use BelongsToSite;

    protected $guarded = [];
}

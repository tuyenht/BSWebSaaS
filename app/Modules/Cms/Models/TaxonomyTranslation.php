<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale name + slug for a taxonomy term. slug is citext (case-insensitive within
 * a locale); the storefront URL path is composed from the slug chain (PathBuilder).
 * The description is plain text, escaped when shown; seo_title / seo_description are the
 * category's own Google title and description, typed like entry_translations'.
 *
 * @property int $site_id
 * @property int $taxonomy_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $seo_title
 * @property string|null $seo_description
 */
class TaxonomyTranslation extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];
}

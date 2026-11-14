<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale name + slug for a taxonomy term. slug is citext (case-insensitive within
 * a locale); the storefront URL path is composed from the slug chain (PathBuilder).
 * The description is plain text, escaped when shown; seo_title / seo_description are the
 * category's own Google title and description, typed like entry_translations'. card_label is the
 * short label the category's cards show (cardLabel()); breadcrumbs, lists and titles keep the name.
 *
 * @property int $site_id
 * @property int $taxonomy_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property string|null $card_label
 */
class TaxonomyTranslation extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /** The label a card shows for this category: its own short label, or its name when it has none. */
    public function cardLabel(): string
    {
        $label = is_string($this->card_label) ? trim($this->card_label) : '';

        return $label !== '' ? $label : $this->name;
    }
}

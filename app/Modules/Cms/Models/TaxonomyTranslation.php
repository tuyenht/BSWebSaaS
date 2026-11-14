<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\TaxonomySearchWords;
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

    /**
     * A language added or removed, or a changed name, rewrites this language's storefront search words
     * in the same transaction (TaxonomySearchWords), from the name just saved. Other saves write nothing.
     */
    #[\Override]
    protected static function booted(): void
    {
        $sync = static function (self $row): void {
            if (array_key_exists('name', $row->getAttributes())) {
                TaxonomySearchWords::put($row->site_id, $row->taxonomy_id, $row->locale, $row->name);
            } else {
                TaxonomySearchWords::sync($row->site_id, $row->taxonomy_id, $row->locale);
            }
        };
        static::created($sync);
        static::updated(static function (self $row) use ($sync): void {
            if ($row->wasChanged('name')) {
                $sync($row);
            }
        });
        static::deleted(static function (self $row): void {
            TaxonomySearchWords::put($row->site_id, $row->taxonomy_id, $row->locale, null);
        });
    }

    /** The label a card shows for this category: its own short label, or its name when it has none. */
    public function cardLabel(): string
    {
        $label = is_string($this->card_label) ? trim($this->card_label) : '';

        return $label !== '' ? $label : $this->name;
    }
}

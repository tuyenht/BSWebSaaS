<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\TaxonomySearchWords;
use App\Modules\Shared\Support\Cast;
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
     * A language added or removed, a changed name, or a row moved to another language or category
     * rewrites this language's storefront search words in the same transaction (TaxonomySearchWords),
     * from the name just saved (or the saved row read back when the model does not hold it); a moved
     * row first takes its words off where it was. Other saves write nothing.
     */
    #[\Override]
    protected static function booted(): void
    {
        $sync = static function (self $row): void {
            if (array_key_exists('name', $row->getAttributes())) {
                TaxonomySearchWords::put($row->site_id, $row->taxonomy_id, $row->locale, $row->name);
            } else {
                TaxonomySearchWords::putSaved($row->site_id, $row->id);
            }
        };
        static::created($sync);
        static::updated(static function (self $row) use ($sync): void {
            if ($row->wasChanged(['locale', 'taxonomy_id'])) {
                TaxonomySearchWords::put($row->site_id, Cast::int($row->getOriginal('taxonomy_id')), Cast::string($row->getOriginal('locale')), null);
                $sync($row);
            } elseif ($row->wasChanged('name')) {
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

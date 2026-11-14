<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Casts\SanitizedPageBody;
use App\Modules\Cms\Concerns\TracksContentChange;
use App\Modules\Cms\Support\EntrySearchWords;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-locale content. body = Page-JSON ({"blocks":[…]}) for a page or a post; a
 * formatted post body is a single `html` block, sanitized on every write. slug is
 * localized; the full resolved path lives in routes. author_name is who a post is by as readers see
 * it (a team or department, free text linked to no staff account; empty = the site's business). It
 * is not main content, so changing it alone never moves the content mark.
 *
 * @property int $site_id
 * @property int $entry_id
 * @property string $locale
 * @property string $title
 * @property string $slug
 * @property array<string, mixed> $body
 * @property string|null $excerpt
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property int|null $og_image_id
 * @property string $status
 * @property string|null $author_name
 * @property Carbon|null $content_changed_at when the page's main content last changed (TracksContentChange)
 */
class EntryTranslation extends Model
{
    use BelongsToSite;
    use TracksContentChange;

    #[\Override]
    protected $guarded = [];

    /**
     * A language added or removed, a changed title or excerpt, or a row moved to another language or
     * entry rewrites this language's storefront search words in the same transaction
     * (EntrySearchWords): from the values just saved when the model holds both, else from the saved
     * row read back; a moved row first takes its words off where it was. Other saves write nothing.
     */
    #[\Override]
    protected static function booted(): void
    {
        $sync = static function (self $row): void {
            $attributes = $row->getAttributes();
            if (array_key_exists('title', $attributes) && array_key_exists('excerpt', $attributes)) {
                EntrySearchWords::put($row->site_id, $row->entry_id, $row->locale, $row->title, $row->excerpt);
            } else {
                EntrySearchWords::putSaved($row->site_id, $row->id);
            }
        };
        static::created($sync);
        static::updated(static function (self $row) use ($sync): void {
            if ($row->wasChanged(['locale', 'entry_id'])) {
                EntrySearchWords::put($row->site_id, Cast::int($row->getOriginal('entry_id')), Cast::string($row->getOriginal('locale')), null, null);
                $sync($row);
            } elseif ($row->wasChanged(['title', 'excerpt'])) {
                $sync($row);
            }
        });
        static::deleted(static function (self $row): void {
            EntrySearchWords::put($row->site_id, $row->entry_id, $row->locale, null, null);
        });
    }

    /** The columns a post or page shows as its main content: heading, text, summary, Google's title and description. */
    public function contentFields(): array
    {
        return ['title', 'body', 'excerpt', 'seo_title', 'seo_description'];
    }

    /**
     * A JSON-path write (`body->blocks`) goes round the cast's set(), so the whole body is cleaned
     * again right after it (no such write exists; PageBodyWriteChokepointTest bans them too).
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    #[\Override]
    public function fillJsonAttribute($key, $value)
    {
        parent::fillJsonAttribute($key, $value);
        if (explode('->', $key, 2)[0] === 'body') {
            $this->setAttribute('body', $this->getAttribute('body'));
        }

        return $this;
    }

    /**
     * body: every html prop is sanitized on every write (ADR-19, SanitizedPageBody).
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'body' => SanitizedPageBody::class,
        ];
    }
}

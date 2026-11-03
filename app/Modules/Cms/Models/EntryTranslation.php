<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Casts\SanitizedPageBody;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-locale content. body = Page-JSON ({"blocks":[…]}) for a page or a post; a
 * formatted post body is a single `html` block, sanitized on every write. slug is
 * localized; the full resolved path lives in routes.
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
 */
class EntryTranslation extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

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

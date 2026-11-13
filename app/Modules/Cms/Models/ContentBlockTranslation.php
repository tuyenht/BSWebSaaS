<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Casts\SanitizedPageBody;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A reusable block's body in one language: Page-JSON `{"blocks": […]}`, checked like a page body
 * (ValidPageJson) before it is saved, and cleaned on every write by the same cast as a page body
 * (SanitizedPageBody: every formatted-text prop through the sanitizer). Saving one touches its
 * block, so the list's "Cập nhật" date moves. RLS isolates by site.
 *
 * @property int $id
 * @property int $site_id
 * @property int $content_block_id
 * @property string $locale
 * @property array<string, mixed> $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentBlockTranslation extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /** @var list<string> */
    #[\Override]
    protected $touches = ['block'];

    /**
     * @return BelongsTo<ContentBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(ContentBlock::class, 'content_block_id');
    }

    /**
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

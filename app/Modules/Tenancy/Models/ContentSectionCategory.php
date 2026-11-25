<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One category a content section covers (content_section_categories): a section may cover several
 * top-level categories side by side, and a category belongs to one section at most (UNIQUE per
 * site). The section's first category stays on content_sections.taxonomy_id (expand-contract);
 * ContentSection keeps that one here too.
 *
 * @property int $id
 * @property int $site_id
 * @property int $content_section_id
 * @property int $taxonomy_id
 */
class ContentSectionCategory extends Model
{
    use BelongsToSite;

    public const UPDATED_AT = null;

    #[\Override]
    protected $guarded = [];

    /** @return BelongsTo<ContentSection, $this> */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ContentSection::class, 'content_section_id');
    }
}

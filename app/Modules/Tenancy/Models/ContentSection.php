<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Dynamic content-type registry / admin nav section (page|post + templates). Seeded
 * per-site by ProvisionSite; drives which "kinds" of content a site manages.
 *
 * @property int $site_id
 * @property string $key
 * @property string $label
 * @property string|null $icon
 * @property int $sort_order
 * @property string|null $entry_type
 * @property int|null $taxonomy_id
 * @property string|null $list_template
 * @property string|null $detail_template
 * @property string|null $permission_prefix
 * @property array<string, mixed> $meta
 */
class ContentSection extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }
}

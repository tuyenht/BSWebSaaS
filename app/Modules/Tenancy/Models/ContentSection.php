<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;

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

    /**
     * The section name in the current UI locale. Standard sections (pages, posts, services…) carry a
     * `section.{key}` translation; a tenant's custom section, with no such key, keeps its stored label.
     * This localizes the admin chrome without translating tenant-authored data.
     */
    public function localizedLabel(): string
    {
        $key = 'section.'.$this->key;

        return Lang::has($key) ? __($key) : $this->label;
    }
}

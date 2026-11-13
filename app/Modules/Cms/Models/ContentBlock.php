<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A reusable block of the website's block library ("Thư viện khối"): content built once and shown
 * wherever it is placed, a page body, a slot around posts or products, a menu's large panel. Its
 * body is kept per language (ContentBlockTranslation); where it is used is recorded in BlockUsage.
 * Everything outside refers to it by `ulid`, never by id (D2). `key` is the stable reference code an
 * editor copies; it never changes on rename. `region` only suggests where it goes (the list filters
 * on it), `status` says whether the website shows it. RLS isolates by site.
 *
 * Every write goes through the actions in Cms\Actions\ContentBlocks (ContentBlockPolicy first).
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property string $key
 * @property string $name
 * @property string $region
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentBlock extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    /** Where a block is meant to go: a hint for the list's filter, nothing more. */
    public const array REGIONS = ['footer', 'header', 'sidebar', 'inline', 'menu'];

    public const string DEFAULT_REGION = 'inline';

    public const array STATUSES = ['draft', 'published'];

    public const string DRAFT = 'draft';

    public const string PUBLISHED = 'published';

    /** The longest name, and the longest reference code (the table's limits). */
    public const int NAME_MAX = 120;

    public const int KEY_MAX = 64;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * @return HasMany<ContentBlockTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ContentBlockTranslation::class);
    }

    /**
     * @return HasMany<BlockUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(BlockUsage::class);
    }
}

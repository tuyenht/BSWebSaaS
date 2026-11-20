<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A 301/302 history row: an old path → its current target within a (site, locale).
 * Created when a slug changes (design.md §213, source 'auto') or by hand on the «Chuyển hướng»
 * screen (source 'manual'); served by the storefront before it 404s, so links and search engines
 * follow content as it moves. A row in the trash (deleted_at) is hidden from every reader by the
 * SoftDeletes scope and purged after 30 days. RLS isolates by site.
 *
 * entry_id / product_id / taxonomy_id name the content the row leads to when RouteSync wrote it
 * (at most one is set); they fall to NULL when that content is deleted.
 *
 * @property int $id
 * @property string|null $ulid
 * @property int $site_id
 * @property string $locale
 * @property string $from_path
 * @property string $to_path
 * @property int $status_code
 * @property string $source
 * @property int|null $entry_id
 * @property int|null $product_id
 * @property int|null $taxonomy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class Redirect extends Model
{
    use BelongsToSite;
    use HasUlidColumn;
    use SoftDeletes;

    public const string SOURCE_AUTO = 'auto';

    public const string SOURCE_MANUAL = 'manual';

    /** @var list<int> */
    public const array STATUS_CODES = [301, 302];

    /** Days a removed redirect waits in the trash before `redirects:purge-trash` deletes it for good. */
    public const int TRASH_DAYS = 30;

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status_code' => 'integer',
            'entry_id' => 'integer',
            'product_id' => 'integer',
            'taxonomy_id' => 'integer',
        ];
    }
}

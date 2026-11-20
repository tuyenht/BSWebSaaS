<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * A row that leads to another website (`https://…`, only ever written by a member on the screen) is
 * never changed or removed by the system's own writes (slug moves, packs): every change to one is a
 * member's, and audited — except the trash purge, which audits each one it deletes.
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

    /** How every redirect to another website starts (stored lower-case: SafeLink::externalHttps). */
    public const string EXTERNAL_PREFIX = 'https://';

    /** Audit code of a redirect to another website deleted for good from the trash. */
    public const string AUDIT_EXTERNAL_PURGED = 'cms.redirect.external_purged';

    /**
     * Write the system's row at $from (a slug move), unless a member's redirect to another website
     * holds that address. The row is read locked and written as read, so a member's save in between
     * is never overwritten; an insert that races a member's insert of the same address rolls back to
     * its savepoint and is decided again against the member's row (as updateOrCreate would).
     * Returns false when a member's redirect to another website holds the address (nothing written).
     *
     * @param  array<string, mixed>  $values
     */
    public static function writeSystemRow(string $locale, string $from, array $values): bool
    {
        $write = static function () use ($locale, $from, $values): bool {
            $held = self::query()->where('locale', $locale)->where('from_path', $from)->lockForUpdate()->first();
            if (self::leadsAway($held?->to_path)) {
                return false;
            }
            ($held ?? new self(['locale' => $locale, 'from_path' => $from]))->fill($values)->save();

            return true;
        };

        try {
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException) {
            return $write();
        }
    }

    /** Whether a stored destination leads to another website (any `https://` value, valid or not). */
    public static function leadsAway(mixed $to): bool
    {
        return is_string($to) && str_starts_with($to, self::EXTERNAL_PREFIX);
    }

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

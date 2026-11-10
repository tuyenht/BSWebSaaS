<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * GLOBAL table (D6). One thing that happened to a customer, for the platform console only: no
 * website code path writes or reads it. Rows are only ever added, so there is no updated_at.
 * kind is one of the KIND_* constants. meta holds, for a website's change, the website as it was
 * then (`site`: ulid, `site_name`) and, for an override, `feature` and `value` (null: cleared) —
 * never a bigint id.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property string|null $reason
 * @property int|null $actor_user_id
 * @property array{site?: string, site_name?: string, feature?: string, value?: bool|int|null}|null $meta
 * @property CarbonImmutable $created_at
 */
final class TenantHistory extends Model
{
    public const string KIND_PLAN_CHANGED = 'plan_changed';

    public const string KIND_CUSTOMER_CREATED = 'customer_created';

    public const string KIND_SITE_CREATED = 'site_created';

    public const string KIND_FEATURE_OVERRIDDEN = 'feature_overridden';

    public const string KIND_SUSPENDED = 'suspended';

    public const string KIND_RESUMED = 'resumed';

    /** Every kind the table's CHECK takes. */
    public const array KINDS = [
        self::KIND_PLAN_CHANGED, self::KIND_CUSTOMER_CREATED, self::KIND_SITE_CREATED,
        self::KIND_FEATURE_OVERRIDDEN, self::KIND_SUSPENDED, self::KIND_RESUMED,
    ];

    public const UPDATED_AT = null;

    protected $table = 'tenant_history';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}

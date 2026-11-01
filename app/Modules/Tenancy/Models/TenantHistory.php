<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * GLOBAL table (D6). One thing that happened to a customer, for the platform console only: no
 * website code path writes or reads it. Rows are only ever added, so there is no updated_at.
 * kind is one of: plan_changed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind
 * @property int|null $from_plan_id
 * @property int|null $to_plan_id
 * @property string|null $reason
 * @property int|null $actor_user_id
 * @property CarbonImmutable $created_at
 */
final class TenantHistory extends Model
{
    public const string KIND_PLAN_CHANGED = 'plan_changed';

    public const UPDATED_AT = null;

    protected $table = 'tenant_history';

    protected $guarded = [];
}

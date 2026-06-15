<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * TENANT table (site_id + RLS). The permanent "isolation canary" the Gate #1 tests
 * assert on. Not a business entity.
 *
 * @property int $id
 * @property int $site_id
 * @property string $value
 */
final class TenantProbe extends Model
{
    use BelongsToSite;

    protected $guarded = [];
}

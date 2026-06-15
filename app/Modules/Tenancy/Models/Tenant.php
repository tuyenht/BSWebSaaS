<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GLOBAL table (D6). A customer; owns 1..N sites.
 *
 * @property int $id
 * @property string $name
 * @property string $status
 */
final class Tenant extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }
}

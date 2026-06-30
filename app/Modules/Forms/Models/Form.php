<?php

namespace App\Modules\Forms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A form definition (e.g. the contact / quote form). Tenant table (site_id + RLS);
 * BelongsToSite auto-scopes + stamps site_id. Localized labels live in JSONB columns.
 *
 * @property int $site_id
 * @property string $ulid
 * @property string $key
 * @property string $status
 */
final class Form extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'success_message' => 'array',
            'notify_emails' => 'array',
            'settings' => 'array',
        ];
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}

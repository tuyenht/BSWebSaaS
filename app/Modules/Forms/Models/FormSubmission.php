<?php

namespace App\Modules\Forms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A captured lead. Tenant table (site_id + RLS) — a submission for site A is never
 * readable in site B's context. Typed name/email/phone are "graduated" for the inbox;
 * `data` JSONB keeps the full payload.
 *
 * @property int $site_id
 * @property string $ulid
 * @property int $form_id
 * @property string $status
 * @property bool $is_spam
 */
final class FormSubmission extends Model
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
            'data' => 'array',
            'is_spam' => 'boolean',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}

<?php

namespace App\Modules\Forms\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field of a form. Tenant table (site_id denormalized from the parent form for RLS).
 *
 * @property int $site_id
 * @property int $form_id
 * @property string $key
 * @property string $type
 * @property bool $is_required
 */
final class FormField extends Model
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
            'label' => 'array',
            'is_required' => 'boolean',
            'options' => 'array',
            'validation' => 'array',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}

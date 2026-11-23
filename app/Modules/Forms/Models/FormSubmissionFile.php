<?php

declare(strict_types=1);

namespace App\Modules\Forms\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One file a visitor sent with a lead («Tệp đính kèm»). Tenant table (site_id + RLS). Written only
 * by StoreLeadFile; a `stored` row's object sits on the private lead-files disk under storage_key
 * (LeadFileStorage), a `rejected` row has none. When its lead goes, form_submission_id turns NULL
 * (ON DELETE SET NULL) and forms:sweep-files deletes the object, then the row. Addressed outside by
 * its ulid only (D2).
 *
 * @property int $id
 * @property int $site_id
 * @property string $ulid
 * @property int|null $form_submission_id null once its lead is gone: waiting for the sweeper
 * @property string $field_key
 * @property string $original_name
 * @property string|null $mime
 * @property int $size_bytes
 * @property string|null $storage_key
 * @property string $status
 */
final class FormSubmissionFile extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    public const string STORED = 'stored';

    public const string REJECTED = 'rejected';

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'form_submission_id' => 'integer',
        ];
    }

    /** @return BelongsTo<FormSubmission, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }
}

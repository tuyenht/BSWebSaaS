<?php

namespace App\Modules\Forms\Models;

use App\Modules\Forms\Support\LeadSearchTerms;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A captured lead. Tenant table (site_id + RLS) — a submission for site A is never
 * readable in site B's context. Typed name/email/phone are "graduated" for the inbox;
 * `data` JSONB keeps the full payload. Its search words (LeadSearchTerms) are written here, in
 * the transaction of the save that stores or changes the name, email or phone.
 *
 * @property int $site_id
 * @property string $ulid
 * @property int $form_id
 * @property string $status
 * @property bool $is_spam
 * @property Carbon|null $deleted_at set while the lead sits in the Trash (LeadTrash); read through LeadListQuery::leads()
 */
final class FormSubmission extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected static function booted(): void
    {
        self::created(static function (self $lead): void {
            self::writeTerms($lead);
        });
        self::updated(static function (self $lead): void {
            if ($lead->wasChanged(['name', 'email', 'phone'])) {
                self::writeTerms($lead);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_spam' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    private static function writeTerms(self $lead): void
    {
        LeadSearchTerms::write($lead->site_id, Cast::int($lead->getKey()), LeadSearchTerms::of(
            Cast::stringOrNull($lead->getAttribute('name')),
            Cast::stringOrNull($lead->getAttribute('email')),
            Cast::stringOrNull($lead->getAttribute('phone')),
        ));
    }
}

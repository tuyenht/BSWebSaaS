<?php

namespace App\Modules\Forms\Models;

use App\Modules\Forms\Support\FormDraft;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
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

    /** The form every site is given (EnsureContactForm): it can be changed and switched off, never deleted. */
    public const string SYSTEM_KEY = 'contact';

    /** The key of the consent box every form ends with: the platform's sentence, never changed on the screen. */
    public const string CONSENT_KEY = 'consent';

    /**
     * The status of a form whose leads are being deleted in batches off the request
     * (DeleteLargeForm). Such a form takes no lead (the storefront reads only active forms) and
     * reads as gone everywhere: the global scope below hides it from every query but those that ask
     * for it by name (that job, forms:resume-deletes, the lead lists that leave its leads out).
     */
    public const string DELETING = 'deleting';

    /** The global scope that hides a form being deleted. */
    public const string NOT_DELETING = 'not_deleting';

    #[\Override]
    protected static function booted(): void
    {
        self::addGlobalScope(self::NOT_DELETING, static function (Builder $builder): void {
            $builder->where($builder->getModel()->getTable().'.status', '<>', self::DELETING);
        });
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
            'name' => 'array',
            'success_message' => 'array',
            'notify_emails' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * The confirmation message for the given locale from the per-locale `success_message` JSONB
     * (site default language fallback), or null when none is set — callers supply their own default.
     */
    public function successMessageFor(string $locale): ?string
    {
        $value = FormDraft::text($this->success_message, $locale);

        return $value !== '' ? $value : null;
    }

    public function isSystem(): bool
    {
        return $this->key === self::SYSTEM_KEY;
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

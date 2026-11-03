<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Support\ContactPhone;
use App\Modules\Tenancy\Support\CustomerCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * GLOBAL table (D6). A customer; owns 1..N sites.
 *
 * customer_no is the customer's number, drawn from a sequence by the column default; staff read it
 * as customer_code ("KH-000123", see CustomerCode).
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $status
 * @property int|null $plan_id
 * @property int $customer_no
 * @property string|null $contact_phone stored as +84901234567; set it in any typed form (ContactPhone)
 * @property-read string|null $customer_code null when customer_no was not selected
 */
final class Tenant extends Model
{
    use HasUlidColumn;
    use SoftDeletes;

    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    /** @return Attribute<string|null, never> */
    protected function customerCode(): Attribute
    {
        return Attribute::get(function (): ?string {
            $number = $this->getAttributes()['customer_no'] ?? null;

            return $number === null ? null : CustomerCode::format(Cast::int($number));
        });
    }

    /**
     * Stores what staff typed in its one international form; a blank value clears it. A value that
     * is not a phone number is kept as typed so the column's CHECK refuses it loudly (validate with
     * ContactPhoneRule first to give the user a plain message).
     *
     * @return Attribute<string|null, string|null>
     */
    protected function contactPhone(): Attribute
    {
        return Attribute::set(function (?string $value): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }

            return ContactPhone::normalize($value) ?? $value;
        });
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}

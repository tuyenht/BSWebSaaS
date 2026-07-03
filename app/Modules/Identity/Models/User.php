<?php

namespace App\Modules\Identity\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * Global identity (D6 — no site_id, no RLS). A single account spans the platform;
 * membership in a tenant is recorded in tenant_users, while per-site authority is
 * granted through RBAC (spatie teams = site_id), not on this model.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $email
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property array<string, mixed> $preferences
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlidColumn;
    use MustVerifyEmailTrait;
    use Notifiable;

    /**
     * preferences (per-user UI chrome, later rendered into class/data-* attributes)
     * stays out of mass assignment so it is written only through an explicit,
     * allow-list-validated setter — never a request-data spread.
     */
    #[\Override]
    protected $guarded = ['preferences'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users');
    }

    /**
     * @return HasMany<SocialIdentity, $this>
     */
    public function socialIdentities(): HasMany
    {
        return $this->hasMany(SocialIdentity::class);
    }

    /**
     * The User lives in a module namespace, so the factory can't be resolved by
     * Laravel's default convention — bind it explicitly.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'preferences' => 'array',
        ];
    }
}

<?php

namespace App\Modules\Identity\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
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
     * Users who hold any of $permissions on the current team (site): granted directly, or through
     * a role of that site or a role every site shares (site_id NULL). The same people as spatie's
     * permission() scope, asked by NAME through the same team-scoped relations: spatie's scope
     * first binds the id of every role on every site holding the permission — one parameter per
     * site, past PostgreSQL's 65,535 limit at scale — while this one binds a fixed handful. No team
     * set: nobody (fail closed). A name that does not exist: nobody, where spatie's scope throws.
     *
     * @param  Builder<self>  $query
     * @param  string|list<string>  $permissions
     */
    public function scopeHoldingPermission(Builder $query, string|array $permissions): void
    {
        if (getPermissionsTeamId() === null) {
            $query->whereRaw('false');

            return;
        }

        $guard = $this->getDefaultGuardName();
        $named = static fn (Builder $permission): Builder => $permission
            ->whereIn($permission->qualifyColumn('name'), (array) $permissions)
            ->where($permission->qualifyColumn('guard_name'), $guard);

        $query->where(static fn (Builder $holder): Builder => $holder
            ->whereHas('permissions', $named)
            ->orWhereHas('roles', static fn (Builder $role): Builder => $role->whereHas('permissions', $named)));
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

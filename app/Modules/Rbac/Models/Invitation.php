<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An invitation to join the current site with one or more roles (design.md §15.3). Only the
 * SHA-256 of the mailed token is stored. State is read off the timestamps — see isPending().
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property string $email
 * @property string $token_hash
 * @property string|null $message
 * @property int|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $accepted_user_id
 * @property CarbonImmutable|null $revoked_at
 */
class Invitation extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    #[\Override]
    protected $guarded = [];

    /** Hash the mailed token the way it is stored. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Invitations still waiting for an answer: neither accepted nor revoked, and not expired.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->expires_at->isFuture();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'invitation_roles')->withPivot('site_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Global (D6 — no site_id, no RLS) link from a user to a provider's stable subject id (sub),
 * established when an authenticated member connects a social provider. Social login matches by
 * (provider, sub). `sub`/`email` are public identifiers (not secrets) — stored plaintext.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $sub
 * @property string|null $email
 */
class SocialIdentity extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One in-app notification for one person on one site. `data` holds the facts; summary() turns
 * them into the sentence in the current (reader's) language.
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property int $user_id
 * @property string $kind
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
class SiteNotification extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    public const UPDATED_AT = null;

    #[\Override]
    protected $guarded = [];

    /** The sentence the reader sees, e.g. "Anh Nam giao bài «Bàn giao dây chuyền» cho bạn". */
    public function summary(): string
    {
        $placeholders = [];
        foreach ($this->data as $key => $value) {
            if (is_scalar($value)) {
                $placeholders[$key] = (string) $value;
            }
        }

        $key = 'notifications.'.$this->kind;
        $line = __($key, $placeholders);

        return is_string($line) ? $line : $key;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}

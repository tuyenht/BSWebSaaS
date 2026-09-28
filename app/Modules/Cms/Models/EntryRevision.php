<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One locale's content of an entry at one moment (design.md §8). Written only by
 * RecordEntryRevision, read by EntryRevisions, written back by RestoreEntryRevision; never edited.
 * Addressed from outside by `ulid` only.
 *
 * @property int $id
 * @property string $ulid
 * @property int $site_id
 * @property int $entry_id
 * @property string $locale
 * @property RevisionKind $kind
 * @property array<string, mixed> $snapshot
 * @property int|null $created_by
 * @property CarbonImmutable $created_at
 */
class EntryRevision extends Model
{
    use BelongsToSite;
    use HasUlidColumn;

    public const UPDATED_AT = null;

    #[\Override]
    protected $guarded = [];

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }

    /**
     * Who saved the version; null for the scheduler or a removed user.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'kind' => RevisionKind::class,
            'snapshot' => 'array',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}

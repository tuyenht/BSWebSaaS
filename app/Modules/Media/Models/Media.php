<?php

namespace App\Modules\Media\Models;

use App\Modules\Shared\Concerns\HasUlidColumn;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A media master (design.md §16 / ADR-17). Tenant table (site_id + RLS); BelongsToSite auto-scopes
 * + stamps site_id. storage_key (content-hash) is the source of truth — the public URL is derived
 * by MediaUrlResolver, never stored. alt/title are per-locale JSONB. Soft-deleted (Thùng rác).
 *
 * @property int $site_id
 * @property string $ulid
 * @property string $collection
 * @property string|null $slug
 * @property string $hash
 * @property string $storage_key
 * @property string $disk
 * @property string $mime
 * @property int $size
 * @property string|null $original_filename
 * @property int|null $folder_id
 * @property int $position
 * @property array<string, string> $alt
 * @property array<string, string> $title
 */
final class Media extends Model
{
    use BelongsToSite;
    use HasUlidColumn;
    use SoftDeletes;

    /** Sniffed mime → the extension label the library groups/badges by ("Định dạng" filter). */
    public const array MIME_EXT_LABELS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'audio/mpeg' => 'mp3',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    /** Explicit so the irregular plural of "Media" never surprises the table name. */
    #[\Override]
    protected $table = 'media';

    #[\Override]
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'alt' => 'array',
            'title' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * Every soft attachment of this master (a mediable points back via media_id).
     *
     * @return HasMany<Mediable, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Mediable::class);
    }

    /**
     * The library folder this master lives in (null = the root "Tất cả ảnh" view).
     *
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /** Extension badge for this master ("jpg", "pdf"…) — unmapped mimes fall back to the subtype. */
    public function extLabel(): string
    {
        return self::MIME_EXT_LABELS[$this->mime] ?? Str::after($this->mime, '/');
    }
}

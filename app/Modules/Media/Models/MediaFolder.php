<?php

namespace App\Modules\Media\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A flat per-site folder organizing the media library (the picker's sidebar tree). Tenant table
 * (site_id + RLS); BelongsToSite auto-scopes + stamps. Deleting a folder never deletes its files —
 * the media.folder_id FK SET NULLs them back to the root "Tất cả ảnh" view.
 *
 * @property int $site_id
 * @property string $name
 * @property string $slug
 * @property int $position
 */
final class MediaFolder extends Model
{
    use BelongsToSite;

    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<Media, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }
}

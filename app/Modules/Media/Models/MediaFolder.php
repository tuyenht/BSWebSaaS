<?php

namespace App\Modules\Media\Models;

use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A flat per-site folder organizing the media library (the picker's sidebar tree). Tenant table
 * (site_id + RLS); BelongsToSite auto-scopes + stamps. Deleting a folder never deletes its files —
 * the media.folder_id FK SET NULLs them back to the root "Tất cả ảnh" view. `name` is free Unicode;
 * `slug` is ASCII [a-z0-9-], unique per site, and used ONLY for admin URLs/filters — image links
 * derive from media.storage_key, so renaming a folder never breaks an inserted image.
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

    /**
     * The auto-slug for a folder name, for the realtime preview (NO dedup yet — uniqueSlug does
     * that at save). Str::slug transliterates Vietnamese ("Sản phẩm" → san-pham, đ → d). A name
     * that is all ideographs / emoji / punctuation slugs to '' → the caller shows the fallback.
     *
     * @return array{slug: string, fallback: bool}
     */
    public function suggestSlugBase(string $name): array
    {
        $base = Str::slug($name);

        return $base === ''
            ? ['slug' => 'thu-muc-'.(self::query()->count() + 1), 'fallback' => true]
            : ['slug' => $base, 'fallback' => false];
    }

    /**
     * Force a candidate to the ASCII [a-z0-9-] shape, then make it unique within the site by
     * appending -2, -3… (WP-style). Empty after sanitizing → the name-based fallback.
     */
    public function uniqueSlug(string $candidate, string $name): string
    {
        $base = Str::slug($candidate);
        if ($base === '') {
            $base = $this->suggestSlugBase($name)['slug'];
        }

        $slug = $base;
        for ($i = 2; self::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}

<?php

namespace App\Modules\Media\Models;

use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * that at save). Slugger reads any writing system out in Latin letters ("Sản phẩm" → san-pham,
     * "产品" → chan-pin). A name that is all emoji / punctuation, or has no Latin reading, slugs
     * to '' → the caller shows the fallback.
     *
     * @return array{slug: string, fallback: bool}
     */
    public function suggestSlugBase(string $name): array
    {
        $base = Slugger::make($name);

        return $base === '' || Slugger::needsManualInput($name)
            ? ['slug' => 'thu-muc-'.(self::query()->count() + 1), 'fallback' => true]
            : ['slug' => $base, 'fallback' => false];
    }

    /**
     * Force a candidate to the ASCII [a-z0-9-] shape, then make it unique within the site by
     * appending -2, -3… (WP-style). Empty after sanitizing, or the name itself when its Latin
     * reading would be wrong (kanji in Japanese) → the name-based fallback, as the preview showed.
     */
    public function uniqueSlug(string $candidate, string $name): string
    {
        $base = Slugger::make($candidate);
        if ($base === '' || ($candidate === $name && Slugger::needsManualInput($name))) {
            $base = $this->suggestSlugBase($name)['slug'];
        }

        $slug = $base;
        for ($i = 2; self::query()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}

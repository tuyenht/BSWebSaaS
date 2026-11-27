<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Media\Models\Media;
use Illuminate\Database\Eloquent\Builder;

/**
 * The share picture an editor picked for one language of a page or post (entry_translations.og_image_id):
 * the storage key of the live file of the current website it names, else null (none picked, or the file is
 * trashed, purged or another website's), and the reader then falls back to the cover.
 *
 * Read the way the cover is: a correlated subselect (keySubquery) rides the reader's own read of the
 * translations, so a page costs no extra query; a translation read without it is looked up once (key()).
 *
 * Stateless, so it is Octane-safe.
 */
final class EntryShareImage
{
    /** The column the subselect adds: the storage key the translation's og_image_id names. */
    public const string KEY = 'og_key';

    /**
     * For addSelect([EntryShareImage::KEY => EntryShareImage::keySubquery()]) on entry_translations. The Media
     * scopes add site_id = ? and deleted_at IS NULL; the lookup is the media primary key.
     *
     * @return Builder<Media>
     */
    public static function keySubquery(): Builder
    {
        return Media::query()
            ->select('media.storage_key')
            ->whereColumn('media.id', 'entry_translations.og_image_id')
            ->whereColumn('media.site_id', 'entry_translations.site_id')
            ->limit(1);
    }

    /** The picked share picture's storage key, or null. */
    public static function key(EntryTranslation $translation): ?string
    {
        $id = $translation->getAttribute('og_image_id');
        if (! is_int($id)) {
            return null;
        }
        if (! array_key_exists(self::KEY, $translation->getAttributes())) {
            $found = Media::query()->whereKey($id)->value('storage_key');

            return is_string($found) && $found !== '' ? $found : null;
        }
        $key = $translation->getAttribute(self::KEY);

        return is_string($key) && $key !== '' ? $key : null;
    }
}

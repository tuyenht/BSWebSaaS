<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\ContentPack;

use App\Modules\Media\Actions\UploadMedia;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The image step of a content pack (`site:load-content --media`): every slot of media.json that
 * names a file in the pack becomes a media row of the site, through the media library's own upload
 * path (UploadMedia: magic-byte MIME sniffing, the upload whitelist, the content-hash key, one row
 * per (collection, sha256)). A file the site already has (same bytes, same collection) is reused —
 * no second row and no second object. A match in the Trash is reported and left there, unless the
 * slot is forced with `--force-item=media:<slot>`. Uploaded rows get the slot's alt text and no
 * uploader (`created_by` null); an existing row's alt text is the site's own and never rewritten.
 * A slot of `"kind": "document"` holds a PDF and takes the same path (the library stores a PDF as a
 * download); an image slot holds only a JPEG, PNG or WebP image.
 *
 * Storage keys: new keys are prefixed with the slug of the site's name (MediaKey), so two websites
 * whose names give the same slug would file images in the same folder and could overwrite each
 * other. The step therefore refuses, before planning anything, when another website's name gives
 * this site's folder; it refuses a planned key that does not start with that folder, and a new key
 * whose object already exists (no row of this site owns it, so it is someone else's). A refusal
 * never names the other website. A live row whose object went missing is repaired (the upload's
 * own self-heal puts the bytes back under the row's key).
 *
 * R2 writes are not transactional, so the step runs on its own, before any content load: a failed
 * run leaves at most an object the next run reuses by hash. A dry run reads only.
 */
final readonly class MediaStep
{
    public const string UPLOAD = 'upload';

    public const string REUSE = 'reuse';

    public const string IN_TRASH = 'in-trash';

    public const string RESTORE = 'restore';

    public const string REPAIR = 'repair';

    /** The verdicts that write: an object, a row, or both. */
    public const array WRITES = [self::UPLOAD, self::RESTORE, self::REPAIR];

    /** Forced keys of this step: `media:<slot>`. */
    public const string KEY_PREFIX = 'media:';

    public function __construct(private UploadMedia $upload) {}

    /**
     * The folder this site's new media keys start with under the current key layout.
     *
     * @throws PackRejected the site's name gives no folder
     */
    public function folder(Site $site): string
    {
        $slug = Str::slug($site->name);
        if ($slug === '') {
            throw new PackRejected('Refused: this site\'s name gives no storage folder for its images; nothing was uploaded.');
        }

        return $slug.'/';
    }

    /**
     * Refuses when any other website — in the Trash too, its images stay in storage — has a name
     * that gives the same folder. A platform-level read of names only (the sites registry has no
     * RLS), streamed in id order; nothing about the other website is printed.
     *
     * @throws PackRejected
     */
    public function assertFolderNotShared(Site $site): void
    {
        $folder = $this->folder($site);
        $others = Site::query()->withTrashed()->whereKeyNot($site->id)->select(['id', 'name'])->lazyById(1000);
        foreach ($others as $other) {
            if (Str::slug($other->name).'/' === $folder) {
                throw new PackRejected("Refused: another website files its images under the same storage folder ('{$folder}') as this site, so their images could overwrite each other. Nothing was uploaded; the image step can run once every website has its own storage folder.");
            }
        }
    }

    /**
     * What each slot with a file would do, in media.json order. Runs in the site's tenant context
     * and reads only: one lookup of the site's media by hash, the free-key check of each new upload
     * (the same MediaKey::unique the upload makes) and an existence check of each planned object.
     *
     * @param  list<string>  $forced  `media:<slot>` keys
     * @return list<array{key: string, slot: string, file: string, size: int, collection: string, hash: string, verdict: string, forced: bool, storage_key: string}>
     *
     * @throws PackRejected a planned key lands outside the site's folder, would overwrite an object no
     *                      row of the site owns, or is planned twice
     */
    public function plan(Pack $pack, Site $site, array $forced): array
    {
        $folder = $this->folder($site);
        $slots = [];
        foreach ($pack->slots as $name => $slot) {
            $file = $slot['file'] ?? null;
            if (! is_string($file) || $file === '') {
                continue;
            }
            $path = $pack->dir.'/'.$file;
            $slots[$name] = [
                'file' => $file,
                'path' => $path,
                'collection' => Cast::string($slot['collection'] ?? 'library'),
                'hash' => (string) hash_file('sha256', $path),
                'size' => (int) filesize($path),
                'document' => PackValidator::isDocument($slot),
            ];
        }

        $hashes = array_values(array_unique(array_column($slots, 'hash')));
        $existing = [];
        if ($hashes !== []) {
            $rows = Media::query()->withTrashed()->whereIn('hash', $hashes)->get(['id', 'collection', 'hash', 'storage_key', 'deleted_at']);
            foreach ($rows as $row) {
                $existing[$row->collection."\0".$row->hash] = $row;
            }
        }

        $disk = Storage::disk(MediaKey::disk());
        // Slots with the same bytes share one row, so forcing one of them restores it for all.
        $forcedAt = [];
        foreach ($slots as $name => $slot) {
            if (in_array(self::KEY_PREFIX.$name, $forced, true)) {
                $forcedAt[$slot['collection']."\0".$slot['hash']] = true;
            }
        }

        $lines = [];
        $planned = [];
        $newKeys = [];
        foreach ($slots as $name => $slot) {
            $key = self::KEY_PREFIX.$name;
            $at = $slot['collection']."\0".$slot['hash'];
            $match = $existing[$at] ?? null;
            $earlier = $planned[$at] ?? null;

            if ($earlier !== null) {
                // The same bytes as an earlier slot: that line writes (or leaves) the one row.
                $verdict = $earlier['verdict'] === self::IN_TRASH ? self::IN_TRASH : self::REUSE;
                $storageKey = $earlier['key'];
            } elseif ($match instanceof Media && $match->trashed()) {
                $verdict = isset($forcedAt[$at]) ? self::RESTORE : self::IN_TRASH;
                $storageKey = $match->storage_key;
            } elseif ($match instanceof Media) {
                // A live row whose object went missing gets the bytes back under its own key — the
                // upload's self-heal; otherwise nothing is written.
                $storageKey = $match->storage_key;
                $verdict = $disk->exists($storageKey) ? self::REUSE : self::REPAIR;
            } else {
                $verdict = self::UPLOAD;
                $storageKey = $this->newKey($site, $slot['collection'], $slot['path'], $slot['hash'], $slot['document']);
                if (isset($newKeys[$storageKey])) {
                    throw new PackRejected("Refused: {$key} and another slot would both be stored under '{$storageKey}'; rename one of the two files. Nothing was uploaded.");
                }
                // No row of this site holds the key, so an object already there is not this site's to
                // overwrite (left by a site that once had this site's folder name, for one) — unless
                // it holds these very bytes: what a run that failed after the put leaves behind.
                if ($disk->exists($storageKey) && hash('sha256', (string) $disk->get($storageKey)) !== $slot['hash']) {
                    throw new PackRejected("Refused: the image for {$key} would overwrite an object at '{$storageKey}' that no image of this site owns. Nothing was uploaded.");
                }
                $newKeys[$storageKey] = true;
            }

            // An upload, a restore and a repair can write an object (a restore puts the bytes back
            // when its object is gone); each must stay inside this site's folder.
            if (in_array($verdict, self::WRITES, true) && ! str_starts_with($storageKey, $folder)) {
                throw new PackRejected("Refused: the image for {$key} would be stored under '{$storageKey}', outside this site's storage folder '{$folder}'. Nothing was uploaded.");
            }
            $planned[$at] ??= ['key' => $storageKey, 'verdict' => $verdict];

            $lines[] = [
                'key' => $key,
                'slot' => $name,
                'file' => $slot['file'],
                'size' => $slot['size'],
                'collection' => $slot['collection'],
                'hash' => $slot['hash'],
                'verdict' => $verdict,
                'forced' => in_array($key, $forced, true),
                'storage_key' => $storageKey,
            ];
        }

        return $lines;
    }

    /**
     * The plan's fingerprint: the pack's content, the site, the forced keys and every line.
     *
     * @param  list<array{key: string, verdict: string, hash: string, storage_key: string}>  $lines
     * @param  list<string>  $forced
     */
    public static function fingerprint(Pack $pack, string $siteUlid, array $forced, array $lines): string
    {
        sort($forced, SORT_STRING);

        return substr(CanonicalHash::of([
            'step' => 'media',
            'pack' => [$pack->code, $pack->version, $pack->contentHash],
            'site' => $siteUlid,
            'forced' => $forced,
            'lines' => array_map(fn (array $line): array => [$line['key'], $line['verdict'], $line['hash'], $line['storage_key']], $lines),
        ]), 0, 12);
    }

    /**
     * Upload or restore one planned slot through UploadMedia, inside the site's tenant context.
     * Returns the row; a row the upload just created gets the slot's alt text.
     *
     * @param  array{key: string, slot: string, file: string, verdict: string, storage_key: string}  $line
     * @param  array<string, mixed>  $slot  the media.json slot
     *
     * @throws RuntimeException the row came back under a key other than the planned one
     */
    public function write(Pack $pack, Site $site, array $line, array $slot): Media
    {
        $path = $pack->dir.'/'.$line['file'];
        $file = new UploadedFile($path, basename($path), null, null, true);

        $media = $this->upload->handle(
            file: $file,
            siteName: $site->name,
            collection: Cast::string($slot['collection'] ?? 'library'),
            createdBy: null,
            mayRestore: $line['verdict'] === self::RESTORE,
        );

        if ($media->storage_key !== $line['storage_key'] || ! str_starts_with($media->storage_key, $this->folder($site))) {
            throw new RuntimeException("{$line['key']} was stored under an unplanned key.");
        }

        if ($media->wasRecentlyCreated && is_array($slot['alt'] ?? null)) {
            $alt = [];
            foreach ($slot['alt'] as $locale => $text) {
                if (is_string($locale) && is_string($text) && $text !== '') {
                    $alt[$locale] = $text;
                }
            }
            $media->alt = $alt;
            $media->save();
        }

        return $media;
    }

    /** The key UploadMedia gives these bytes: its slug from the file name, its extension from the sniffed type. */
    private function newKey(Site $site, string $collection, string $path, string $hash, bool $document): string
    {
        $mime = PackValidator::mime($path, $document);
        $ext = $mime === null ? null : (UploadMedia::MIME_EXT[$mime] ?? null);
        if ($ext === null) {
            throw new PackRejected('Refused: '.basename($path).' is not '.($document ? 'a PDF document' : 'an image').' the media library accepts.');
        }
        $slug = Slugger::make(pathinfo(basename($path), PATHINFO_FILENAME)) ?: 'tep';

        return MediaKey::unique($site->name, $collection, $slug, $hash, $ext);
    }
}

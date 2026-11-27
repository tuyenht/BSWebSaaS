<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\MediaBackfill;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Theming\HeadingDecor;
use stdClass;

/**
 * One batch of the media-refs backfill (MediaRefsBackfill), walked twice over the same rows: first
 * collecting every storage key the old shapes name (collect()), then, given the batch's one MediaRefs
 * lookup (resolve()), converting each value and counting what it did per place and kind. A value
 * already in the new shape is counted and never touched; a value that names no file of the website
 * (or a file of the wrong type) is left as it is and counted as unmatched with a few samples.
 *
 * The old shapes: a storage key, or an address on the media host whose path is one (an address
 * the page froze, MediaUrlResolver::keyFromUrl, query string dropped). A glyph name or an outside
 * address is no file and is kept.
 *
 * Beside the counts of what it converts, a line counts what the contract must still see: an old key
 * kept beside the new shape (stale: a cover id set and a key still in meta.image_url, a mask with a
 * ulid and a key), a block of a type the registry does not know (skipped, its values unread), and,
 * in the places no reader resolves an address from (address()), every file address found there.
 *
 * Built per batch, so it is Octane-safe.
 *
 * @phpstan-type Line array{converted: int, already: int, kept: int, unmatched: int, trashed: int, found: int, stale: int, skipped: int, samples: list<string>, trashed_samples: list<string>, notes: list<string>}
 */
final class BackfillPass
{
    /** The counters of a line (Line without its sample lists). */
    public const array COUNTERS = ['converted', 'already', 'kept', 'unmatched', 'trashed', 'found', 'stale', 'skipped'];

    /** @var array<string, true> the storage keys the old shapes name (collect) */
    public array $keys = [];

    /** @var list<int> media ids the current row now names by row (resolve), for the usage index */
    public array $named = [];

    /** Values the current row had converted (resolve). */
    public int $changed = 0;

    /** @var array<string, Line> */
    public array $lines = [];

    private ?MediaRefs $refs = null;

    public function __construct(private readonly MediaUrlResolver $urls) {}

    /** The second walk: values are converted from $refs and counted. */
    public function resolve(MediaRefs $refs): void
    {
        $this->refs = $refs;
        $this->lines = [];
    }

    /** Before each row of the second walk. */
    public function row(): void
    {
        $this->named = [];
        $this->changed = 0;
    }

    /**
     * A cover (entries, a draft's shared fields, a revision snapshot): the media id it now names, or
     * null to leave it. $coverId set means the new shape: counted, never touched; an old key still
     * beside it is counted as stale (the readers take the id; the key is left for the contract).
     */
    public function cover(string $place, mixed $coverId, mixed $value): ?int
    {
        if ($coverId !== null && $coverId !== '') {
            if (is_string($value) && $this->keyOf($value) !== null) {
                $this->tally($place, 'stale', "key beside the cover id: {$value}");
            } else {
                $this->tally($place, 'already');
            }

            return null;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->find($place, $value, null)?->id;
    }

    /** An image prop of a block: an id stays, an old key or address becomes its id. */
    public function image(string $place, mixed $value): mixed
    {
        if (is_int($value) && $value > 0) {
            $this->tally($place, 'already');

            return $value;
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $row = $this->find($place, $value, null);
        if ($row === null) {
            return $value;
        }
        $this->named[] = $row->id;

        return $row->id;
    }

    /**
     * A button's stored value: a "Tải tệp" button naming its file by key gets the row's ulid
     * (`media`) and loses `file`, only for a document of the website (ButtonLink::DOCUMENT_MIMES,
     * what the page and the editor's save accept).
     *
     * @param  array<array-key, mixed>  $do
     * @return array<array-key, mixed>
     */
    public function file(string $place, array $do): array
    {
        if (($do['k'] ?? null) !== 'file') {
            return $do;
        }
        $ref = ButtonLink::fileRef($do);
        if (isset($ref['media'])) {
            $this->tally($place, 'already');

            return $do;
        }
        if (! isset($ref['file'])) {
            return $do;
        }
        $row = $this->find($place, $ref['file'], ButtonLink::DOCUMENT_MIMES);
        if ($row === null) {
            return $do;
        }
        $this->named[] = $row->id;
        unset($do['file']);
        $do['media'] = strtoupper($row->ulid);

        return $do;
    }

    /**
     * The heading decoration's mask: `mask_media` (a key) becomes `mask_ulid`, only for a MASK_MIMES
     * file. A well-formed `mask_ulid` is the new shape (a key still beside it is stale); one that is
     * not a ulid is what the page ignores (HeadingDecor::clean), so the key is converted over it, and
     * with no key to convert it is counted as stale.
     */
    public function mask(string $place, stdClass $value): void
    {
        $ulid = $value->mask_ulid ?? null;
        $key = $value->mask_media ?? null;
        $hasKey = is_string($key) && $key !== '';
        if (is_string($ulid) && trim($ulid) !== '') {
            if (preg_match(ButtonLink::ULID_PATTERN, trim($ulid)) === 1) {
                $hasKey ? $this->tally($place, 'stale', "key beside the mask ulid: {$key}") : $this->tally($place, 'already');

                return;
            }
            if (! $hasKey) {
                $this->tally($place, 'stale', "not a ulid: {$ulid}");

                return;
            }
        }
        if (! $hasKey) {
            return;
        }
        $row = $this->find($place, $key, HeadingDecor::MASK_MIMES);
        if ($row === null) {
            return;
        }
        unset($value->mask_media);
        $value->mask_ulid = strtoupper($row->ulid);
    }

    /**
     * A brand image the general.brand setting still holds (an address on the media host, or a bare
     * key) beside the site profile's picture of the same slot: the row the profile must name for the
     * address to leave the setting, the shape the settings screen saves (SetBrandImages), or null to
     * leave both. Only a picture of the website moves (image/*, as the settings screen accepts), and
     * only when the profile names that same row or none: a profile naming another picture is the
     * owner's call (unmatched). An outside address is kept and noted (the page shows it until the
     * contract). When the address the page will print from the row (BrandImages, at the slot's
     * width) differs from the stored one, that is noted too.
     */
    public function brand(string $place, mixed $profileId, mixed $value, ?int $width): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $key = $this->keyOf($value);
        if ($this->refs === null) {
            if ($key !== null) {
                $this->keys[$key] = true;
            }

            return null;
        }
        if ($key === null) {
            $this->tally($place, 'kept', "not a file of the library, shown until the contract: {$value}");

            return null;
        }
        $row = $this->refs->byKey($key);
        if ($row === null || ! str_starts_with((string) $row->mime, 'image/')) {
            $this->tally($place, 'unmatched', $row === null ? $value : "{$value} ({$row->mime})");

            return null;
        }
        $named = MediaRefs::id($profileId);
        if ($named !== null && $named !== $row->id) {
            $this->tally($place, 'unmatched', "{$value} (the profile names another picture)");

            return null;
        }
        $this->tally($place, 'converted');
        if ($row->trashed()) {
            $this->tally($place, 'trashed', $key);
        }
        $shown = $this->urls->url($key, $width);
        if ($shown !== $value) {
            $this->lines[$place]['notes'] = self::sampled($this->lines[$place]['notes'], "the page's address becomes {$shown}");
        }
        $this->changed++;

        return $row->id;
    }

    /** A block of a type the registry does not know: its values are not read, so it is counted. */
    public function skipped(string $place, string $type): void
    {
        $this->tally($place, 'skipped', "block type {$type}");
    }

    /**
     * A string of a place no reader resolves a media address from (counted only, never written):
     * a bare storage key with a file name, or an address on the media host, is counted as found
     * when it names a file of the website (the Trash included) and as unmatched when it does not.
     */
    public function address(string $place, string $value): void
    {
        $key = $this->addressKey($value);
        if ($key === null) {
            return;
        }
        if ($this->refs === null) {
            $this->keys[$key] = true;

            return;
        }
        $this->refs->byKey($key) === null
            ? $this->tally($place, 'unmatched', $value)
            : $this->tally($place, 'found', "names a file by address: {$value}");
    }

    /**
     * The file an old value names, or null. While collecting, only its key is noted. While
     * resolving, it is counted: converted (and in the Trash, when it is), kept (no file: a glyph or
     * an outside address), or unmatched (no row of this website, or a row of another type).
     *
     * @param  list<string>|null  $mimes
     */
    private function find(string $place, string $value, ?array $mimes): ?Media
    {
        $key = $this->keyOf($value);
        if ($this->refs === null) {
            if ($key !== null) {
                $this->keys[$key] = true;
            }

            return null;
        }
        if ($key === null) {
            $this->tally($place, 'kept');

            return null;
        }
        $row = $this->refs->byKey($key);
        if ($row === null || ($mimes !== null && ! in_array($row->mime, $mimes, true))) {
            $this->tally($place, 'unmatched', $row === null ? $value : "{$value} ({$row->mime})");

            return null;
        }
        $this->tally($place, 'converted');
        if ($row->trashed()) {
            $this->tally($place, 'trashed', $key);
        }
        $this->changed++;

        return $row;
    }

    /** The storage key an old value names: the value itself, or the path of an address on the media host. */
    private function keyOf(string $value): ?string
    {
        if (MediaRefs::looksLikeKey($value)) {
            return $value;
        }
        $key = str_contains($value, '://') ? $this->urls->keyFromUrl($value) : null;

        return $key !== null && MediaRefs::looksLikeKey($key) ? $key : null;
    }

    /**
     * The storage key a free string names, stricter than keyOf() because these places hold any text:
     * a clean key path whose last part has an extension, or an address on the media host whose path is one.
     */
    private function addressKey(string $value): ?string
    {
        $key = str_contains($value, '://') ? $this->urls->keyFromUrl($value) : $value;

        return $key !== null && preg_match(MediaKey::CLEAN_PATH_PATTERN, $key) === 1 && preg_match('/\.[A-Za-z0-9]+\z/D', $key) === 1 ? $key : null;
    }

    /** @param  'converted'|'already'|'kept'|'unmatched'|'trashed'|'found'|'stale'|'skipped'  $what */
    private function tally(string $place, string $what, ?string $sample = null): void
    {
        $line = $this->lines[$place] ?? self::emptyLine();
        $line[$what]++;
        if ($sample !== null) {
            $list = match ($what) {
                'unmatched' => 'samples',
                'trashed' => 'trashed_samples',
                default => 'notes',
            };
            $line[$list] = self::sampled($line[$list], $sample);
        }
        $this->lines[$place] = $line;
    }

    /**
     * @param  list<string>  $samples
     * @return list<string> at most five, each once
     */
    private static function sampled(array $samples, string $sample): array
    {
        if (count($samples) < 5 && ! in_array($sample, $samples, true)) {
            $samples[] = $sample;
        }

        return $samples;
    }

    /** @return Line */
    public static function emptyLine(): array
    {
        return ['converted' => 0, 'already' => 0, 'kept' => 0, 'unmatched' => 0, 'trashed' => 0, 'found' => 0, 'stale' => 0, 'skipped' => 0, 'samples' => [], 'trashed_samples' => [], 'notes' => []];
    }
}

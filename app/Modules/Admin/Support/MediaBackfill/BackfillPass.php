<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\MediaBackfill;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaUrlResolver;
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
 * Built per batch, so it is Octane-safe.
 */
final class BackfillPass
{
    /** @var array<string, true> the storage keys the old shapes name (collect) */
    public array $keys = [];

    /** @var list<int> media ids the current row now names by row (resolve), for the usage index */
    public array $named = [];

    /** Values the current row had converted (resolve). */
    public int $changed = 0;

    /** @var array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}> */
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
     * null to leave it. $coverId set means the new shape: counted, never touched.
     */
    public function cover(string $place, mixed $coverId, mixed $value): ?int
    {
        if ($coverId !== null && $coverId !== '') {
            $this->tally($place, 'already');

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

    /** The heading decoration's mask: `mask_media` (a key) becomes `mask_ulid`, only for a MASK_MIMES file. */
    public function mask(string $place, stdClass $value): void
    {
        $ulid = $value->mask_ulid ?? null;
        if (is_string($ulid) && trim($ulid) !== '') {
            $this->tally($place, 'already');

            return;
        }
        $key = $value->mask_media ?? null;
        if (! is_string($key) || $key === '') {
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

    /** @param  'converted'|'already'|'kept'|'unmatched'|'trashed'  $what */
    private function tally(string $place, string $what, ?string $sample = null): void
    {
        $line = $this->lines[$place] ?? self::emptyLine();
        $line[$what]++;
        if ($sample !== null && $what === 'trashed') {
            $line['trashed_samples'] = self::sampled($line['trashed_samples'], $sample);
        } elseif ($sample !== null) {
            $line['samples'] = self::sampled($line['samples'], $sample);
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

    /** @return array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>} */
    public static function emptyLine(): array
    {
        return ['converted' => 0, 'already' => 0, 'kept' => 0, 'unmatched' => 0, 'trashed' => 0, 'samples' => [], 'trashed_samples' => []];
    }
}

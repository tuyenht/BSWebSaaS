<?php

declare(strict_types=1);

namespace App\Modules\Media\Services;

use App\Modules\Media\Support\MediaKey;
use App\Modules\Media\Support\MediaKeyRewriter;
use App\Modules\Shared\Support\Cast;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Moves a website's files filed before website codes (`an-vinh/hero/x-ab12.jpg`,
 * `{slug(name)}/library/x-ab12.jpg`) to `{code}/{file}`, the address every new upload already gets,
 * and rewrites the places that name a file by its key instead of its id. The steps, which the
 * media:rekey-site command runs in this order:
 *
 * 1. plan(): every media row of the website, the Trash included, whose key is not under its code,
 *    with its new key: the same file name under the code; on a clash with a key the website already
 *    holds (under any extension, as MediaKey::unique() judges a stem) the hash suffix grows.
 * 2. copyObjects(): a server-side copy of each object to its new key, outside any transaction; the
 *    old object stays, so every address already printed or shared keeps working. A copy already
 *    there (a resumed run) is kept when it has the source's size, and refused otherwise.
 * 3. moveKeys() and rewriteReferences(), in ONE transaction under the website's tenant context: the
 *    rows still holding their planned old key get the new one (the database guard accepts only the
 *    website's own code), then every listed JSON column of the website is rewritten.
 *
 * A row whose key is already under the code is never planned, so a second run finds nothing and
 * any interrupted run can simply be run again. Single-site installs keep the collection layout
 * (`media.tenant_prefix` false) and are never rekeyed.
 *
 * Stateless; Octane-safe.
 */
final readonly class SiteMediaRekey
{
    /** Rows read, copied or written at a time. */
    public const int CHUNK = 500;

    /** A table or column name the reference scan may be given (they are code constants, never input). */
    private const string IDENTIFIER = '/\A[a-z_][a-z0-9_]*\z/';

    /**
     * Every row of the website whose key is not under its code, with its new key. Runs inside the
     * website's tenant context; reads only.
     *
     * @return list<array{id: int, from: string, to: string, mime: string}>
     */
    public function plan(int $siteId, string $code): array
    {
        if (preg_match(MediaKey::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException("'{$code}' is not a website code.");
        }

        $moves = [];
        $assigned = [];
        $lastId = 0;
        do {
            $rows = DB::table('media')
                ->where('site_id', $siteId)
                ->where('id', '>', $lastId)
                ->whereRaw("split_part(storage_key, '/', 1) <> ?", [$code])
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'storage_key', 'hash', 'mime']);

            foreach ($rows as $row) {
                $id = Cast::int($row->id);
                $from = Cast::string($row->storage_key);
                $to = $this->target($siteId, $code, $from, Cast::string($row->hash), $assigned);
                $assigned[self::stem($to)] = true;
                $moves[] = ['id' => $id, 'from' => $from, 'to' => $to, 'mime' => Cast::string($row->mime, 'application/octet-stream')];
                $lastId = $id;
            }
        } while ($rows->count() === self::CHUNK);

        return $moves;
    }

    /**
     * Copies each planned object to its new key, a batch at a time ($progress gets the number done).
     *
     * @param  list<array{id: int, from: string, to: string, mime: string}>  $moves
     * @return array{copied: int, present: int, missing: list<string>}
     */
    public function copyObjects(array $moves, ?Closure $progress = null): array
    {
        $disk = Storage::disk(MediaKey::disk());
        $copied = 0;
        $present = 0;
        $missing = [];

        foreach (array_chunk($moves, self::CHUNK) as $batch) {
            foreach ($batch as $move) {
                $hasSource = $disk->exists($move['from']);
                if ($disk->exists($move['to'])) {
                    // What an interrupted run leaves behind; anything else at that key is not ours to reuse.
                    if ($hasSource && $disk->size($move['from']) !== $disk->size($move['to'])) {
                        throw new RuntimeException("A different file already sits at '{$move['to']}' (the new key of '{$move['from']}'); nothing was written to the database. Remove that object, then run again.");
                    }
                    $present++;

                    continue;
                }
                if (! $hasSource) {
                    $missing[] = $move['from']; // already gone from storage: only the key moves

                    continue;
                }
                if (! MediaKey::copyAs($disk, $move['from'], $move['to'], $move['mime'])) {
                    throw new RuntimeException("Copying '{$move['from']}' to '{$move['to']}' failed; nothing was written to the database. Run again.");
                }
                $copied++;
            }
            if ($progress !== null) {
                $progress($copied + $present + count($missing));
            }
        }

        return ['copied' => $copied, 'present' => $present, 'missing' => $missing];
    }

    /**
     * Gives each planned row its new key, inside the caller's transaction under the website's tenant
     * context. A row is locked first and moved only while it still holds its planned old key (one a
     * person changed meanwhile waits for the next run).
     *
     * @param  list<array{id: int, from: string, to: string, mime: string}>  $moves
     * @return array<string, string> old key => new key, for the rows moved
     */
    public function moveKeys(int $siteId, array $moves): array
    {
        $moved = [];
        foreach (array_chunk($moves, self::CHUNK) as $batch) {
            $held = DB::table('media')
                ->where('site_id', $siteId)
                ->whereIn('id', array_column($batch, 'id'))
                ->lockForUpdate()
                ->pluck('storage_key', 'id')
                ->all();

            $values = [];
            $bindings = [];
            foreach ($batch as $move) {
                if (($held[$move['id']] ?? null) !== $move['from']) {
                    continue;
                }
                $values[] = '(?::bigint, ?::text, ?::text)';
                array_push($bindings, $move['id'], $move['from'], $move['to']);
                $moved[$move['from']] = $move['to'];
            }
            if ($values === []) {
                continue;
            }

            DB::update(
                'UPDATE media AS m SET storage_key = v.to_key FROM (VALUES '.implode(', ', $values).') AS v(id, from_key, to_key) '.
                'WHERE m.site_id = ? AND m.id = v.id AND m.storage_key = v.from_key',
                [...$bindings, $siteId],
            );
        }

        return $moved;
    }

    /**
     * Rewrites (or with $write false only counts) the moved keys in each JSON column of the website.
     * Only rows whose text mentions one of the old folders are read, a chunk at a time by id.
     *
     * @param  list<array{0: string, 1: string}>  $places  [table, jsonb column]; every table has id + site_id
     * @return array<string, array{rows: int, references: int, unmatched: int, samples: list<string>}>
     */
    public function rewriteReferences(int $siteId, MediaKeyRewriter $rewriter, array $places, bool $write): array
    {
        $patterns = array_map(
            static fn (string $folder): string => '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folder).'/%',
            $rewriter->folders(),
        );

        $report = [];
        foreach ($places as [$table, $column]) {
            if (preg_match(self::IDENTIFIER, $table) !== 1 || preg_match(self::IDENTIFIER, $column) !== 1) {
                throw new InvalidArgumentException("Not a table and column: {$table}.{$column}.");
            }
            $line = ['rows' => 0, 'references' => 0, 'unmatched' => 0, 'samples' => []];
            if ($patterns !== []) {
                $this->rewritePlace($siteId, $rewriter, $table, $column, $patterns, $write, $line);
            }
            $report["{$table}.{$column}"] = $line;
        }

        return $report;
    }

    /**
     * @param  list<string>  $patterns
     * @param  array{rows: int, references: int, unmatched: int, samples: list<string>}  $line
     */
    private function rewritePlace(int $siteId, MediaKeyRewriter $rewriter, string $table, string $column, array $patterns, bool $write, array &$line): void
    {
        $mentions = implode(' OR ', array_fill(0, count($patterns), "{$column}::text LIKE ?"));
        $lastId = 0;
        do {
            $rows = DB::select(
                "SELECT id, {$column}::text AS doc FROM {$table} WHERE site_id = ? AND id > ? AND ({$mentions}) ORDER BY id LIMIT ".self::CHUNK,
                [$siteId, $lastId, ...$patterns],
            );

            foreach ($rows as $row) {
                if (! is_object($row) || ! isset($row->id, $row->doc)) {
                    continue;
                }
                $lastId = Cast::int($row->id);
                $document = json_decode(Cast::string($row->doc), false, 512, JSON_THROW_ON_ERROR);
                $rewritten = $rewriter->rewrite($document);

                $line['unmatched'] += count($rewriter->unmatched);
                foreach ($rewriter->unmatched as $sample) {
                    if (count($line['samples']) < 5 && ! in_array($sample, $line['samples'], true)) {
                        $line['samples'][] = $sample;
                    }
                }
                if ($rewriter->rewritten === 0) {
                    continue;
                }
                $line['rows']++;
                $line['references'] += $rewriter->rewritten;

                if ($write) {
                    DB::update(
                        "UPDATE {$table} SET {$column} = ?::jsonb WHERE site_id = ? AND id = ?",
                        [json_encode($rewritten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $siteId, $lastId],
                    );
                }
            }
        } while (count($rows) === self::CHUNK);
    }

    /**
     * The new key of $from: its file name under the code, the hash suffix grown while the stem is
     * taken. The suffix grows along the row's content hash when that hash starts with it (a key
     * the upload made), else along the suffix followed by the row's hash; a name without a suffix
     * gets one.
     *
     * @param  array<string, true>  $assigned  stems given to earlier rows of this plan
     */
    private function target(int $siteId, string $code, string $from, string $rowHash, array $assigned): string
    {
        $file = basename($from);
        if (preg_match('/\A(?<name>.+?)(?:-(?<hex>[0-9a-f]{4,64}))?\.(?<ext>[A-Za-z0-9]+)\z/D', $file, $parts) !== 1) {
            throw new RuntimeException("The key '{$from}' has no file name with an extension; re-key it by hand.");
        }
        $name = $parts['name'];
        $hex = $parts['hex'];
        $ext = $parts['ext'];

        $key = "{$code}/{$file}";
        if (! $this->stemTaken($siteId, self::stem($key), $ext, $assigned)) {
            MediaKey::assertCleanPath($key);

            return $key;
        }

        $hash = preg_match('/\A[0-9a-f]{64}\z/D', $rowHash) === 1 ? $rowHash : hash('sha256', $from);
        $source = $hex !== '' && str_starts_with($hash, $hex) ? $hash : $hex.$hash;
        for ($length = max(strlen($hex) + 1, MediaKey::MIN_HASH_LENGTH); $length <= strlen($source); $length++) {
            $key = "{$code}/{$name}-".substr($source, 0, $length).".{$ext}";
            if (! $this->stemTaken($siteId, self::stem($key), $ext, $assigned)) {
                MediaKey::assertCleanPath($key);

                return $key;
            }
        }

        throw new RuntimeException("No free key under '{$code}/' for '{$from}'.");
    }

    /** @param  array<string, true>  $assigned */
    private function stemTaken(int $siteId, string $stem, string $ext, array $assigned): bool
    {
        if (isset($assigned[$stem])) {
            return true;
        }

        $keys = array_map(static fn (string $extension): string => "{$stem}.{$extension}", array_values(array_unique([...MediaKey::EXTENSIONS, $ext])));

        return DB::table('media')->where('site_id', $siteId)->whereIn('storage_key', $keys)->exists();
    }

    /** A key without its extension. */
    private static function stem(string $key): string
    {
        $dot = strrpos($key, '.');

        return $dot === false ? $key : substr($key, 0, $dot);
    }
}

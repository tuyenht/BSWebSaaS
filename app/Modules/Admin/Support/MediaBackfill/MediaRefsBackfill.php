<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\MediaBackfill;

use App\Modules\Admin\Support\Settings\HeadingDecorInput;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\BlockMediaUsage;
use App\Modules\Cms\Support\EntryCoverUsage;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Media\Services\SiteMediaRekey;
use App\Modules\Media\Support\MediaRefs;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Components\SchemaImages;
use App\Modules\Theme\Rendering\LayoutSlots;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;
use Throwable;

/**
 * Converts one website's stored media references from the old shapes (a storage key, or an address
 * on the media host) to the row: an entry's cover (entries.meta.image_url → entries.cover_media_id,
 * and the same pair inside its pending change or draft and its history), the image props and "Tải
 * tệp" buttons of blocks (live bodies, shared blocks, a pending change's and a revision's bodies,
 * the block slots of the website and of each category), and the heading decoration's mask. Image
 * props and covers become the bigint id (typed columns and the image-prop contract, which never
 * reach the browser as such), buttons and the mask the row ulid.
 *
 * Every lookup goes through MediaRefs (one query per batch, the Trash included and FOR KEY SHARE
 * when writing). A file in the Trash is converted like any other (so a picture in the Trash stops
 * showing, as block pictures already do) and counted apart, so the owner can be told before the
 * first real run. A value already in the new shape is never touched; one that names no file of
 * the website, or a file of another type, is left as it is and counted as unmatched.
 *
 * Per place, BATCH rows by id (keyset) per tenant transaction: in a real run the rows are locked
 * FOR NO KEY UPDATE, then the media rows FOR KEY SHARE (the order an entry save takes them), and a
 * batch that lost a lock conflict is run again whole, TRIES times at most (as SiteMediaRekey). A
 * dry run reads without locks and writes nothing. Writes skip the model events (no updated_at, no
 * revision, no content mark: the page shows the same picture): a page body through a quiet save of
 * its model, so its sanitizing cast still runs, the other places one statement per batch. A real run keeps the usage index
 * in the same transaction (a block picture now named by id is recorded as used, BlockMediaUsage's
 * collection; the mask's row through HeadingDecorInput), then rebuilds the cover usage of the
 * website (EntryCoverUsage::reconcileSite). Running it again converts nothing.
 *
 * Stateless (a pass object per batch), so it is Octane-safe.
 */
final readonly class MediaRefsBackfill
{
    /** Rows read per transaction. */
    public const int BATCH = 500;

    /** Tries of one batch that lost a lock conflict, the first included. */
    public const int TRIES = 3;

    /** The JSON places: report name => [table, jsonb column, owner morph alias for the usage index, owner id column]. */
    public const array DOCS = [
        'entry_drafts' => ['entry_drafts', 'content', null, null],
        'entry_revisions' => ['entry_revisions', 'snapshot', null, null],
        'entry_translations' => ['entry_translations', 'body', 'entry', 'entry_id'],
        'content_block_translations' => ['content_block_translations', 'body', 'content_block', 'content_block_id'],
        'taxonomies' => ['taxonomies', 'meta', 'taxonomy', 'id'],
        'site_settings' => ['site_settings', 'value', 'site_setting', 'id'],
    ];

    /** The places a visitor sees: a picture of these in the Trash stops showing once converted. */
    public const array SHOWN = ['entries', 'entry_translations', 'content_block_translations', 'taxonomies', 'site_settings'];

    public function __construct(private Tenancy $tenancy, private ComponentRegistry $registry, private MediaUrlResolver $urls) {}

    /**
     * Converts (or with $apply false only counts) the website's places.
     *
     * @return array{lines: array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}>, rows: array<string, int>, covers: array{inserted: int, deleted: int}|null}
     */
    public function site(int $siteId, bool $apply): array
    {
        $lines = [];
        $rows = [];
        $this->entries($siteId, $apply, $lines, $rows);
        foreach (self::DOCS as $place => [$table, $column, $owner, $ownerColumn]) {
            $this->docs($siteId, $apply, $place, $table, $column, $owner, $ownerColumn, $lines, $rows);
        }
        $covers = $apply ? $this->tenancy->enter($siteId, static fn (): array => EntryCoverUsage::reconcileSite($siteId)) : null;

        ksort($lines);

        return ['lines' => $lines, 'rows' => $rows, 'covers' => $covers];
    }

    /**
     * entries: a cover named by key in meta.image_url becomes cover_media_id, the key leaving the
     * meta in the same statement (writers never keep one beside an id). Only entries with a cover
     * are read; the Trash included.
     *
     * @param  array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}>  $lines
     * @param  array<string, int>  $rows
     */
    private function entries(int $siteId, bool $apply, array &$lines, array &$rows): void
    {
        $lastId = 0;
        do {
            [$count, $lastId, $pass, $written] = $this->batch($siteId, function () use ($siteId, $apply, $lastId): array {
                $found = self::objects(DB::select(
                    "SELECT id, cover_media_id, meta->'image_url' AS cover FROM entries WHERE site_id = ? AND id > ? AND (cover_media_id IS NOT NULL OR meta->'image_url' IS NOT NULL) ORDER BY id LIMIT ".self::BATCH.($apply ? ' FOR NO KEY UPDATE' : ''),
                    [$siteId, $lastId],
                ));
                $pass = new BackfillPass($this->urls);
                $read = static function (stdClass $row) use ($pass): ?int {
                    $cover = isset($row->cover) ? json_decode(Cast::string($row->cover), true) : null;

                    return $pass->cover('entries cover', $row->cover_media_id ?? null, $cover);
                };
                foreach ($found as $row) {
                    $read($row);
                }
                $pass->resolve($this->lookup($pass, $apply));

                $values = [];
                $bindings = [];
                $last = $lastId;
                foreach ($found as $row) {
                    $last = Cast::int($row->id);
                    $id = $read($row);
                    if ($id !== null) {
                        $values[] = '(?::bigint, ?::bigint)';
                        array_push($bindings, $last, $id);
                    }
                }
                if ($apply && $values !== []) {
                    DB::update(
                        "UPDATE entries AS e SET cover_media_id = v.media_id, meta = e.meta - 'image_url' FROM (VALUES ".implode(', ', $values).') AS v(id, media_id) '.
                        'WHERE e.site_id = ? AND e.id = v.id AND e.cover_media_id IS NULL',
                        [...$bindings, $siteId],
                    );
                }

                return [count($found), $last, $pass, count($values)];
            });
            $lines = self::merge($lines, $pass->lines);
            $rows['entries'] = ($rows['entries'] ?? 0) + $written;
        } while ($count === self::BATCH);
    }

    /**
     * One JSON place: each row's document walked, rewritten whole when anything in it was converted.
     *
     * @param  array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}>  $lines
     * @param  array<string, int>  $rows
     */
    private function docs(int $siteId, bool $apply, string $place, string $table, string $column, ?string $owner, ?string $ownerColumn, array &$lines, array &$rows): void
    {
        $extra = match ($table) {
            'entry_translations' => ', entry_id',
            'content_block_translations' => ', content_block_id',
            'site_settings' => ', group_name, key',
            default => '',
        };
        [$filter, $filterBindings] = match ($table) {
            'taxonomies' => [" AND meta->'".LayoutSlots::META_KEY."' IS NOT NULL", []],
            'site_settings' => [' AND ((group_name = ? AND key = ?) OR (group_name = ? AND key = ?))', [LayoutSlots::SETTING_GROUP, LayoutSlots::SETTING_KEY, 'theme', 'heading_decor']],
            default => ['', []],
        };

        $lastId = 0;
        do {
            [$count, $lastId, $pass, $written] = $this->batch($siteId, function () use ($siteId, $apply, $place, $table, $column, $owner, $ownerColumn, $extra, $filter, $filterBindings, $lastId): array {
                $found = self::objects(DB::select(
                    "SELECT id, {$column}::text AS doc{$extra} FROM {$table} WHERE site_id = ? AND id > ?{$filter} ORDER BY id LIMIT ".self::BATCH.($apply ? ' FOR NO KEY UPDATE' : ''),
                    [$siteId, $lastId, ...$filterBindings],
                ));
                $pass = new BackfillPass($this->urls);
                foreach ($found as $row) {
                    $doc = self::decode($row);
                    if ($doc !== null) {
                        $this->visit($place, $doc, $row, $pass);
                    }
                }
                $pass->resolve($this->lookup($pass, $apply));

                $docs = [];
                $usage = [];
                $masks = [];
                $last = $lastId;
                foreach ($found as $row) {
                    $last = Cast::int($row->id);
                    $doc = self::decode($row);
                    if ($doc === null) {
                        continue;
                    }
                    $pass->row();
                    $this->visit($place, $doc, $row, $pass);
                    if ($pass->changed === 0) {
                        continue;
                    }
                    $docs[$last] = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                    if (self::isDecor($row)) {
                        $masks[$last] = $doc;
                    } elseif ($owner !== null && $ownerColumn !== null) {
                        $ownerId = Cast::int($row->{$ownerColumn});
                        foreach ($pass->named as $mediaId) {
                            $usage["{$mediaId}|{$ownerId}"] = [
                                'media_id' => $mediaId, 'mediable_type' => $owner, 'mediable_id' => $ownerId, 'site_id' => $siteId,
                                'collection' => BlockMediaUsage::COLLECTION, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
                            ];
                        }
                    }
                }
                if ($apply && $docs !== []) {
                    self::write($siteId, $table, $docs);
                    if ($usage !== []) {
                        DB::table('mediables')->insertOrIgnore(array_values($usage));
                    }
                    foreach ($masks as $settingId => $doc) {
                        HeadingDecorInput::syncMaskUsage($settingId, json_decode(Cast::string(json_encode($doc)), true));
                    }
                }

                return [count($found), $last, $pass, count($docs)];
            });
            $lines = self::merge($lines, $pass->lines);
            $rows[$place] = ($rows[$place] ?? 0) + $written;
        } while ($count === self::BATCH);
    }

    /**
     * Writes a batch's rewritten documents. A page body (a post's, a pending change's, a shared
     * block's) goes through its model, so its sanitizing cast runs as on every body write: a quiet
     * save with timestamps off, one per row (ResanitizePageBodies' road). The other places take one
     * statement per batch.
     *
     * @param  array<int, string>  $docs  row id => the document as JSON
     */
    private static function write(int $siteId, string $table, array $docs): void
    {
        $model = match ($table) {
            'entry_translations' => EntryTranslation::class,
            'entry_drafts' => EntryDraft::class,
            'content_block_translations' => ContentBlockTranslation::class,
            default => null,
        };
        if ($model !== null) {
            $column = $table === 'entry_drafts' ? 'content' : 'body';
            // only the id and the body are read, so only the body is written (the title and excerpt,
            // whose storefront search words the model events keep, are never touched)
            foreach ($model::query()->whereKey(array_keys($docs))->get(['id', $column]) as $row) {
                $row->setAttribute($column, json_decode($docs[Cast::int($row->getKey())], true, 512, JSON_THROW_ON_ERROR));
                $row->timestamps = false;
                $row->saveQuietly();
            }

            return;
        }

        $values = implode(', ', array_fill(0, count($docs), '(?::bigint, ?::text)'));
        $bindings = [];
        foreach ($docs as $id => $doc) {
            array_push($bindings, $id, $doc);
        }
        $bindings[] = $siteId;
        match ($table) {
            'entry_revisions' => DB::update("UPDATE entry_revisions AS t SET snapshot = v.doc::jsonb FROM (VALUES {$values}) AS v(id, doc) WHERE t.site_id = ? AND t.id = v.id", $bindings),
            'taxonomies' => DB::update("UPDATE taxonomies AS t SET meta = v.doc::jsonb FROM (VALUES {$values}) AS v(id, doc) WHERE t.site_id = ? AND t.id = v.id", $bindings),
            'site_settings' => DB::update("UPDATE site_settings AS t SET value = v.doc::jsonb FROM (VALUES {$values}) AS v(id, doc) WHERE t.site_id = ? AND t.id = v.id", $bindings),
            default => throw new InvalidArgumentException("Not a place: {$table}."),
        };
    }

    /** The batch's one lookup: every key it names, the Trash included, FOR KEY SHARE when writing. */
    private function lookup(BackfillPass $pass, bool $apply): MediaRefs
    {
        return MediaRefs::rows([], [], array_keys($pass->keys), ['deleted_at'], withTrashed: true, lock: $apply);
    }

    /** Walks the places of one stored document that can name a file. */
    private function visit(string $place, stdClass $doc, object $row, BackfillPass $pass): void
    {
        switch ($place) {
            case 'entry_drafts':
                // {v, shared: {cover_media_id, meta, …}, locales: {<locale>: {body, …}}}
                if (($doc->shared ?? null) instanceof stdClass) {
                    $this->cover($place, $doc->shared, $pass);
                }
                foreach (self::props($doc->locales ?? null) as $text) {
                    if ($text instanceof stdClass && ($text->body ?? null) instanceof stdClass) {
                        $this->blocks($place, $text->body, $pass);
                    }
                }
                break;
            case 'entry_revisions':
                // {v, cover_media_id, meta, body, …}
                $this->cover($place, $doc, $pass);
                if (($doc->body ?? null) instanceof stdClass) {
                    $this->blocks($place, $doc->body, $pass);
                }
                break;
            case 'entry_translations':
            case 'content_block_translations':
                $this->blocks($place, $doc, $pass);
                break;
            case 'taxonomies':
                $this->slots($place, $doc->{LayoutSlots::META_KEY} ?? null, $pass);
                break;
            case 'site_settings':
                if (self::isDecor($row)) {
                    $pass->mask("{$place} mask", $doc);
                } else {
                    $this->slots($place, $doc, $pass);
                }
                break;
        }
    }

    /** A cover pair held in $holder (cover_media_id beside meta.image_url). */
    private function cover(string $place, stdClass $holder, BackfillPass $pass): void
    {
        $meta = $holder->meta ?? null;
        $id = $pass->cover("{$place} cover", $holder->cover_media_id ?? null, $meta instanceof stdClass ? ($meta->image_url ?? null) : null);
        if ($id !== null && $meta instanceof stdClass) {
            $holder->cover_media_id = $id;
            unset($meta->image_url);
        }
    }

    /** A slot map: slot → NONE or locale → {"blocks": […]} (LayoutSlots::blockLists). */
    private function slots(string $place, mixed $slots, BackfillPass $pass): void
    {
        foreach (self::props($slots) as $value) {
            foreach (self::props($value) as $text) {
                if ($text instanceof stdClass) {
                    $this->blocks($place, $text, $pass);
                }
            }
        }
    }

    /** A Page-JSON body {"blocks": [{type, props}, …]}: each known block's image props and buttons. */
    private function blocks(string $place, stdClass $body, BackfillPass $pass): void
    {
        $blocks = $body->blocks ?? null;
        if (! is_array($blocks)) {
            return;
        }
        foreach ($blocks as $block) {
            if (! $block instanceof stdClass || ! is_string($block->type ?? null) || ! $this->registry->has($block->type)) {
                continue;
            }
            $stored = $block->props ?? null;
            if (! $stored instanceof stdClass && ! is_array($stored)) {
                continue;
            }
            $schema = $this->registry->schemaFor($block->type);
            $props = Cast::arr(json_decode(Cast::string(json_encode($stored)), true));
            $new = ButtonLink::walk($props, $schema, static fn (array $do): array => $pass->file("{$place} file", $do));
            $new = SchemaImages::mapImages($new, $schema, static fn (mixed $value): mixed => $pass->image("{$place} image", $value));
            if ($new !== $props) {
                $block->props = $new;
            }
        }
    }

    /**
     * Runs one batch in its own tenant transaction, again whole after a lost lock conflict.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function batch(int $siteId, Closure $work): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $this->tenancy->enter($siteId, $work);
            } catch (Throwable $e) {
                if ($try >= self::TRIES || DB::transactionLevel() > 0 || ! SiteMediaRekey::lostLockConflict($e)) {
                    throw $e;
                }
                usleep(100_000 * $try);
            }
        }
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<stdClass>
     */
    private static function objects(array $rows): array
    {
        $objects = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $objects[] = $row;
            }
        }

        return $objects;
    }

    private static function isDecor(object $row): bool
    {
        return ($row->group_name ?? null) === 'theme' && ($row->key ?? null) === 'heading_decor';
    }

    /** A row's document as objects (an empty JSON object stays one), null when it is not an object. */
    private static function decode(object $row): ?stdClass
    {
        $doc = isset($row->doc) ? json_decode(Cast::string($row->doc), false, 512, JSON_THROW_ON_ERROR) : null;

        return $doc instanceof stdClass ? $doc : null;
    }

    /** @return array<array-key, mixed> the values of a JSON object or list ([] for anything else) */
    private static function props(mixed $value): array
    {
        return $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
    }

    /**
     * @param  array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}>  $sum
     * @param  array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}>  $more
     * @return array<string, array{converted: int, already: int, kept: int, unmatched: int, trashed: int, samples: list<string>, trashed_samples: list<string>}>
     */
    public static function merge(array $sum, array $more): array
    {
        foreach ($more as $place => $line) {
            $old = $sum[$place] ?? BackfillPass::emptyLine();
            $sum[$place] = [
                'converted' => $old['converted'] + $line['converted'],
                'already' => $old['already'] + $line['already'],
                'kept' => $old['kept'] + $line['kept'],
                'unmatched' => $old['unmatched'] + $line['unmatched'],
                'trashed' => $old['trashed'] + $line['trashed'],
                'samples' => array_slice(array_values(array_unique([...$old['samples'], ...$line['samples']])), 0, 5),
                'trashed_samples' => array_slice(array_values(array_unique([...$old['trashed_samples'], ...$line['trashed_samples']])), 0, 5),
            ];
        }

        return $sum;
    }
}

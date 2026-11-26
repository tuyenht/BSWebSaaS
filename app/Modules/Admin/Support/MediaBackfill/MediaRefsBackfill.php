<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\MediaBackfill;

use App\Modules\Admin\Support\Settings\HeadingDecorInput;
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
use LogicException;
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
 * The other places media:rekey-site rewrites keys in (COUNTED, and every other setting of the
 * website, general.brand among them) are read too but only counted: no reader resolves a file
 * address from them but the brand's, whose URLs the brand step removes. Every file address found
 * there is a line of the report, so the contract sees it.
 *
 * Every lookup goes through MediaRefs (one query per batch, the Trash included and FOR KEY SHARE
 * when writing). A file in the Trash is converted like any other (so a picture in the Trash stops
 * showing, as block pictures already do) and counted apart, so the owner can be told before the
 * first real run. A value already in the new shape is never touched; one that names no file of
 * the website, or a file of another type, is left as it is and counted as unmatched.
 *
 * Rows are read BATCH at a time per tenant transaction, in the order of an index that leads with
 * site_id (WALKS), so a batch reads about BATCH rows of the website, not all of them. The page is
 * read without a lock and the walk's cursor taken from that read. In a real run the rows of it that
 * hold a reference are then locked and read again by id (FOR NO KEY UPDATE), then the media rows FOR
 * KEY SHARE (the order an entry save takes them), with lock_timeout set, and a batch that lost a
 * lock conflict is run again whole, TRIES times at most (as SiteMediaRekey). (A cursor taken from the
 * locked rows could jump: at READ COMMITTED a row that waited for a save comes back with its new key
 * values, out of order, and the rows between would never be read.) A dry run reads without locks and
 * writes nothing. A row is written only while it still holds what was read under the lock (a save in
 * between, which the lock rules out, leaves it for the next run, counted); a row that left the
 * segment between the two reads is counted the same way. Writes skip the model events (no
 * updated_at, no revision, no content mark: the page shows the same picture): a page body through a
 * quiet save of its model, so its sanitizing cast still runs, the other places one statement per
 * batch. A real run keeps the usage index in the same transaction (a block picture now named by id
 * is recorded as used, BlockMediaUsage's collection; the mask's row through HeadingDecorInput), then
 * rebuilds the cover usage of the website (EntryCoverUsage::reconcileSite). Running it again
 * converts nothing.
 *
 * Stateless (a pass object per batch), so it is Octane-safe.
 *
 * @phpstan-import-type Line from BackfillPass
 */
final readonly class MediaRefsBackfill
{
    /** Rows read per transaction. */
    public const int BATCH = 500;

    /** Tries of one batch that lost a lock conflict, the first included. */
    public const int TRIES = 3;

    /** How long a batch waits for a row lock before it gives up and is tried again (SET LOCAL lock_timeout). */
    public const string LOCK_TIMEOUT = '3s';

    /** The converted JSON places: report name => [table, jsonb column, owner morph alias for the usage index, owner id column]. */
    public const array DOCS = [
        'entry_drafts' => ['entry_drafts', 'content', null, null],
        'entry_revisions' => ['entry_revisions', 'snapshot', null, null],
        'entry_translations' => ['entry_translations', 'body', 'entry', 'entry_id'],
        'content_block_translations' => ['content_block_translations', 'body', 'content_block', 'content_block_id'],
        'taxonomies' => ['taxonomies', 'meta', 'taxonomy', 'id'],
        'site_settings' => ['site_settings', 'value', 'site_setting', 'id'],
    ];

    /**
     * The places only counted: no reader resolves a file address from them (products.meta holds
     * notes of its own, the pictures are product_images rows; a menu's and a menu item's meta hold
     * flags, a glyph name and a block ulid; a content section's and a profile's meta are read by no
     * page; a form's settings hold flags and an entry ulid). Report name => [table, jsonb column].
     */
    public const array COUNTED = [
        'products' => ['products', 'meta'],
        'menus' => ['menus', 'meta'],
        'menu_items' => ['menu_items', 'meta'],
        'content_sections' => ['content_sections', 'meta'],
        'site_profiles' => ['site_profiles', 'meta'],
        'forms' => ['forms', 'settings'],
    ];

    /**
     * How each table is read: segments that together hold every row of a website, each walked in the
     * order of an index that leads with site_id, keyset on its columns (an id tie-break where the
     * index ends without one). [segment condition, keys: list of [column, ORDER BY term]]; a term
     * ending in DESC walks that column down, as its index stores it.
     *
     * A key a save can change (updated_at, a title, a page's status) lets a row saved during the run
     * move behind the walk; such a row is the new shape already when the save wrote it, and anything
     * left is what the second dry run is for. updated_at only grows, so it moves a row ahead (read again).
     */
    public const array WALKS = [
        'entries' => [
            // live posts: entries_post_updated_idx (site_id, updated_at NULLS FIRST, id)
            ["type = 'post' AND deleted_at IS NULL AND updated_at IS NOT NULL", [['updated_at', 'updated_at NULLS FIRST'], ['id', 'id']]],
            ["type = 'post' AND deleted_at IS NULL AND updated_at IS NULL", [['id', 'id']]],
            // the Trash: entries_trash_idx (site_id, type, updated_at NULLS FIRST, id)
            ['deleted_at IS NOT NULL AND updated_at IS NOT NULL', [['type', 'type'], ['updated_at', 'updated_at NULLS FIRST'], ['id', 'id']]],
            ['deleted_at IS NOT NULL AND updated_at IS NULL', [['type', 'type'], ['id', 'id']]],
            // live pages: entries_site_id_type_status_index (site_id, type, status); no index holds
            // their id, so a batch sorts the rest of one status (a website has few pages)
            ["type = 'page' AND deleted_at IS NULL", [['status', 'status'], ['id', 'id']]],
        ],
        // entry_drafts_queue (site_id, workflow_state) holds no id: a batch sorts the website's
        // drafts (a row lives only while a change is open, so they are few)
        'entry_drafts' => [['', [['id', 'id']]]],
        // entry_revisions_history (site_id, entry_id, locale, created_at DESC, id DESC), in its own
        // order: walked by (entry_id, id), the planner took entry_revisions_entry_id_index and read
        // every other website's revisions before this one's first
        'entry_revisions' => [['', [['entry_id', 'entry_id'], ['locale', 'locale'], ['created_at', 'created_at DESC'], ['id', 'id DESC']]]],
        // entry_translations_title_icu_idx (site_id, locale, title COLLATE "vi-VN-x-icu", entry_id)
        'entry_translations' => [['', [['locale', 'locale'], ['title COLLATE "vi-VN-x-icu"', 'title COLLATE "vi-VN-x-icu"'], ['entry_id', 'entry_id']]]],
        // content_block_translations_block_locale_unique (site_id, content_block_id, locale)
        'content_block_translations' => [['', [['content_block_id', 'content_block_id'], ['locale', 'locale']]]],
        // taxonomies_site_id_id_unique
        'taxonomies' => [['', [['id', 'id']]]],
        // site_settings_site_id_group_name_key_unique
        'site_settings' => [['', [['group_name', 'group_name'], ['key', 'key']]]],
        // products_live_updated_idx (site_id, updated_at NULLS FIRST, id), products_trash_idx (site_id, deleted_at NULLS FIRST, id)
        'products' => [
            ['deleted_at IS NULL AND updated_at IS NOT NULL', [['updated_at', 'updated_at NULLS FIRST'], ['id', 'id']]],
            ['deleted_at IS NULL AND updated_at IS NULL', [['id', 'id']]],
            ['deleted_at IS NOT NULL', [['deleted_at', 'deleted_at NULLS FIRST'], ['id', 'id']]],
        ],
        // the (site_id, id) unique index of each; site_profiles_site_id_unique (one row)
        'menus' => [['', [['id', 'id']]]],
        'menu_items' => [['', [['id', 'id']]]],
        'content_sections' => [['', [['id', 'id']]]],
        'forms' => [['', [['id', 'id']]]],
        'site_profiles' => [['', [['id', 'id']]]],
    ];

    /** The places a visitor sees: a picture of these in the Trash stops showing once converted. */
    public const array SHOWN = ['entries', 'entry_translations', 'content_block_translations', 'taxonomies', 'site_settings'];

    public function __construct(private Tenancy $tenancy, private ComponentRegistry $registry, private MediaUrlResolver $urls) {}

    /**
     * Converts (or with $apply false only counts) the website's places.
     *
     * @return array{lines: array<string, Line>, rows: array<string, int>, covers: array{inserted: int, deleted: int}|null}
     */
    public function site(int $siteId, bool $apply): array
    {
        $lines = [];
        $rows = [];
        $this->entries($siteId, $apply, $lines, $rows);
        foreach (self::DOCS as $place => [$table, $column, $owner, $ownerColumn]) {
            $this->docs($siteId, $apply, $place, $table, $column, $owner, $ownerColumn, $lines, $rows);
        }
        foreach (self::COUNTED as $place => [$table, $column]) {
            $this->docs($siteId, false, $place, $table, $column, null, null, $lines, $rows);
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
     * @param  array<string, Line>  $lines
     * @param  array<string, int>  $rows
     */
    private function entries(int $siteId, bool $apply, array &$lines, array &$rows): void
    {
        $select = "id, cover_media_id, meta->'image_url' AS cover";
        $filter = "cover_media_id IS NOT NULL OR meta->'image_url' IS NOT NULL";
        $this->walk($siteId, 'entries', $select, $filter, [], $apply, function (array $found) use ($siteId, $apply): array {
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
            foreach ($found as $row) {
                $id = $read($row);
                if ($id !== null) {
                    $values[] = '(?::bigint, ?::bigint, ?::jsonb)';
                    array_push($bindings, Cast::int($row->id), $id, Cast::string($row->cover));
                }
            }
            $written = 0;
            if ($apply && $values !== []) {
                // only while the row still holds what was read (the lock keeps it so)
                $written = DB::update(
                    "UPDATE entries AS e SET cover_media_id = v.media_id, meta = e.meta - 'image_url' FROM (VALUES ".implode(', ', $values).') AS v(id, media_id, cover) '.
                    "WHERE e.site_id = ? AND e.id = v.id AND e.cover_media_id IS NULL AND e.meta->'image_url' = v.cover",
                    [...$bindings, $siteId],
                );
            }

            return [$pass, $apply ? $written : count($values), $apply ? count($values) - $written : 0];
        }, $lines, $rows, 'entries');
    }

    /**
     * One JSON place: each row's document walked, rewritten whole when anything in it was converted.
     * A COUNTED place ($owner null and not in DOCS) and the settings rows that hold no blocks or mask
     * are only read for the file addresses they name.
     *
     * @param  array<string, Line>  $lines
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
        $filter = match ($table) {
            'taxonomies' => "meta->'".LayoutSlots::META_KEY."' IS NOT NULL",
            // a file address has a slash: a document without one names none
            'products', 'menus', 'menu_items', 'content_sections', 'site_profiles', 'forms' => "{$column}::text LIKE '%/%'",
            default => '',
        };
        $counted = isset(self::COUNTED[$place]);

        $this->walk($siteId, $table, "id, {$column}::text AS doc{$extra}", $filter, [], $apply && ! $counted, function (array $found) use ($siteId, $apply, $place, $table, $owner, $ownerColumn, $counted): array {
            $pass = new BackfillPass($this->urls);
            foreach ($found as $row) {
                $this->visit($place, $counted, $row, $pass);
            }
            $pass->resolve($this->lookup($pass, $apply && ! $counted));

            $docs = [];
            $usage = [];
            $masks = [];
            foreach ($found as $row) {
                $pass->row();
                $doc = $this->visit($place, $counted, $row, $pass);
                if ($pass->changed === 0 || ! $doc instanceof stdClass) {
                    continue;
                }
                $id = Cast::int($row->id);
                $docs[$id] = [json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), Cast::string($row->doc)];
                if (self::isDecor($row)) {
                    $masks[$id] = $doc;
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
            if (! $apply || $docs === []) {
                return [$pass, count($docs), 0];
            }
            $written = self::write($siteId, $table, $docs);
            if ($usage !== []) {
                DB::table('mediables')->insertOrIgnore(array_values($usage));
            }
            foreach ($masks as $settingId => $doc) {
                if (in_array($settingId, $written, true)) {
                    HeadingDecorInput::syncMaskUsage($settingId, json_decode(Cast::string(json_encode($doc)), true));
                }
            }

            return [$pass, count($written), count($docs) - count($written)];
        }, $lines, $rows, $place);
    }

    /**
     * Walks one table's rows of the website, segment by segment (WALKS), BATCH rows per tenant
     * transaction: $handle gets a batch's rows that match $filter and returns its pass, the rows it
     * wrote (or would write) and the rows it left because they changed since they were read.
     *
     * $filter is computed per row (`hit`), not put in the WHERE: there it would make a batch scan
     * the index until BATCH rows match, the whole website when few do. So every batch reads at most
     * BATCH index entries. With $lock, only the rows that match are locked and read again (locked());
     * the cursor stays the one of the unlocked page, so no row is passed over.
     *
     * @param  list<mixed>  $filterBindings
     * @param  Closure(list<stdClass>): array{0: BackfillPass, 1: int, 2: int}  $handle
     * @param  array<string, Line>  $lines
     * @param  array<string, int>  $rows
     */
    private function walk(int $siteId, string $table, string $select, string $filter, array $filterBindings, bool $lock, Closure $handle, array &$lines, array &$rows, string $place): void
    {
        $walks = self::WALKS[$table] ?? throw new InvalidArgumentException("Not a place: {$table}.");
        foreach ($walks as [$segment, $keys]) {
            $cursor = null;
            do {
                [$count, $cursor, $pass, $written, $left] = $this->batch($siteId, function () use ($siteId, $table, $select, $segment, $keys, $filter, $filterBindings, $lock, $handle, $cursor): array {
                    [$sql, $bindings] = self::page($siteId, $table, $select, $segment, $keys, $filter, $filterBindings, $cursor);
                    $found = self::objects(DB::select($sql, $bindings));
                    $last = $found === [] ? $cursor : array_map(static fn (int $i): mixed => $found[count($found) - 1]->{"k{$i}"}, array_keys($keys));
                    $hits = self::hits($found);
                    $gone = 0;
                    if ($lock && $hits !== []) {
                        [$sql, $bindings] = self::locked($siteId, $table, $select, $segment, $filter, $filterBindings, array_map(static fn (stdClass $row): int => Cast::int($row->id), $hits));
                        $again = self::objects(DB::select($sql, $bindings));
                        // a row deleted or moved out of the segment since the page was read
                        $gone = count($hits) - count($again);
                        $hits = self::hits($again);
                    }
                    [$pass, $written, $left] = $handle($hits);

                    return [count($found), $last, $pass, $written, $left + $gone];
                });
                $lines = self::merge($lines, $pass->lines);
                if (! isset(self::COUNTED[$place])) {
                    $rows[$place] = ($rows[$place] ?? 0) + $written;
                }
                if ($left > 0) {
                    $rows["{$place} changed since read, left for the next run"] = ($rows["{$place} changed since read, left for the next run"] ?? 0) + $left;
                }
            } while ($count === self::BATCH);
        }
    }

    /**
     * The query of one batch: up to BATCH rows of the website after $cursor, in the keys' order,
     * read without a lock. The first key's bound is also given alone (>=), so an index whose next
     * column is not the second key still starts at the cursor; that bound holds only for a first
     * key walked up, so a first key walked down is refused.
     *
     * @param  list<array{0: string, 1: string}>  $keys
     * @param  list<mixed>  $filterBindings
     * @param  list<mixed>|null  $cursor
     * @return array{0: string, 1: list<mixed>}
     */
    public static function page(int $siteId, string $table, string $select, string $segment, array $keys, string $filter, array $filterBindings, ?array $cursor): array
    {
        if ($keys === [] || self::descending($keys[0][1])) {
            throw new LogicException("The walk of {$table} must start with a key walked up (ascending).");
        }
        $columns = implode(', ', array_map(static fn (array $key, int $i): string => "{$key[0]} AS k{$i}", $keys, array_keys($keys)));
        $hit = $filter === '' ? '1' : "CASE WHEN {$filter} THEN 1 ELSE 0 END";
        $sql = "SELECT {$select}, {$hit} AS hit, {$columns} FROM {$table} WHERE site_id = ?".($segment !== '' ? " AND {$segment}" : '');
        $bindings = [...$filterBindings, $siteId];
        if ($cursor !== null) {
            if (count($keys) > 1) {
                $sql .= " AND {$keys[0][0]} >= ?";
                $bindings[] = $cursor[0];
            }
            $down = array_map(static fn (array $key): bool => self::descending($key[1]), $keys);
            if (! in_array(true, $down, true)) {
                $sql .= ' AND ('.implode(', ', array_column($keys, 0)).') > ('.implode(', ', array_fill(0, count($keys), '?')).')';
                array_push($bindings, ...$cursor);
            } else {
                // mixed directions: (k0 > c0) OR (k0 = c0 AND k1 > c1) OR …, < for a DESC key
                $or = [];
                foreach ($keys as $i => $key) {
                    $and = [];
                    foreach (array_slice($keys, 0, $i) as $j => $before) {
                        $and[] = "{$before[0]} = ?";
                        $bindings[] = $cursor[$j];
                    }
                    $and[] = $key[0].($down[$i] ? ' < ?' : ' > ?');
                    $bindings[] = $cursor[$i];
                    $or[] = '('.implode(' AND ', $and).')';
                }
                $sql .= ' AND ('.implode(' OR ', $or).')';
            }
        }
        $sql .= ' ORDER BY '.implode(', ', array_column($keys, 1)).' LIMIT '.self::BATCH;

        return [$sql, $bindings];
    }

    /**
     * The rows of a page that matched, locked and read again by id (in id order) while they are still
     * in the segment: what a real run converts.
     *
     * @param  list<mixed>  $filterBindings
     * @param  list<int>  $ids
     * @return array{0: string, 1: list<mixed>}
     */
    public static function locked(int $siteId, string $table, string $select, string $segment, string $filter, array $filterBindings, array $ids): array
    {
        $hit = $filter === '' ? '1' : "CASE WHEN {$filter} THEN 1 ELSE 0 END";
        $sql = "SELECT {$select}, {$hit} AS hit FROM {$table} WHERE site_id = ? AND id = ANY(?::bigint[])".($segment !== '' ? " AND {$segment}" : '').' ORDER BY id FOR NO KEY UPDATE';

        return [$sql, [...$filterBindings, $siteId, '{'.implode(',', $ids).'}']];
    }

    /** An ORDER BY term that walks its column down. */
    private static function descending(string $term): bool
    {
        return preg_match('/\sDESC(\s+NULLS\s+(FIRST|LAST))?$/i', $term) === 1;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<stdClass> the rows that match the walk's filter
     */
    private static function hits(array $rows): array
    {
        return array_values(array_filter($rows, static fn (stdClass $row): bool => Cast::int($row->hit) === 1));
    }

    /**
     * Writes a batch's rewritten documents, each only while its row still holds the document that
     * was read (under the row lock it always does). A page body goes through its model
     * (PageBodyWriter); the other places take one statement per batch.
     *
     * @param  array<int, array{0: string, 1: string}>  $docs  row id => [the new document, the one read] as JSON
     * @return list<int> the ids written
     */
    private static function write(int $siteId, string $table, array $docs): array
    {
        if (in_array($table, PageBodyWriter::TABLES, true)) {
            return PageBodyWriter::write($table, $docs);
        }

        $values = implode(', ', array_fill(0, count($docs), '(?::bigint, ?::text, ?::text)'));
        $bindings = [];
        foreach ($docs as $id => [$new, $old]) {
            array_push($bindings, $id, $new, md5($old));
        }
        $bindings[] = $siteId;
        $sql = match ($table) {
            'entry_revisions' => "UPDATE entry_revisions AS t SET snapshot = v.doc::jsonb FROM (VALUES {$values}) AS v(id, doc, old) WHERE t.site_id = ? AND t.id = v.id AND md5(t.snapshot::text) = v.old RETURNING t.id",
            'taxonomies' => "UPDATE taxonomies AS t SET meta = v.doc::jsonb FROM (VALUES {$values}) AS v(id, doc, old) WHERE t.site_id = ? AND t.id = v.id AND md5(t.meta::text) = v.old RETURNING t.id",
            'site_settings' => "UPDATE site_settings AS t SET value = v.doc::jsonb FROM (VALUES {$values}) AS v(id, doc, old) WHERE t.site_id = ? AND t.id = v.id AND md5(t.value::text) = v.old RETURNING t.id",
            default => throw new InvalidArgumentException("Not a place: {$table}."),
        };

        return array_map(static fn (stdClass $row): int => Cast::int($row->id), self::objects(DB::selectFromWriteConnection($sql, $bindings)));
    }

    /** The batch's one lookup: every key it names, the Trash included, FOR KEY SHARE when writing. */
    private function lookup(BackfillPass $pass, bool $lock): MediaRefs
    {
        return MediaRefs::rows([], [], array_keys($pass->keys), ['deleted_at'], withTrashed: true, lock: $lock);
    }

    /**
     * Walks the places of one stored row that can name a file: the document (decoded afresh, so the
     * second walk starts from what was read), or null when the row holds none to rewrite.
     */
    private function visit(string $place, bool $counted, stdClass $row, BackfillPass $pass): ?stdClass
    {
        $value = isset($row->doc) ? json_decode(Cast::string($row->doc), false, 512, JSON_THROW_ON_ERROR) : null;
        if ($counted) {
            $this->addresses("{$place} address", $value, $pass);

            return null;
        }
        if ($place === 'site_settings' && ! self::isDecor($row) && ! self::isSlots($row)) {
            $this->addresses('site_settings '.Cast::string($row->group_name ?? '').'.'.Cast::string($row->key ?? ''), $value, $pass);

            return null;
        }
        if (! $value instanceof stdClass) {
            return null;
        }
        switch ($place) {
            case 'entry_drafts':
                // {v, shared: {cover_media_id, meta, …}, locales: {<locale>: {body, …}}}
                if (($value->shared ?? null) instanceof stdClass) {
                    $this->cover($place, $value->shared, $pass);
                }
                foreach (self::props($value->locales ?? null) as $text) {
                    if ($text instanceof stdClass && ($text->body ?? null) instanceof stdClass) {
                        $this->blocks($place, $text->body, $pass);
                    }
                }
                break;
            case 'entry_revisions':
                // {v, cover_media_id, meta, body, …}
                $this->cover($place, $value, $pass);
                if (($value->body ?? null) instanceof stdClass) {
                    $this->blocks($place, $value->body, $pass);
                }
                break;
            case 'entry_translations':
            case 'content_block_translations':
                $this->blocks($place, $value, $pass);
                break;
            case 'taxonomies':
                $this->slots($place, $value->{LayoutSlots::META_KEY} ?? null, $pass);
                break;
            case 'site_settings':
                if (self::isDecor($row)) {
                    $pass->mask("{$place} mask", $value);
                } else {
                    $this->slots($place, $value, $pass);
                }
                break;
        }

        return $value;
    }

    /** Every string of a counted document, through BackfillPass::address(). */
    private function addresses(string $place, mixed $value, BackfillPass $pass): void
    {
        if (is_string($value)) {
            $pass->address($place, $value);
        } elseif ($value instanceof stdClass || is_array($value)) {
            foreach (self::props($value) as $item) {
                $this->addresses($place, $item, $pass);
            }
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

    /**
     * A Page-JSON body {"blocks": [{type, props}, …]}: each known block's image props and buttons.
     * Only the converted values change: the rest of the props is put back as it was read (an empty
     * object stays one, 1.0 stays 1.0). A block of a type the registry does not know is counted.
     */
    private function blocks(string $place, stdClass $body, BackfillPass $pass): void
    {
        $blocks = $body->blocks ?? null;
        if (! is_array($blocks)) {
            return;
        }
        foreach ($blocks as $block) {
            if (! $block instanceof stdClass || ! is_string($block->type ?? null)) {
                continue;
            }
            if (! $this->registry->has($block->type)) {
                $pass->skipped("{$place} block", $block->type);

                continue;
            }
            $stored = $block->props ?? null;
            if (! $stored instanceof stdClass && ! is_array($stored)) {
                continue;
            }
            $schema = $this->registry->schemaFor($block->type);
            $props = Cast::arr(json_decode(Cast::string(json_encode($stored, JSON_PRESERVE_ZERO_FRACTION)), true));
            $new = ButtonLink::walk($props, $schema, static fn (array $do): array => $pass->file("{$place} file", $do));
            $new = SchemaImages::mapImages($new, $schema, static fn (mixed $value): mixed => $pass->image("{$place} image", $value));
            if ($new !== $props) {
                $block->props = self::patch($stored, $new);
            }
        }
    }

    /**
     * $new (an array decoded from $old and partly converted) laid over $old as it was read: objects
     * stay objects and lists stay lists, even empty ones, so only the values that changed differ.
     */
    private static function patch(mixed $old, mixed $new): mixed
    {
        if (! is_array($new) || (! $old instanceof stdClass && ! is_array($old))) {
            return $new;
        }
        $before = self::props($old);
        if ($old instanceof stdClass) {
            $out = new stdClass;
            foreach ($new as $key => $value) {
                $out->{$key} = array_key_exists($key, $before) ? self::patch($before[$key], $value) : $value;
            }

            return $out;
        }
        $out = [];
        foreach ($new as $key => $value) {
            $out[$key] = array_key_exists($key, $before) ? self::patch($before[$key], $value) : $value;
        }

        return $out;
    }

    /**
     * Runs one batch in its own tenant transaction (with lock_timeout set, so a lock wait ends in a
     * conflict), again whole after a lost lock conflict.
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
                return $this->tenancy->enter($siteId, static function () use ($work): mixed {
                    DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");

                    return $work();
                });
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

    private static function isSlots(object $row): bool
    {
        return ($row->group_name ?? null) === LayoutSlots::SETTING_GROUP && ($row->key ?? null) === LayoutSlots::SETTING_KEY;
    }

    /** @return array<array-key, mixed> the values of a JSON object or list ([] for anything else) */
    private static function props(mixed $value): array
    {
        return $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
    }

    /**
     * @param  array<string, Line>  $sum
     * @param  array<string, Line>  $more
     * @return array<string, Line>
     */
    public static function merge(array $sum, array $more): array
    {
        foreach ($more as $place => $line) {
            $old = $sum[$place] ?? BackfillPass::emptyLine();
            foreach (BackfillPass::COUNTERS as $counter) {
                $old[$counter] += $line[$counter];
            }
            foreach (['samples', 'trashed_samples', 'notes'] as $list) {
                $old[$list] = array_slice(array_values(array_unique([...$old[$list], ...$line[$list]])), 0, 5);
            }
            $sum[$place] = $old;
        }

        return $sum;
    }
}

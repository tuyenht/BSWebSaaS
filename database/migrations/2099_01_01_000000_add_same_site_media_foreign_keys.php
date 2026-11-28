<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Every column that names a media row now names one of the same website, enforced by the database:
 * a composite foreign key (site_id, <column>) → media (site_id, id). Until now the keys were plain
 * (<column>) → media (id), or missing (entries.cover_media_id, entry_translations.og_image_id), so a
 * write past the app could point one site's post, category, brand image, attachment or gallery at
 * another site's file. Foreign-key checks bypass row security, so RLS never stopped that; only a key
 * that carries site_id does.
 *
 *   - media (site_id, id): a plain unique index, built CONCURRENTLY, which a foreign key may
 *     reference. It is not promoted to a constraint: that step takes ACCESS EXCLUSIVE on media,
 *     which every storefront page reads.
 *   - entries (cover_media_id) and entry_translations (og_image_id), partial on IS NOT NULL: the
 *     lookup a media purge makes to clear the reference (the other tables already have one led by
 *     the column). Built CONCURRENTLY.
 *   - Nine foreign keys, <table>_<column>_same_site_foreign. A nullable column is cleared when its
 *     file is purged, ON DELETE SET NULL (<column>) so site_id (NOT NULL) stays; a gallery row
 *     (product_images, NOT NULL) goes with its file, ON DELETE CASCADE, as before. A NULL column
 *     skips the check (MATCH SIMPLE).
 *
 * Order: each foreign key is added NOT VALID first, in its own short transaction (it locks the table
 * and media against writes for a moment, scans nothing, and checks every write from then on). Then
 * references written before it that name no media row of their own site are found:
 *   - a gallery row is never deleted here: any one found stops the migration with the count and the
 *     ids before any row is changed (removing those rows is the owner's call);
 *   - in a nullable column the value is cleared (it named no file of that site, so it showed
 *     nothing; this cannot be undone).
 * Every row found is written to storage/logs/media-same-site-keys-<UTC time>.csv (action, table,
 * id, site_id, column, old media id), all of them, before its column is cleared; STDERR names the
 * file and the count per column. A clean run writes no file.
 * Last, each key is validated outside any transaction: the scan holds no lock that blocks reads or
 * writes. The cleanup must see every website's rows, so up() first refuses a role that row security
 * filters. The old plain keys stay until 2099_01_01_000100 drops them.
 *
 * Every step waits at most $lockWait (5s) for a table lock, CREATE INDEX CONCURRENTLY and VALIDATE
 * included (set on this session for that one statement); one that runs out stops the run with a
 * message saying to run it again. Every step is guarded (an index an interrupted build left INVALID
 * is dropped and built again; a constraint is added only when missing), so a run that stops half
 * way can be repeated. down() drops the nine keys (each drop locks media against reads for a
 * moment: roll back at low traffic) and the three indexes; cleared values are not put back.
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY and VALIDATE run outside a transaction; each other step is its own. */
    public $withinTransaction = false;

    /** @var list<array{string, string}> [table, column] of every nullable reference to media */
    private const array NULLABLE = [
        ['entries', 'cover_media_id'],
        ['entry_translations', 'og_image_id'],
        ['taxonomies', 'cover_media_id'],
        ['site_profiles', 'logo_media_id'],
        ['site_profiles', 'logo_dark_media_id'],
        ['site_profiles', 'favicon_media_id'],
        ['site_profiles', 'og_image_media_id'],
        ['mediables', 'media_id'],
    ];

    /** @var array<string, array{string, string, string}> index name => [kind, table, columns and predicate] */
    private const array INDEXES = [
        'media_site_id_id_unique' => ['UNIQUE INDEX', 'media', '(site_id, id)'],
        'entries_cover_media_id_index' => ['INDEX', 'entries', '(cover_media_id) WHERE cover_media_id IS NOT NULL'],
        'entry_translations_og_image_id_index' => ['INDEX', 'entry_translations', '(og_image_id) WHERE og_image_id IS NOT NULL'],
    ];

    /** How many gallery row ids the stop message names (the file it points to lists them all). */
    private const int SHOWN = 50;

    /**
     * How long a step waits for a table lock before it stops (the release stops, re-runnable). A
     * test may shorten it.
     */
    public string $lockWait = '5s';

    /** The file listing every reference this run found (cleared, or stopping the run); null while none was. */
    public ?string $foundLog = null;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->refuseUnlessEveryRowIsVisible();
        $this->foundLog = null;

        foreach (self::INDEXES as $name => [$kind, $table, $definition]) {
            $this->waitingBriefly("building the index {$name}", fn () => $this->index($name, $kind, $table, $definition));
        }

        foreach (self::NULLABLE as [$table, $column]) {
            $this->briefly("adding the key on {$table}.{$column}", fn () => $this->addConstraint($table, $column, "ON DELETE SET NULL ({$column})"));
        }
        $this->briefly('adding the key on product_images.media_id', fn () => $this->addConstraint('product_images', 'media_id', 'ON DELETE CASCADE'));

        $this->stopOnForeignGalleryRows();
        foreach (self::NULLABLE as [$table, $column]) {
            $this->clear($table, $column);
        }

        foreach ([...self::NULLABLE, ['product_images', 'media_id']] as [$table, $column]) {
            $name = self::name($table, $column);
            $this->waitingBriefly("validating {$name}", fn () => DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}"));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Rolled back alone after 2099_01_01_000100, this would leave the columns with no key at all:
        // that migration's down() puts the plain keys back first.
        $plain = DB::scalar("SELECT count(*) FROM pg_constraint WHERE contype = 'f' AND confrelid = 'media'::regclass AND conname LIKE '%\_media\_id\_foreign' AND conname NOT LIKE '%\_same\_site\_foreign'");
        if (! is_numeric($plain) || (int) $plain < 7) {
            throw new RuntimeException('Same-site media keys: roll back 2099_01_01_000100 first; it puts the plain media keys back, without which this rollback would leave the media columns with no foreign key.');
        }

        // Dropping a foreign key drops its triggers on media too, which locks media against every
        // read for that step: each drop is its own short step.
        foreach ([...self::NULLABLE, ['product_images', 'media_id']] as [$table, $column]) {
            $this->briefly("dropping the key on {$table}.{$column}", fn () => DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS ".self::name($table, $column)));
        }

        foreach (array_keys(self::INDEXES) as $name) {
            $this->waitingBriefly("dropping the index {$name}", fn () => DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name));
        }
    }

    public static function name(string $table, string $column): string
    {
        return "{$table}_{$column}_same_site_foreign";
    }

    /** The cleanup below must see every website's rows: under row security it would find none and VALIDATE would fail. */
    public function refuseUnlessEveryRowIsVisible(): void
    {
        $sees = DB::selectOne('SELECT 1 FROM pg_catalog.pg_roles WHERE rolname = current_user AND (rolsuper OR rolbypassrls)');

        if ($sees === null) {
            $role = DB::scalar('SELECT current_user');

            throw new RuntimeException(sprintf(
                'Same-site media keys: the role "%s" cannot read every website\'s rows (needs superuser or BYPASSRLS); run the migration as the privileged role.',
                is_string($role) ? $role : '?',
            ));
        }
    }

    /**
     * A gallery row naming no media row of its own site cannot be cleared (media_id is NOT NULL):
     * every one is listed in the file, then the run stops before any row is changed.
     */
    private function stopOnForeignGalleryRows(): void
    {
        $ids = [];
        foreach (self::foreignRows('product_images', 'media_id', false) as $row) {
            $this->found('stopped', 'product_images', 'media_id', $row);
            $ids[] = $row['id'];
        }

        if ($ids !== []) {
            throw new RuntimeException(sprintf(
                'Same-site media keys: %d product_images row(s) name a file that is not of their own website (ids: %s%s; all of them, with their website and file, in %s). '
                .'No row was changed, but the new keys are already in place (not yet validated) and refuse every new write that names another website\'s file. '
                .'Decide what to do with these gallery rows, then run the migration again.',
                count($ids),
                implode(', ', array_slice($ids, 0, self::SHOWN)),
                count($ids) > self::SHOWN ? ', …' : '',
                $this->foundLog,
            ));
        }
    }

    /**
     * Clear the values of one column that name no media row of their own site, in one short
     * transaction: the rows are locked and each is written to the file (table, id, site, column, old
     * value) before the update, which must then clear exactly those rows. A rollback is marked in
     * the file, so a line there is cleared unless a "rolled back" line for its column follows it.
     */
    private function clear(string $table, string $column): void
    {
        $count = 0;

        try {
            $this->briefly("clearing {$table}.{$column}", function () use ($table, $column, &$count): void {
                foreach (self::foreignRows($table, $column, true) as $row) {
                    $this->found('cleared', $table, $column, $row);
                    $count++;
                }
                if ($count === 0) {
                    return;
                }

                // Only a media-id column from the list above is written (never a body or a title).
                $cleared = DB::table($table)
                    ->whereNotNull($column)
                    ->whereNotExists(fn (Builder $media) => $media->selectRaw('1')->from('media')
                        ->whereColumn('media.site_id', "{$table}.site_id")->whereColumn('media.id', "{$table}.{$column}"))
                    ->update([$column => null]);

                if ($cleared !== $count) {
                    throw new RuntimeException(sprintf('Same-site media keys: %s.%s listed %d row(s) but would clear %d; nothing was cleared, run the migration again.', $table, $column, $count, $cleared));
                }
            });
        } catch (Throwable $e) {
            if ($count > 0) {
                $this->note("rolled back: {$table}.{$column}, the {$count} row(s) listed above for it were not cleared");
            }

            throw $e;
        }

        if ($count > 0) {
            fwrite(STDERR, sprintf("Same-site media keys: cleared %s.%s on %d row(s) that named no file of their own website; every one is listed in %s\n", $table, $column, $count, $this->foundLog));
        }
    }

    /**
     * The rows of one column that name no media row of their own site, by id; with $lock each is
     * locked FOR UPDATE until the caller's transaction ends.
     *
     * @return Generator<int, array{id: int, site_id: int, media_id: int}>
     */
    private static function foreignRows(string $table, string $column, bool $lock): Generator
    {
        $rows = DB::cursor(<<<SQL
            SELECT t.id, t.site_id, t.{$column} AS media_id
            FROM {$table} AS t
            WHERE t.{$column} IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM media m WHERE m.site_id = t.site_id AND m.id = t.{$column})
            ORDER BY t.id
            SQL.($lock ? ' FOR UPDATE OF t' : ''));

        foreach ($rows as $row) {
            $fields = get_object_vars($row);
            yield ['id' => self::int($fields['id'] ?? null), 'site_id' => self::int($fields['site_id'] ?? null), 'media_id' => self::int($fields['media_id'] ?? null)];
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * One CSV line of the found-references file, which is created (with its header) on the first.
     *
     * @param  array{id: int, site_id: int, media_id: int}  $row
     */
    private function found(string $action, string $table, string $column, array $row): void
    {
        $this->line([$action, $table, $row['id'], $row['site_id'], $column, $row['media_id']]);
    }

    private function note(string $text): void
    {
        $this->line(['# '.$text]);
    }

    /** @param  list<mixed>  $fields */
    private function line(array $fields): void
    {
        $path = $this->foundLog;
        if ($path === null) {
            $path = $this->foundLog = storage_path('logs/media-same-site-keys-'.now()->utc()->format('Ymd-His-u').'.csv');
            $this->write($path, ['action', 'table', 'id', 'site_id', 'column', 'old_media_id']);
        }
        $this->write($path, $fields);
    }

    /** @param  list<mixed>  $fields */
    private function write(string $path, array $fields): void
    {
        $handle = fopen($path, 'ab');
        if ($handle === false || fputcsv($handle, array_map(static fn (mixed $f): string => is_scalar($f) ? (string) $f : '', $fields), escape: '') === false) {
            throw new RuntimeException("Same-site media keys: cannot write the list of found references to {$path}; nothing was changed by this step.");
        }
        fclose($handle);
    }

    /** Builds an index, first dropping one an interrupted CONCURRENTLY build left INVALID. */
    private function index(string $name, string $kind, string $table, string $definition): void
    {
        $invalid = DB::selectOne('SELECT 1 FROM pg_index WHERE indexrelid = to_regclass(?) AND NOT indisvalid', [$name]);
        if ($invalid !== null) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
        DB::statement("CREATE {$kind} CONCURRENTLY IF NOT EXISTS {$name} ON {$table} {$definition}");
    }

    /** Add a same-site key NOT VALID unless a repeated run already added it; up() validates it later. */
    private function addConstraint(string $table, string $column, string $onDelete): void
    {
        $name = self::name($table, $column);
        $exists = DB::selectOne('SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?', [$table, $name]);

        if ($exists === null) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY (site_id, {$column}) REFERENCES media (site_id, id) {$onDelete} NOT VALID");
        }
    }

    /**
     * One step in its own transaction with a short lock wait: queued behind a long transaction it
     * gives up (the release stops, re-runnable) instead of holding uploads and every write to the
     * table behind it.
     */
    private function briefly(string $step, Closure $work): void
    {
        $this->explainingLockWait($step, fn () => DB::transaction(function () use ($work): void {
            DB::select('SELECT set_config(?, ?, true)', ['lock_timeout', $this->lockWait]);
            $work();
        }));
    }

    /**
     * A step that cannot run in a transaction (CREATE/DROP INDEX CONCURRENTLY, VALIDATE), with the
     * same short lock wait set on this session for it and reset after it.
     */
    private function waitingBriefly(string $step, Closure $work): void
    {
        DB::select('SELECT set_config(?, ?, false)', ['lock_timeout', $this->lockWait]);
        try {
            $this->explainingLockWait($step, $work);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    /** A lock wait that ran out (SQLSTATE 55P03) stops the run with what to do next. */
    private function explainingLockWait(string $step, Closure $work): void
    {
        try {
            $work();
        } catch (QueryException $e) {
            if ($e->getCode() !== '55P03') {
                throw $e;
            }

            throw new RuntimeException(sprintf(
                'Same-site media keys: lock timeout (55P03) while %s: another transaction held the table for more than %s. '
                .'The steps before it are kept and this one left nothing half-done (an index it had started is dropped and built again on the next run). '
                .'Run the migration again when traffic is quieter; to see what held it: '
                ."SELECT pid, state, xact_start, left(query, 80) FROM pg_stat_activity WHERE xact_start < now() - interval '5 seconds' ORDER BY xact_start.",
                $step,
                $this->lockWait,
            ), 0, $e);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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
 *     ids, before anything is changed (removing those rows is the owner's call);
 *   - in a nullable column the value is printed, then cleared (it named no file of that site, so it
 *     showed nothing; this cannot be undone).
 * Last, each key is validated outside any transaction: the scan holds no lock that blocks reads or
 * writes. The cleanup must see every website's rows, so up() first refuses a role that row security
 * filters. The old plain keys stay until 2099_01_01_000100 drops them.
 *
 * Every step is guarded (an index an interrupted build left INVALID is dropped and built again; a
 * constraint is added only when missing), so a run that stops half way can be repeated. down()
 * drops the nine keys (each drop locks media against reads for a moment: roll back at low traffic)
 * and the three indexes; cleared values are not put back.
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

    /** How many bad ids a printout or an error lists at most. */
    private const int SHOWN = 50;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->refuseUnlessEveryRowIsVisible();

        foreach (self::INDEXES as $name => [$kind, $table, $definition]) {
            $this->index($name, $kind, $table, $definition);
        }

        foreach (self::NULLABLE as [$table, $column]) {
            $this->briefly(fn () => $this->addConstraint($table, $column, "ON DELETE SET NULL ({$column})"));
        }
        $this->briefly(fn () => $this->addConstraint('product_images', 'media_id', 'ON DELETE CASCADE'));

        $this->stopOnForeignGalleryRows();
        foreach (self::NULLABLE as [$table, $column]) {
            $this->briefly(fn () => $this->clear($table, $column));
        }

        foreach ([...self::NULLABLE, ['product_images', 'media_id']] as [$table, $column]) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT ".self::name($table, $column));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Dropping a foreign key drops its triggers on media too, which locks media against every
        // read for that step: each drop is its own short step.
        foreach ([...self::NULLABLE, ['product_images', 'media_id']] as [$table, $column]) {
            $this->briefly(fn () => DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS ".self::name($table, $column)));
        }

        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
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

    /** A gallery row naming no media row of its own site cannot be cleared (media_id is NOT NULL): stop, change nothing. */
    private function stopOnForeignGalleryRows(): void
    {
        $bad = $this->foreign('product_images', 'media_id');

        if ($bad['count'] > 0) {
            throw new RuntimeException(sprintf(
                'Same-site media keys: %d product_images row(s) name a file that is not of their own website (ids: %s). Nothing was changed; decide what to do with these gallery rows, then run the migration again.',
                $bad['count'],
                implode(', ', $bad['ids']),
            ));
        }
    }

    /** Print, then clear, the values of one column that name no media row of their own site. */
    private function clear(string $table, string $column): void
    {
        $bad = $this->foreign($table, $column);
        if ($bad['count'] === 0) {
            return;
        }

        fwrite(STDERR, sprintf(
            "Same-site media keys: clearing %s.%s on %d row(s) that named no file of their own website (row id => media id: %s)\n",
            $table,
            $column,
            $bad['count'],
            implode(', ', $bad['pairs']),
        ));

        // Only a media-id column from the list above is written (never a body or a title).
        DB::table($table)
            ->whereNotNull($column)
            ->whereNotExists(fn (Builder $media) => $media->selectRaw('1')->from('media')
                ->whereColumn('media.site_id', "{$table}.site_id")->whereColumn('media.id', "{$table}.{$column}"))
            ->update([$column => null]);
    }

    /**
     * The rows of one column that name no media row of their own site, the first few by id.
     *
     * @return array{count: int, ids: list<int>, pairs: list<string>}
     */
    private function foreign(string $table, string $column): array
    {
        /** @var list<object{id: int, media_id: int, total: int}> $rows */
        $rows = DB::select(<<<SQL
            SELECT t.id, t.{$column} AS media_id, count(*) OVER () AS total
            FROM {$table} AS t
            WHERE t.{$column} IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM media m WHERE m.site_id = t.site_id AND m.id = t.{$column})
            ORDER BY t.id
            LIMIT ?
            SQL, [self::SHOWN]);

        return [
            'count' => $rows === [] ? 0 : $rows[0]->total,
            'ids' => array_map(fn (object $r): int => $r->id, $rows),
            'pairs' => array_map(fn (object $r): string => $r->id.' => '.$r->media_id, $rows),
        ];
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
     * gives up after 5 s (the release stops, re-runnable) instead of holding uploads and every
     * write to the table behind it.
     */
    private function briefly(Closure $step): void
    {
        DB::transaction(function () use ($step): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            $step();
        });
    }
};

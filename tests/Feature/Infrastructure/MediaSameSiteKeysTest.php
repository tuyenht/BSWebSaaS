<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Every column that names a media row names one of the same website: a composite foreign key
 * (site_id, <column>) → media (site_id, id). A foreign-key check bypasses row security, so with the
 * old plain keys an app_user write inside site A could point at site B's file; only the site_id in
 * the key refuses it. Purging a file clears the nullable references (site_id stays) and deletes its
 * gallery rows. The expand migration clears earlier bad references before it validates and stops on
 * a bad gallery row; both migrations roll back and forward.
 */

const SSK_EXPAND = 'migrations/2099_01_01_000000_add_same_site_media_foreign_keys.php';
const SSK_CONTRACT = 'migrations/2099_01_01_000100_drop_plain_media_foreign_keys.php';

/** Tables whose rows are links to a file (written by insert); the others hold the id on a content row. */
const SSK_LINK_TABLES = ['mediables', 'product_images'];

/** Stray attachments past the 50 ids a message names, to show the cleared-rows file lists them all. */
const SSK_EXTRA_ATTACHMENTS = 55;

beforeEach(function (): void {
    seedTenancy();
});

/** @return array<string, array{string, string}> every column that names a media row */
function sskColumns(): array
{
    return [
        'a post\'s cover' => ['entries', 'cover_media_id'],
        'a language\'s share image' => ['entry_translations', 'og_image_id'],
        'a category\'s cover' => ['taxonomies', 'cover_media_id'],
        'the logo' => ['site_profiles', 'logo_media_id'],
        'the logo on dark' => ['site_profiles', 'logo_dark_media_id'],
        'the favicon' => ['site_profiles', 'favicon_media_id'],
        'the share image' => ['site_profiles', 'og_image_media_id'],
        'an attachment' => ['mediables', 'media_id'],
        'a gallery picture' => ['product_images', 'media_id'],
    ];
}

function sskMedia(int $site, string $slug): Media
{
    return app(Tenancy::class)->enter($site, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', "{$site}-{$slug}"),
        'storage_key' => "s/library/{$slug}-{$site}.png", 'mime' => 'image/png', 'size' => 1_000,
    ]));
}

/**
 * One row per content table on a site, every media column empty, written as app_user in the site.
 *
 * @return array<string, int> table => row id (products: the product the gallery rows belong to)
 */
function sskRows(int $site): array
{
    return app(Tenancy::class)->enter($site, function () use ($site): array {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        $translation = $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => "gioi-thieu-{$site}", 'status' => 'draft']);

        return [
            'entries' => $entry->id,
            'entry_translations' => $translation->id,
            'taxonomies' => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0])->id,
            'site_profiles' => SiteProfile::query()->create(['brand_name' => 'Xưởng '.$site])->id,
            'products' => Product::query()->create(['status' => 'draft'])->id,
            'site_id' => $site,
        ];
    });
}

/** @param  array<string, int>  $rows */
function sskWrite(ConnectionInterface $db, string $table, string $column, ?int $mediaId, array $rows): void
{
    $now = now();
    match ($table) {
        'mediables' => $db->table('mediables')->insert([
            'site_id' => $rows['site_id'], 'media_id' => $mediaId, 'mediable_type' => 'entry', 'mediable_id' => $rows['entries'],
            'collection' => 'default', 'created_at' => $now, 'updated_at' => $now,
        ]),
        'product_images' => $db->table('product_images')->insert([
            'site_id' => $rows['site_id'], 'product_id' => $rows['products'], 'media_id' => $mediaId, 'position' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]),
        default => $db->table($table)->where('id', $rows[$table])->update([$column => $mediaId]),
    };
}

/**
 * What a site's rows hold in one column, read on the privileged connection.
 *
 * @param  array<string, int>  $rows
 * @return list<int|null>
 */
function sskStored(string $table, string $column, array $rows): array
{
    $query = DB::connection('pgsql_admin')->table($table)->where('site_id', $rows['site_id']);
    if (! in_array($table, SSK_LINK_TABLES, true)) {
        $query->where('id', $rows[$table]);
    }

    return $query->orderBy('id')->pluck($column)->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)->all();
}

/** @return list<string> every foreign key that points at media, as "<name> <valid|NOT VALID> <definition>" */
function sskKeys(): array
{
    return array_map(
        fn (object $k): string => $k->conname.' '.($k->convalidated ? 'valid' : 'NOT VALID').' '.$k->def,
        DB::connection('pgsql_admin')->select(
            "select conname, convalidated, pg_get_constraintdef(oid) as def from pg_constraint
             where contype = 'f' and confrelid = 'media'::regclass order by conname",
        ),
    );
}

/** @return array<string, string> the three indexes the expand migration builds, name => definition (valid ones only) */
function sskIndexes(): array
{
    return collect(DB::connection('pgsql_admin')->select(
        "select c.relname as name, pg_get_indexdef(i.indexrelid) as def from pg_index i join pg_class c on c.oid = i.indexrelid
         where c.relname in ('media_site_id_id_unique', 'entries_cover_media_id_index', 'entry_translations_og_image_id_index') and i.indisvalid
         order by c.relname",
    ))->pluck('def', 'name')->all();
}

/** Runs $step with the migrations' connection as the default, as `migrate --database=pgsql_admin` does. */
function sskAsMigrations(Closure $step): void
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $step();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

// ------------------------------------------------------------------ the keys

it('refuses another website\'s file in each media column, for the app inside a site and for the privileged role', function (string $table, string $column): void {
    $mine = sskMedia(1, 'cua-a');
    $theirs = sskMedia(2, 'cua-b');
    $rows = sskRows(1);
    $key = "{$table}_{$column}_same_site_foreign";

    app(Tenancy::class)->enter(1, function () use ($table, $column, $rows, $mine, $theirs, $key): void {
        // row security hides site B's file from app_user, but a key check bypasses row security
        expect(fn () => DB::transaction(fn () => sskWrite(DB::connection(), $table, $column, $theirs->id, $rows)))
            ->toThrow(QueryException::class, $key);
        sskWrite(DB::connection(), $table, $column, $mine->id, $rows);
    });

    expect(fn () => sskWrite(DB::connection('pgsql_admin'), $table, $column, $theirs->id, $rows))
        ->toThrow(QueryException::class, $key)
        ->and(sskStored($table, $column, $rows))->toBe([$mine->id]);
})->with(sskColumns());

it('clears every reference to a purged file and deletes its gallery rows, keeping the website on each row', function (): void {
    $purged = sskMedia(1, 'xoa-han');
    $kept = sskMedia(1, 'con-giu');
    $rows = sskRows(1);

    app(Tenancy::class)->enter(1, function () use ($rows, $purged, $kept): void {
        foreach (sskColumns() as [$table, $column]) {
            sskWrite(DB::connection(), $table, $column, $purged->id, $rows);
        }
        sskWrite(DB::connection(), 'product_images', 'media_id', $kept->id, $rows);

        // a mass purge fires no model event: the database alone clears and deletes
        Media::query()->whereKey($purged->id)->forceDelete();
    });

    foreach (sskColumns() as [$table, $column]) {
        // every row stays on site 1 (sskStored reads by site_id): only the media column changed
        expect(sskStored($table, $column, $rows))->toBe($table === 'product_images' ? [$kept->id] : [null], "{$table}.{$column}");
    }
});

it('pins the nine keys, validated, and leaves no plain key to media', function (): void {
    $expected = [];
    foreach (sskColumns() as [$table, $column]) {
        $onDelete = $table === 'product_images' ? 'CASCADE' : "SET NULL ({$column})";
        $expected[] = "{$table}_{$column}_same_site_foreign valid FOREIGN KEY (site_id, {$column}) REFERENCES media(site_id, id) ON DELETE {$onDelete}";
    }
    sort($expected);

    expect(sskKeys())->toBe($expected)
        ->and(sskIndexes())->toBe([
            'entries_cover_media_id_index' => 'CREATE INDEX entries_cover_media_id_index ON public.entries USING btree (cover_media_id) WHERE (cover_media_id IS NOT NULL)',
            'entry_translations_og_image_id_index' => 'CREATE INDEX entry_translations_og_image_id_index ON public.entry_translations USING btree (og_image_id) WHERE (og_image_id IS NOT NULL)',
            'media_site_id_id_unique' => 'CREATE UNIQUE INDEX media_site_id_id_unique ON public.media USING btree (site_id, id)',
        ]);
});

it('restores a version whose cover and share image were purged since, without them', function (): void {
    truncateRbac();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $editor = User::factory()->create();
    $cover = sskMedia(1, 'bia');
    $share = sskMedia(1, 'chia-se');

    app(Tenancy::class)->enter(1, function () use ($editor, $cover, $share): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $actor = $editor->fresh();
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'cover_media_id' => $cover->id, 'meta' => []]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Ảnh', 'slug' => 'anh', 'status' => 'draft', 'og_image_id' => $share->id, 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $actor);
        $v1 = EntryRevision::query()->where('entry_id', $page->id)->sole();

        Media::query()->whereKey([$cover->id, $share->id])->forceDelete();
        expect($page->fresh()->cover_media_id)->toBeNull();

        // the version still names both files: restoring it must not write their ids back
        app(RestoreEntryRevision::class)->handle($v1, $actor);
        $page = $page->fresh();
        expect($page->cover_media_id)->toBeNull()
            ->and($page->translationFor('vi')->og_image_id)->toBeNull();
    });
});

// ------------------------------------------------------------------ the migrations

it('clears earlier references to another website\'s file or to none, and stops first on such a gallery row', function (): void {
    $mine = sskMedia(1, 'cua-a');
    $theirs = sskMedia(2, 'cua-b');
    $siteA = sskRows(1);
    $siteB = sskRows(2);
    $expand = require database_path(SSK_EXPAND);
    $contract = require database_path(SSK_CONTRACT);
    $admin = DB::connection('pgsql_admin');
    $logs = [];

    sskAsMigrations(function () use ($expand, $contract, $admin, $siteA, $siteB, $mine, $theirs, &$logs): void {
        try {
            $contract->down();
            $expand->down();

            // written while only the plain keys stood: site A's rows name site B's file (the post's
            // cover, which had no key, names no file at all); site B's rows name its own file
            foreach (sskColumns() as [$table, $column]) {
                sskWrite($admin, $table, $column, $table === 'entries' ? 999_999 : $theirs->id, $siteA);
                sskWrite($admin, $table, $column, $theirs->id, $siteB);
            }
            sskWrite($admin, 'product_images', 'media_id', $mine->id, $siteA);
            // more stray attachments than a message would list: the file lists every one
            $admin->table('mediables')->insert(array_map(fn (int $i): array => [
                'site_id' => 1, 'media_id' => $theirs->id, 'mediable_type' => 'entry', 'mediable_id' => $siteA['entries'],
                'collection' => "extra-{$i}", 'created_at' => now(), 'updated_at' => now(),
            ], range(1, SSK_EXTRA_ATTACHMENTS)));

            $logs['stray_gallery'] = $admin->table('product_images')->where('site_id', 1)->where('media_id', $theirs->id)->value('id');
            $stop = null;
            try {
                $expand->up();
            } catch (RuntimeException $e) {
                $stop = $e->getMessage();
            }
            $logs['stopped'] = $expand->foundLog;
            expect($stop)->toContain('1 product_images row(s)')
                ->toContain('No row was changed, but the new keys are already in place')
                ->toContain((string) $expand->foundLog)
                ->and(sskStored('entries', 'cover_media_id', $siteA))->toBe([999_999])
                ->and(sskStored('site_profiles', 'logo_media_id', $siteA))->toBe([$theirs->id]);

            $admin->table('product_images')->where('site_id', 1)->where('media_id', $theirs->id)->delete();
            $expand->up();
            $logs['cleared'] = $expand->foundLog;
            $contract->up();
        } finally {
            // never leave the test database without the keys, whatever failed above
            $admin->table('product_images')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('media as m')
                ->whereColumn('m.site_id', 'product_images.site_id')->whereColumn('m.id', 'product_images.media_id'))->delete();
            $expand->up();
            $contract->up();
        }
    });

    foreach (sskColumns() as [$table, $column]) {
        $cleared = $table === 'mediables' ? array_fill(0, 1 + SSK_EXTRA_ATTACHMENTS, null) : [null];
        expect(sskStored($table, $column, $siteA))->toBe($table === 'product_images' ? [$mine->id] : $cleared, "site A {$table}.{$column}")
            ->and(sskStored($table, $column, $siteB))->toBe([$theirs->id], "site B {$table}.{$column}");
    }

    // every row found is in the run's file, in full: the gallery row that stopped the first run, and
    // each value the second one cleared (table, row, website, column, old file id)
    try {
        $read = fn (?string $path): array => array_map(
            fn (string $line): array => str_getcsv($line, escape: ''),
            file((string) $path, FILE_IGNORE_NEW_LINES) ?: [],
        );
        expect($read($logs['stopped'] ?? null))->toBe([
            ['action', 'table', 'id', 'site_id', 'column', 'old_media_id'],
            ['stopped', 'product_images', (string) $logs['stray_gallery'], '1', 'media_id', (string) $theirs->id],
        ]);

        $cleared = array_slice($read($logs['cleared'] ?? null), 1);
        $expected = [];
        foreach (sskColumns() as [$table, $column]) {
            if ($table === 'product_images') {
                continue;
            }
            $ids = $table === 'mediables'
                ? $admin->table('mediables')->where('site_id', 1)->whereNull('media_id')->orderBy('id')->pluck('id')->all()
                : [$siteA[$table]];
            foreach ($ids as $id) {
                $expected[] = ['cleared', $table, (string) $id, '1', $column, (string) ($table === 'entries' ? 999_999 : $theirs->id)];
            }
        }
        expect($cleared)->toBe($expected)->and($cleared)->toHaveCount(7 + 1 + SSK_EXTRA_ATTACHMENTS);
    } finally {
        foreach ([$logs['stopped'] ?? null, $logs['cleared'] ?? null] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }
});

it('refuses to clean up as a role that row security filters', function (): void {
    $expand = require database_path(SSK_EXPAND);
    $appRole = (string) DB::connection()->selectOne('select current_user as name')->name;
    $admin = DB::connection('pgsql_admin');

    sskAsMigrations(function () use ($expand, $admin, $appRole): void {
        $admin->transaction(function () use ($expand, $admin, $appRole): void {
            $admin->statement('SET LOCAL ROLE "'.str_replace('"', '""', $appRole).'"');
            expect(fn () => $expand->refuseUnlessEveryRowIsVisible())->toThrow(RuntimeException::class, 'cannot read every website');
        });
        $expand->refuseUnlessEveryRowIsVisible();
    });
});

it('rolls both migrations back and forward, twice each, as after a half-finished deploy', function (): void {
    $expand = require database_path(SSK_EXPAND);
    $contract = require database_path(SSK_CONTRACT);
    $after = ['keys' => sskKeys(), 'indexes' => sskIndexes()];

    $plain = [
        'mediables_media_id_foreign valid FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL',
        'product_images_media_id_foreign valid FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE',
        'site_profiles_favicon_media_id_foreign valid FOREIGN KEY (favicon_media_id) REFERENCES media(id) ON DELETE SET NULL',
        'site_profiles_logo_dark_media_id_foreign valid FOREIGN KEY (logo_dark_media_id) REFERENCES media(id) ON DELETE SET NULL',
        'site_profiles_logo_media_id_foreign valid FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL',
        'site_profiles_og_image_media_id_foreign valid FOREIGN KEY (og_image_media_id) REFERENCES media(id) ON DELETE SET NULL',
        'taxonomies_cover_media_id_foreign valid FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL',
    ];

    sskAsMigrations(function () use ($expand, $contract, $after, $plain): void {
        try {
            // rolled back alone, the first migration would leave the columns with no key: it refuses
            expect(fn () => $expand->down())->toThrow(RuntimeException::class, 'roll back 2099_01_01_000100 first')
                ->and(['keys' => sskKeys(), 'indexes' => sskIndexes()])->toBe($after);

            $contract->down();
            $contract->down();
            $both = [...$after['keys'], ...$plain];
            sort($both);
            expect(sskKeys())->toBe($both);

            $expand->down();
            $expand->down();
            expect(sskKeys())->toBe($plain)->and(sskIndexes())->toBe([]);

            $expand->up();
            $expand->up();
            $contract->up();
            $contract->up();
            expect(['keys' => sskKeys(), 'indexes' => sskIndexes()])->toBe($after);
        } finally {
            $expand->up();
            $contract->up();
        }
    });
});

it('does not drop a plain key before its same-site key is validated', function (): void {
    $expand = require database_path(SSK_EXPAND);
    $contract = require database_path(SSK_CONTRACT);

    sskAsMigrations(function () use ($expand, $contract): void {
        try {
            $contract->down();
            $expand->down();
            expect(fn () => $contract->up())->toThrow(RuntimeException::class, 'has no validated taxonomies_cover_media_id_same_site_foreign')
                ->and(sskKeys())->toHaveCount(7);
        } finally {
            $expand->up();
            $contract->up();
        }
    });
});

it('stops on a lock held too long, at an index build, a new key or a validation, and the next run finishes', function (): void {
    $expand = require database_path(SSK_EXPAND);
    $contract = require database_path(SSK_CONTRACT);
    $admin = DB::connection('pgsql_admin');
    $after = ['keys' => sskKeys(), 'indexes' => sskIndexes()];
    $sessionWait = $admin->scalar('SHOW lock_timeout');
    // another session (the app's) holds a lock in an open transaction while one run of the migration goes
    $app = DB::connection();
    $holding = function (string $lock, string $stop) use ($app, $expand): void {
        $app->beginTransaction();
        try {
            $app->statement($lock);
            sskAsMigrations(fn () => expect(fn () => $expand->up())->toThrow(RuntimeException::class, $stop));
        } finally {
            $app->rollBack();
        }
    };
    $mediaIndex = fn (): array => array_map(fn (object $i): bool => (bool) $i->indisvalid, $admin->select(
        "select i.indisvalid from pg_index i join pg_class c on c.oid = i.indexrelid where c.relname = 'media_site_id_id_unique'",
    ));
    $key = fn (string $name): array => array_values(array_filter(sskKeys(), fn (string $k): bool => str_starts_with($k, $name.' ')));

    try {
        $expand->lockWait = '300ms';
        sskAsMigrations(function () use ($expand, $contract): void {
            $contract->down();
            $expand->down();
        });

        // a write on media still open: the index build waits for it, gives up and leaves the index INVALID
        $holding('LOCK TABLE media IN ROW EXCLUSIVE MODE', 'lock timeout (55P03) while building the index media_site_id_id_unique: another transaction held the table for more than 300ms');
        expect($mediaIndex())->toBe([false])
            ->and($admin->scalar('SHOW lock_timeout'))->toBe($sessionWait);

        // the next run drops it and builds it again; then a lock on site_profiles stops it at that key
        $holding('LOCK TABLE site_profiles IN SHARE UPDATE EXCLUSIVE MODE', 'lock timeout (55P03) while adding the key on site_profiles.logo_media_id');
        expect($mediaIndex())->toBe([true])
            ->and($key('taxonomies_cover_media_id_same_site_foreign'))->toHaveCount(1)
            ->and($key('taxonomies_cover_media_id_same_site_foreign')[0])->toContain(' NOT VALID ')
            ->and($key('site_profiles_logo_media_id_same_site_foreign'))->toBe([]);

        // the next run adds the rest; a lock on taxonomies stops it at that validation
        $holding('LOCK TABLE taxonomies IN SHARE UPDATE EXCLUSIVE MODE', 'lock timeout (55P03) while validating taxonomies_cover_media_id_same_site_foreign');
        expect($key('entries_cover_media_id_same_site_foreign')[0] ?? '')->toContain(' valid ')
            ->and($key('taxonomies_cover_media_id_same_site_foreign')[0] ?? '')->toContain(' NOT VALID ')
            ->and($key('product_images_media_id_same_site_foreign'))->toHaveCount(1)
            ->and($admin->scalar('SHOW lock_timeout'))->toBe($sessionWait);

        // nothing in the way: the run finishes, and the database is as after a clean one
        sskAsMigrations(function () use ($expand, $contract): void {
            $expand->up();
            $contract->up();
        });
        expect(['keys' => sskKeys(), 'indexes' => sskIndexes()])->toBe($after);
    } finally {
        $expand->lockWait = '5s';
        sskAsMigrations(function () use ($expand, $contract): void {
            $expand->up();
            $contract->up();
        });
    }
});

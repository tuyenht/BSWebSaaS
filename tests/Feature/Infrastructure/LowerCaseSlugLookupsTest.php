<?php

use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The slug and invitation-address lookups on website tables compare the citext column read as text
 * against a lower-cased value, because under RLS only a leakproof operator may become an index
 * condition and no citext operator is one. This pins the indexes built on that expression, the
 * lower-case CHECKs that keep the stored values findable, and plans the real queries as app_user
 * inside a website, with the generic plan a prepared statement gets (`plan_cache_mode =
 * force_generic_plan`), so a query rewritten back to a citext comparison fails here.
 */

/** @var array<string, array{string, string}> constraint => [table, column] */
const LCL_CHECKS = [
    'taxonomy_translations_slug_lower_case' => ['taxonomy_translations', 'slug'],
    'entry_translations_slug_lower_case' => ['entry_translations', 'slug'],
    'product_translations_slug_lower_case' => ['product_translations', 'slug'],
    'invitations_email_lower_case' => ['invitations', 'email'],
];

/** Puts a CHECK back as the migration leaves it: present and NOT VALID. */
function lclResetCheck(string $constraint): void
{
    [$table, $column] = LCL_CHECKS[$constraint];
    $admin = DB::connection('pgsql_admin');
    $admin->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
    $admin->statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column}::text = lower({$column}::text)) NOT VALID");
}

/** @return array<string, bool> constraint => validated */
function lclValidated(): array
{
    $rows = DB::connection('pgsql_admin')->table('pg_constraint')
        ->whereIn('conname', array_keys(LCL_CHECKS))
        ->pluck('convalidated', 'conname')
        ->all();
    ksort($rows);

    return $rows;
}

beforeEach(function (): void {
    seedTenancy();
    foreach (array_keys(LCL_CHECKS) as $constraint) {
        lclResetCheck($constraint);
    }
});

afterEach(function (): void {
    foreach (array_keys(LCL_CHECKS) as $constraint) {
        lclResetCheck($constraint);
    }
});

/**
 * The generic plan of $sql as app_user inside site 1 (RLS on), as JSON: the plan a prepared
 * statement keeps under `plan_cache_mode = force_generic_plan`, where no parameter value is known
 * while planning. `EXPLAIN (GENERIC_PLAN)` plans the `$n` placeholders as such; it is sent unbound
 * (emulated prepare), and a SQL-level PREPARE would outlive the transaction on the pooled connection.
 *
 * @param  array<int, mixed>  $bindings  only counted: the generic plan never sees their values
 */
function lclGenericPlan(string $sql, array $bindings): string
{
    return app(Tenancy::class)->enter(1, function () use ($sql, $bindings): string {
        $n = 0;
        $numbered = (string) preg_replace_callback('/\?/', function () use (&$n): string {
            return '$'.++$n;
        }, $sql);
        expect($n)->toBe(count($bindings));

        DB::statement('SET LOCAL plan_cache_mode = force_generic_plan');
        $pdo = DB::getPdo();
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

        try {
            $statement = $pdo->query("EXPLAIN (GENERIC_PLAN, FORMAT JSON) {$numbered}");

            return (string) $statement->fetchColumn();
        } finally {
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    });
}

/**
 * How a JSON plan reads its table: [index name, index condition] per index read (plain, index-only
 * or bitmap), ['Seq Scan', ''] for a full read.
 *
 * @return list<array{string, string}>
 */
function lclScans(string $json): array
{
    $scans = [];
    $walk = function (array $node) use (&$walk, &$scans): void {
        if (($node['Node Type'] ?? '') === 'Seq Scan') {
            $scans[] = ['Seq Scan', ''];
        } elseif (isset($node['Index Name'])) {
            $scans[] = [(string) $node['Index Name'], (string) ($node['Index Cond'] ?? '')];
        }
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk(json_decode($json, true)[0]['Plan']);

    return $scans;
}

/**
 * The SQL and bindings of the first query on $table that $run sends.
 *
 * @return array{sql: string, bindings: array<int, mixed>}
 */
function lclCapture(string $table, Closure $run, string $marker = ''): array
{
    $seen = null;
    DB::listen(function (QueryExecuted $query) use (&$seen, $table, $marker): void {
        if ($seen === null && str_contains($query->sql, "from \"{$table}\"") && str_contains($query->sql, $marker)) {
            $seen = ['sql' => $query->sql, 'bindings' => $query->connection->prepareBindings($query->bindings)];
        }
    });

    app(Tenancy::class)->enter(1, $run);

    expect($seen)->not->toBeNull("No query on {$table} was sent.");

    return $seen;
}

it('defines each lookup index on the column read as text', function (string $index, string $definition): void {
    $row = DB::selectOne('select pg_get_indexdef(indexrelid) as def, indisvalid as valid from pg_index where indexrelid = to_regclass(?)', [$index]);

    expect($row)->not->toBeNull("{$index} is missing")
        ->and($row->valid)->toBeTrue()
        ->and($row->def)->toBe("CREATE INDEX {$index} ON {$definition}");
})->with([
    'category slug' => ['taxonomy_translations_site_id_locale_slug_text_index', 'public.taxonomy_translations USING btree (site_id, locale, ((slug)::text))'],
    'entry slug' => ['entry_translations_site_id_locale_slug_text_index', 'public.entry_translations USING btree (site_id, locale, ((slug)::text) text_pattern_ops)'],
    'product slug' => ['product_translations_site_id_locale_slug_text_index', 'public.product_translations USING btree (site_id, locale, ((slug)::text) text_pattern_ops)'],
    'pending invitation address' => ['invitations_pending_email_text_index', 'public.invitations USING btree (site_id, ((email)::text)) WHERE ((accepted_at IS NULL) AND (revoked_at IS NULL))'],
]);

it('drops the citext category index app_user could not use past the language', function (): void {
    expect(DB::selectOne("select to_regclass('taxonomy_translations_site_id_locale_slug_index') as idx")->idx)->toBeNull();
});

it('holds new links and invitation addresses to lower case', function (string $constraint): void {
    [$table, $column] = LCL_CHECKS[$constraint];
    $def = DB::connection('pgsql_admin')->selectOne(
        'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ? and conrelid = to_regclass(?)',
        [$constraint, $table],
    );

    expect($def?->def)->toBe("CHECK ((({$column})::text = lower(({$column})::text))) NOT VALID");
})->with(array_keys(LCL_CHECKS));

it('refuses a link stored with capitals', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);

        expect(fn () => DB::transaction(fn () => $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'Tin-Tuc'])))
            ->toThrow(QueryException::class, 'taxonomy_translations_slug_lower_case');
    });
});

it('plans the storefront grid category lookup on the slug index, generic plan included', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->statement("insert into taxonomies (site_id, vocabulary, position) select 1 + g % 2, 'post_cat', g from generate_series(1, 4000) g");
    $admin->statement("insert into taxonomy_translations (taxonomy_id, site_id, locale, name, slug) select id, site_id, 'vi', 'Mục '||id, 'muc-'||id from taxonomies");
    $admin->statement('analyze taxonomies, taxonomy_translations');

    ['sql' => $sql, 'bindings' => $bindings] = lclCapture('taxonomy_translations', function (): void {
        app(EntryGridComponent::class)->data(['taxonomy_slug' => 'Muc-7', 'locale' => 'vi']);
    });

    expect($sql)->toContain('slug::text = ?')->and($bindings)->toContain('muc-7');

    $scans = lclScans(lclGenericPlan($sql, $bindings));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('taxonomy_translations_site_id_locale_slug_text_index')
        ->and($scans[0][1])->toContain('(slug)::text = $');
});

it('plans the free-link probe and its fallback range on the entry slug index, generic plan included', function (): void {
    $admin = DB::connection('pgsql_admin');
    $admin->statement("insert into entries (ulid, site_id, type) select lpad(g::text, 26, '0'), 1 + g % 2, 'post' from generate_series(1, 4000) g");
    $admin->statement("insert into entry_translations (entry_id, site_id, locale, title, slug, status) select id, site_id, 'vi', 'Bài '||id, 'bai-'||id, 'draft' from entries");
    // One base with every probed copy taken, so the lookup falls back to the range read.
    $admin->statement("insert into entries (ulid, site_id, type) select lpad(g::text, 26, 'f'), 1, 'post' from generate_series(1, 60) g");
    $admin->statement("insert into entry_translations (entry_id, site_id, locale, title, slug, status) select id, 1, 'vi', 'Trùng', case when ulid = lpad('1', 26, 'f') then 'trung' else 'trung-'||ltrim(ulid, 'f') end, 'draft' from entries where ulid like 'f%'");
    $admin->statement('analyze entries, entry_translations');

    $query = fn () => EntryTranslation::query()->where('locale', 'vi');

    $probe = lclCapture('entry_translations', fn () => Slugger::takenAmong($query(), 'slug', 'bai-7'));
    $scans = lclScans(lclGenericPlan($probe['sql'], $probe['bindings']));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('entry_translations_site_id_locale_slug_text_index')
        ->and($scans[0][1])->toContain('(slug)::text = ANY');

    $range = lclCapture('entry_translations', fn () => expect(Slugger::takenAmong($query(), 'slug', 'trung'))->toHaveCount(60), '~>=~');
    $scans = lclScans(lclGenericPlan($range['sql'], $range['bindings']));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('entry_translations_site_id_locale_slug_text_index')
        ->and($scans[0][1])->toContain('~>=~ $')->toContain('~<~ $');
});

it('reports no capitals and leaves the CHECKs as they are without --validate', function (): void {
    $this->artisan('db:lower-case-keys')
        ->expectsOutputToContain('taxonomy_translations.slug: 0 rows with capitals · CHECK not validated')
        ->expectsOutputToContain('invitations.email: 0 rows with capitals · CHECK not validated')
        ->assertSuccessful();

    expect(array_values(lclValidated()))->toBe([false, false, false, false]);
});

it('names the rows with capitals, validates nothing while they remain, then validates every CHECK', function (): void {
    $admin = DB::connection('pgsql_admin');
    $entry = $admin->table('entries')->insertGetId(['ulid' => str_repeat('0', 25).'1', 'site_id' => 2, 'type' => 'page']);
    // A row from before the CHECK: the CHECK is put back NOT VALID over it, as on a live database.
    $admin->statement('ALTER TABLE entry_translations DROP CONSTRAINT entry_translations_slug_lower_case');
    $row = $admin->table('entry_translations')->insertGetId(['entry_id' => $entry, 'site_id' => 2, 'locale' => 'vi', 'title' => 'Cũ', 'slug' => 'Bang-Gia', 'status' => 'draft']);
    lclResetCheck('entry_translations_slug_lower_case');

    $this->artisan('db:lower-case-keys')
        ->expectsOutputToContain('entry_translations.slug: 1 rows with capitals · CHECK not validated')
        ->expectsOutputToContain("site 2, row {$row}")
        ->assertFailed();

    $this->artisan('db:lower-case-keys --validate')
        ->expectsOutputToContain('1 rows still hold capitals: lower-case them first. Nothing validated.')
        ->assertFailed();
    expect(array_values(lclValidated()))->toBe([false, false, false, false]);

    $admin->table('entry_translations')->where('id', $row)->update(['slug' => 'bang-gia']);

    $this->artisan('db:lower-case-keys --validate')
        ->expectsOutputToContain('entry_translations: entry_translations_slug_lower_case validated')
        ->expectsOutputToContain('Every lower-case CHECK is validated.')
        ->assertSuccessful();
    expect(array_values(lclValidated()))->toBe([true, true, true, true]);

    $this->artisan('db:lower-case-keys')
        ->expectsOutputToContain('entry_translations.slug: 0 rows with capitals · CHECK validated')
        ->assertSuccessful();
});

it('validates nothing while a CHECK is missing', function (): void {
    DB::connection('pgsql_admin')->statement('ALTER TABLE invitations DROP CONSTRAINT invitations_email_lower_case');

    $this->artisan('db:lower-case-keys --validate')
        ->expectsOutputToContain('invitations.email: 0 rows with capitals · CHECK missing')
        ->expectsOutputToContain('A lower-case CHECK is missing: run the migrations first. Nothing validated.')
        ->assertFailed();

    expect(lclValidated())->not->toHaveKey('invitations_email_lower_case');
});

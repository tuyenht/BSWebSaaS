<?php

use App\Modules\Admin\Livewire\Catalog\Editor as CatalogEditor;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\InvitationRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Shared\Support\Slugger;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The slug and invitation-address lookups on website tables compare the citext column read as text
 * against a lower-cased value, because under RLS only a leakproof operator may become an index
 * condition and no citext operator is one. This pins the indexes built on that expression, that no
 * lower-case CHECK holds old rows back yet, and plans the real queries as app_user inside a website,
 * with the generic plan a prepared statement gets (`plan_cache_mode = force_generic_plan`), so a
 * query rewritten back to a citext comparison fails here.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** Catalog roles and permissions as a fresh install has them. */
function lclSyncRbac(): void
{
    truncateRbac();
    expect(Artisan::call('rbac:sync'))->toBe(0);
    app(TeamContext::class)->forget();
}

/** 4000 products over sites 1 and 2, each with a Vietnamese link `ban-<id>`. */
function lclSeedProducts(): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->statement("insert into products (ulid, site_id) select lpad(g::text, 26, '0'), 1 + g % 2 from generate_series(1, 4000) g");
    $admin->statement("insert into product_translations (product_id, site_id, locale, name, slug) select id, site_id, 'vi', 'Bàn '||id, 'ban-'||id from products");
    $admin->statement('analyze products, product_translations');
}

/** 4000 categories over sites 1 and 2, each with a Vietnamese link `muc-<id>`. */
function lclSeedCategories(): void
{
    $admin = DB::connection('pgsql_admin');
    $admin->statement("insert into taxonomies (site_id, vocabulary, position) select 1 + g % 2, 'post_cat', g from generate_series(1, 4000) g");
    $admin->statement("insert into taxonomy_translations (taxonomy_id, site_id, locale, name, slug) select id, site_id, 'vi', 'Mục '||id, 'muc-'||id from taxonomies");
    $admin->statement('analyze taxonomies, taxonomy_translations');
}

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

it('adds no lower-case CHECK, so a row stored with capitals can still be saved', function (): void {
    $checks = DB::connection('pgsql_admin')->table('pg_constraint')->where('conname', 'like', '%lower_case')->pluck('conname')->all();
    expect($checks)->toBe([]);

    $saved = app(Tenancy::class)->enter(1, function (): string {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $translation = $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'Tin-Tuc']);
        $translation->update(['name' => 'Tin tức mới']);

        return (string) $translation->fresh()?->name;
    });

    expect($saved)->toBe('Tin tức mới');
});

it('plans the storefront grid category lookup on the slug index, generic plan included', function (): void {
    lclSeedCategories();

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

it('plans the category slug-taken check on the category slug index, generic plan included', function (): void {
    lclSeedCategories();

    ['sql' => $sql, 'bindings' => $bindings] = lclCapture('taxonomy_translations', function (): void {
        app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Mục mới'], slugs: ['vi' => 'muc-moi']));
    }, 'slug::text = ?');

    expect($bindings)->toContain('muc-moi');

    $scans = lclScans(lclGenericPlan($sql, $bindings));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('taxonomy_translations_site_id_locale_slug_text_index')
        ->and($scans[0][1])->toContain('(slug)::text = $');
});

it('plans the product link rule on the product slug index, generic plan included', function (): void {
    lclSyncRbac();
    lclSeedProducts();
    $editor = User::factory()->create();

    ['sql' => $sql, 'bindings' => $bindings] = lclCapture('product_translations', function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())->test(CatalogEditor::class)
            ->set('name', 'Bàn khác')->set('slug', 'Ban-7')->call('save')
            ->assertHasErrors(['slug']);
    }, 'slug::text = ?');

    expect($bindings)->toContain('ban-7');

    $scans = lclScans(lclGenericPlan($sql, $bindings));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('product_translations_site_id_locale_slug_text_index')
        ->and($scans[0][1])->toContain('(slug)::text = $');
});

it('plans the free product link probe on the product slug index, generic plan included', function (): void {
    lclSeedProducts();

    $probe = lclCapture('product_translations', fn () => Slugger::takenAmong(ProductTranslation::query()->where('locale', 'vi'), 'slug', 'ban-7'));

    $scans = lclScans(lclGenericPlan($probe['sql'], $probe['bindings']));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('product_translations_site_id_locale_slug_text_index')
        ->and($scans[0][1])->toContain('(slug)::text = ANY');
});

it('plans the pending-invitation lookup on the invitation address index, generic plan included', function (): void {
    lclSyncRbac();
    $admin = DB::connection('pgsql_admin');
    $admin->statement("insert into invitations (ulid, site_id, email, token_hash, expires_at, sent_at, created_at, updated_at) select lpad(g::text, 26, '0'), 1 + g % 2, 'khach-'||g||'@example.test', md5(g::text)||md5('x'||g), now() + interval '7 days', now() - interval '2 days', now(), now() from generate_series(1, 4000) g");
    $admin->statement('analyze invitations');

    $owner = User::factory()->create();
    ['sql' => $sql, 'bindings' => $bindings] = lclCapture('invitations', function () use ($owner): void {
        $roles = [];
        foreach (['owner', 'viewer'] as $name) {
            $template = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
            $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
            $role->forceFill(['level' => $template['level'], 'is_system' => true])->save();
            $role->syncPermissions($template['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $template['permissions']);
            $roles[$name] = $role;
        }
        $owner->assignRole($roles['owner']);

        try {
            app(InviteMember::class)->handle($owner->fresh(), 'Moi@Example.Test', [$roles['viewer']]);
        } catch (InvitationRejectedException) {
            // A send limit refusing the mail comes after the lookup this plans.
        }
    }, 'email::text = ?');

    expect($bindings)->toContain('moi@example.test');

    $scans = lclScans(lclGenericPlan($sql, $bindings));
    expect($scans)->toHaveCount(1)
        ->and($scans[0][0])->toBe('invitations_pending_email_text_index')
        ->and($scans[0][1])->toContain('(email)::text = $');
});

it('reports no capitals in any column and succeeds', function (): void {
    $this->artisan('db:lower-case-keys')
        ->expectsOutputToContain('taxonomy_translations.slug: 0 rows with capitals')
        ->expectsOutputToContain('entry_translations.slug: 0 rows with capitals')
        ->expectsOutputToContain('product_translations.slug: 0 rows with capitals')
        ->expectsOutputToContain('invitations.email: 0 rows with capitals')
        ->expectsOutputToContain('media_folders.slug: 0 rows with capitals')
        ->expectsOutputToContain('No row holds capitals.')
        ->assertSuccessful();
});

it('names the website and row of each row with capitals, never its value, and fails while any remain', function (): void {
    $admin = DB::connection('pgsql_admin');
    $entry = $admin->table('entries')->insertGetId(['ulid' => str_repeat('0', 25).'1', 'site_id' => 2, 'type' => 'page']);
    $row = $admin->table('entry_translations')->insertGetId(['entry_id' => $entry, 'site_id' => 2, 'locale' => 'vi', 'title' => 'Cũ', 'slug' => 'Bang-Gia', 'status' => 'draft']);
    $folder = $admin->table('media_folders')->insertGetId(['site_id' => 1, 'name' => 'Ảnh cũ', 'slug' => 'Anh-Cu', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('db:lower-case-keys')
        ->expectsOutputToContain('entry_translations.slug: 1 rows with capitals')
        ->expectsOutputToContain("site 2, row {$row}")
        ->expectsOutputToContain('media_folders.slug: 1 rows with capitals')
        ->expectsOutputToContain("site 1, row {$folder}")
        ->expectsOutputToContain('2 rows hold capitals')
        ->doesntExpectOutputToContain('Bang-Gia')
        ->doesntExpectOutputToContain('Anh-Cu')
        ->assertFailed();

    $admin->table('entry_translations')->where('id', $row)->update(['slug' => 'bang-gia']);
    $admin->table('media_folders')->where('id', $folder)->update(['slug' => 'anh-cu']);

    $this->artisan('db:lower-case-keys')
        ->expectsOutputToContain('No row holds capitals.')
        ->assertSuccessful();
});

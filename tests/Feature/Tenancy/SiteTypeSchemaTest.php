<?php

use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Concerns\BelongsToSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * T2 increment-2 — schema nền for site_type (design.md §15.2 / §17 D6). site_types is a GLOBAL
 * catalog (no site_id, no RLS); sites + roles gain nullable additive FKs (site_type_id /
 * forked_from_role_id). These assertions pin the expand-safe shape. The migration's one-shot
 * BACKFILL of pre-existing rows + the down() rollback ordering are verified manually (plan §7) —
 * they cannot be observed in the truncated shared test DB without corrupting other suites.
 */

beforeEach(function () {
    seedTenancy();      // sites 1=A, 2=B (no site_type_id) — corporate persists (migration-seeded)
    truncateRbac();
});

/** PostgreSQL data_type of a column (null if the column is absent). */
function stColumnType(string $table, string $column): ?string
{
    $row = DB::selectOne(
        'select data_type from information_schema.columns where table_schema = current_schema() and table_name = ? and column_name = ?',
        [$table, $column],
    );

    return $row->data_type ?? null;
}

/**
 * True if $table.$column carries a FOREIGN KEY referencing $referenced. Reads pg_catalog (not
 * information_schema.constraint_column_usage, which is privilege-filtered and invisible to the
 * restricted app_user the tests run as).
 */
function stForeignKeyTo(string $table, string $column, string $referenced): bool
{
    $rows = DB::select(
        <<<'SQL'
            select 1
            from pg_constraint con
            join pg_class c on c.oid = con.conrelid
            join pg_class f on f.oid = con.confrelid
            join pg_attribute a on a.attrelid = con.conrelid and a.attnum = any (con.conkey)
            where con.contype = 'f'
              and c.relname = ? and a.attname = ? and f.relname = ?
        SQL,
        [$table, $column, $referenced],
    );

    return count($rows) > 0;
}

// ── A. site_types catalog table ────────────────────────────────────────────

it('creates site_types as a GLOBAL catalog (no site_id, unique key, jsonb, timestamptz)', function () {
    expect(Schema::hasTable('site_types'))->toBeTrue()
        ->and(Schema::hasColumns('site_types', ['id', 'key', 'label', 'meta', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumn('site_types', 'site_id'))->toBeFalse()                 // D6: global, no tenant column
        ->and(stColumnType('site_types', 'label'))->toBe('jsonb')
        ->and(stColumnType('site_types', 'meta'))->toBe('jsonb')
        ->and(stColumnType('site_types', 'created_at'))->toBe('timestamp with time zone'); // D11

    // key is UNIQUE — a second 'corporate' (privileged insert) must violate the constraint.
    expect(fn () => DB::connection('pgsql_admin')->table('site_types')->insert([
        'key' => 'corporate', 'label' => '{}', 'meta' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('seeds the corporate site_type with tri-locale labels', function () {
    $row = DB::table('site_types')->where('key', 'corporate')->first();
    expect($row)->not->toBeNull();

    /** @var array<string,string> $label */
    $label = json_decode($row->label, true);
    expect($label)->toHaveKeys(['vi', 'en', 'ja'])
        ->and($label['vi'])->not->toBe('');
});

it('adds sites.site_type_id as a nullable FK to site_types', function () {
    expect(Schema::hasColumn('sites', 'site_type_id'))->toBeTrue()
        ->and(DB::selectOne(
            'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            ['sites', 'site_type_id'],
        )->is_nullable)->toBe('YES')
        ->and(stForeignKeyTo('sites', 'site_type_id', 'site_types'))->toBeTrue();
});

it('adds roles.forked_from_role_id as a nullable self-FK on roles', function () {
    expect(Schema::hasColumn('roles', 'forked_from_role_id'))->toBeTrue()
        ->and(DB::selectOne(
            'select is_nullable from information_schema.columns where table_name = ? and column_name = ?',
            ['roles', 'forked_from_role_id'],
        )->is_nullable)->toBe('YES')
        ->and(stForeignKeyTo('roles', 'forked_from_role_id', 'roles'))->toBeTrue();
});

it('keeps site_types OUT of RLS scope (global, D6) — RLS not enabled', function () {
    // No site_id column ⇒ the RlsCoverageTest heuristic never flags it; assert the table is
    // genuinely RLS-free (a tenant table would be relrowsecurity = true).
    $row = DB::selectOne('select relrowsecurity from pg_class where relname = ?', ['site_types']);
    expect($row)->not->toBeNull()
        ->and($row->relrowsecurity)->toBeFalse();
});

// ── B. Models ───────────────────────────────────────────────────────────────

it('SiteType is a global model (no BelongsToSite) with json casts and a sites() relation', function () {
    expect(class_uses_recursive(SiteType::class))->not->toContain(BelongsToSite::class);

    $corporate = SiteType::query()->where('key', 'corporate')->first();
    expect($corporate->label)->toBeArray()
        ->and($corporate->meta)->toBeArray()
        ->and($corporate->sites())->toBeInstanceOf(HasMany::class);
});

it('Site::siteType() resolves the linked corporate type', function () {
    $corporate = SiteType::query()->where('key', 'corporate')->first();
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['site_type_id' => $corporate->id]);

    $site = Site::query()->find(1);
    expect($site->siteType())->toBeInstanceOf(BelongsTo::class)
        ->and($site->siteType->key)->toBe('corporate');
});

it('Role::forkedFrom() is a self relation, null until copy-on-write', function () {
    $role = app(Tenancy::class)->enter(1, fn () => Role::findOrCreate('tmp_role', PermissionRegistry::GUARD));

    expect($role->forkedFrom())->toBeInstanceOf(BelongsTo::class)
        ->and($role->fresh()->forkedFrom)->toBeNull();
});

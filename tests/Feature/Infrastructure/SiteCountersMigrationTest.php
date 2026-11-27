<?php

use Illuminate\Support\Facades\DB;

/*
 * The site_counters table: a website's own rows (RLS enabled and forced, one isolation policy that
 * fails closed), the website's foreign key cascading, the metric name checked, one unique key that
 * is the upsert's target, app_user's four rights; down() drops it all and up() builds it again.
 */

beforeEach(function (): void {
    seedTenancy();
});

function scmShape(): array
{
    $db = DB::connection('pgsql_admin');

    return [
        'columns' => collect($db->select("select column_name, data_type, is_nullable, column_default from information_schema.columns where table_name = 'site_counters' order by ordinal_position"))
            ->mapWithKeys(fn ($r): array => [$r->column_name => $r->data_type.($r->is_nullable === 'YES' ? ' null' : '')])->all(),
        'indexes' => collect($db->select("select indexname, indexdef from pg_indexes where tablename = 'site_counters' order by indexname"))->mapWithKeys(fn ($r): array => [$r->indexname => $r->indexdef])->all(),
        'rls' => (array) $db->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'site_counters'"),
        'policies' => collect($db->select("select policyname, qual, with_check from pg_policies where tablename = 'site_counters'"))->map(fn ($r): array => (array) $r)->all(),
        'checks' => collect($db->select("select conname from pg_constraint where conrelid = 'site_counters'::regclass and contype = 'c'"))->pluck('conname')->all(),
        'fk' => $db->scalar("select confdeltype from pg_constraint where conrelid = 'site_counters'::regclass and contype = 'f' and confrelid = 'sites'::regclass"),
        'grants' => collect($db->select("select privilege_type from information_schema.role_table_grants where table_name = 'site_counters' and grantee = 'app_user' order by privilege_type"))->pluck('privilege_type')->all(),
    ];
}

it('builds the counters table a website keeps to itself, and takes it down and up again', function (): void {
    $migration = require base_path('database/migrations/2103_01_01_000000_create_site_counters.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $after = scmShape();
        expect($after['columns'])->toBe([
            'id' => 'bigint', 'site_id' => 'bigint', 'metric' => 'character varying', 'subject_id' => 'bigint',
            'value' => 'bigint', 'updated_at' => 'timestamp with time zone',
        ])
            ->and($after['indexes'])->toHaveKeys(['site_counters_pkey', 'site_counters_site_metric_subject_unique'])
            ->and($after['indexes']['site_counters_site_metric_subject_unique'])->toContain('(site_id, metric, subject_id)')
            ->and($after['rls'])->toBe(['relrowsecurity' => true, 'relforcerowsecurity' => true])
            ->and($after['policies'])->toHaveCount(1)
            ->and($after['policies'][0]['policyname'])->toBe('site_counters_site_isolation')
            ->and($after['policies'][0]['qual'])->toContain("current_setting('app.current_site_id'::text, true)")
            ->and($after['policies'][0]['with_check'])->toContain("current_setting('app.current_site_id'::text, true)")
            ->and($after['checks'])->toBe(['site_counters_metric_check'])
            ->and($after['fk'])->toBe('c') // ON DELETE CASCADE
            ->and($after['grants'])->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE']);

        DB::transaction(fn () => $migration->down());
        expect(DB::connection('pgsql_admin')->scalar("select to_regclass('site_counters')"))->toBeNull();

        DB::transaction(fn () => $migration->up());
        expect(scmShape())->toEqual($after);
    } finally {
        if (DB::connection('pgsql_admin')->scalar("select to_regclass('site_counters')") === null) {
            DB::transaction(fn () => $migration->up());
        }
        DB::setDefaultConnection($previous);
    }
});

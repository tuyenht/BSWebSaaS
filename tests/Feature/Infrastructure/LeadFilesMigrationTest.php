<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * The two migrations behind the files a visitor sends with a lead. The table: a website's own rows
 * (RLS enabled and forced, one isolation policy), the lead's foreign key set to NULL when the lead is
 * deleted, the index for one lead's files and the partial index the sweeper walks; down() drops it
 * all and up() builds it again. The permission: every role that manages leads is given forms.files,
 * the «Chỉ xem» role (forms.view only) is not; down() takes the permission away.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function lfmShape(): array
{
    $db = DB::connection('pgsql_admin');

    return [
        'columns' => collect($db->select("select column_name, data_type, is_nullable from information_schema.columns where table_name = 'form_submission_files' order by ordinal_position"))
            ->mapWithKeys(fn ($r): array => [$r->column_name => $r->data_type.($r->is_nullable === 'YES' ? ' null' : '')])->all(),
        'indexes' => collect($db->select("select indexname, indexdef from pg_indexes where tablename = 'form_submission_files' order by indexname"))->mapWithKeys(fn ($r): array => [$r->indexname => $r->indexdef])->all(),
        'rls' => $db->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'form_submission_files'"),
        'policies' => collect($db->select("select policyname from pg_policies where tablename = 'form_submission_files'"))->pluck('policyname')->all(),
        'fk' => $db->selectOne("select confdeltype from pg_constraint where conname = 'form_submission_files_form_submission_id_foreign' or (conrelid = 'form_submission_files'::regclass and contype = 'f' and confrelid = 'form_submissions'::regclass)"),
    ];
}

it('builds the lead files table a website keeps to itself, and takes it down and up again', function (): void {
    $migration = require base_path('database/migrations/2090_01_01_000100_create_form_submission_files_table.php');
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        $after = lfmShape();
        expect($after['columns'])->toMatchArray([
            'site_id' => 'bigint', 'ulid' => 'character', 'form_submission_id' => 'bigint null', 'storage_key' => 'character varying null',
            'size_bytes' => 'bigint', 'status' => 'character varying', 'created_at' => 'timestamp with time zone null',
        ])
            ->and($after['indexes'])->toHaveKeys(['form_submission_files_submission_idx', 'form_submission_files_orphan_idx'])
            ->and($after['indexes']['form_submission_files_orphan_idx'])->toContain('(site_id, id) WHERE (form_submission_id IS NULL)')
            ->and($after['rls']->relrowsecurity)->toBeTrue()
            ->and($after['rls']->relforcerowsecurity)->toBeTrue()
            ->and($after['policies'])->toBe(['form_submission_files_site_isolation'])
            ->and($after['fk']->confdeltype)->toBe('n'); // ON DELETE SET NULL

        DB::transaction(fn () => $migration->down());
        expect(DB::connection('pgsql_admin')->scalar("select to_regclass('form_submission_files')"))->toBeNull();

        DB::transaction(fn () => $migration->up());
        expect(lfmShape())->toEqual($after);
    } finally {
        if (DB::connection('pgsql_admin')->scalar("select to_regclass('form_submission_files')") === null) {
            DB::transaction(fn () => $migration->up());
        }
        DB::setDefaultConnection($previous);
    }
});

it('gives forms.files to every role that manages leads, not to the read-only one, and takes it back', function (): void {
    $migration = require base_path('database/migrations/2090_01_01_000200_grant_lead_files_permission.php');
    $db = DB::connection('pgsql_admin');
    $holders = fn (string $permission): array => $db->table('role_has_permissions as rp')
        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('p.name', $permission)->orderBy('r.id')->pluck('r.id')->all();

    $manager = User::factory()->create();
    $reader = User::factory()->create();
    $theirs = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($manager, $reader): void {
        grantRole($manager, 'lfm-handles-leads', ['forms.view', 'forms.manage']);
        grantRole($reader, 'lfm-reads-leads', ['forms.view']);
    });
    app(Tenancy::class)->enter(2, fn () => grantRole($theirs, 'lfm-their-leads', ['forms.view', 'forms.manage', 'entries.view']));
    app(TeamContext::class)->forget();
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');

    try {
        // as before the deploy: the permission does not exist yet
        $migration->down();
        expect($db->table('permissions')->where('name', 'forms.files')->exists())->toBeFalse();
        $managers = $holders('forms.manage');
        $readers = $db->table('roles')->where('name', 'lfm-reads-leads')->pluck('id')->all();
        $ours = $db->table('roles')->whereIn('name', ['lfm-handles-leads', 'lfm-their-leads'])->orderBy('id')->pluck('id')->all();
        expect($ours)->toHaveCount(2)->and(array_values(array_intersect($managers, $ours)))->toBe($ours)
            ->and($readers)->toHaveCount(1)->and($managers)->not->toContain($readers[0]);

        $migration->up();
        $migration->up(); // twice is harmless
        expect($holders('forms.files'))->toBe($managers)
            ->and(array_intersect($holders('forms.files'), $readers))->toBe([]);

        $migration->down();
        expect($db->table('permissions')->where('name', 'forms.files')->exists())->toBeFalse()
            ->and($holders('forms.manage'))->toBe($managers);
    } finally {
        $migration->up();
        DB::setDefaultConnection($previous);
    }
});

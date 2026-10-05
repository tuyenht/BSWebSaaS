<?php

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

/*
 * P0.7 — audit trail (design.md §29). Records sensitive actions with a ulid + action
 * taxonomy + context, and is BEST-EFFORT: a failure must never bubble to the caller — except a
 * concurrency error, which has aborted the caller's transaction and is the caller's to handle.
 */

beforeEach(fn () => DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade'));

it('records an audit entry with action + context', function (): void {
    Audit::record('rbac.role.granted', ['site_id' => null, 'meta' => ['role' => 'super_admin']]);

    $log = AuditLog::sole();

    expect($log->action)->toBe('rbac.role.granted')
        ->and($log->ulid)->toBeString()->toHaveLength(26)
        ->and($log->meta)->toBe(['role' => 'super_admin']);
});

it('never throws and writes nothing when the entry is invalid (best-effort)', function (): void {
    // action exceeds varchar(255) → DB error → swallowed (no exception bubbles here),
    // and nothing is persisted.
    Audit::record(str_repeat('x', 300));

    expect(AuditLog::count())->toBe(0);
});

it('leaves the request transaction usable when an entry cannot be written', function (): void {
    // Every tenant request runs inside one transaction (Tenancy::enter). On PostgreSQL a failed
    // statement aborts the whole transaction: a swallowed audit error must not silently roll back
    // the request's own writes at COMMIT, nor turn its next query into an error.
    seedTenancy();

    $answer = app(Tenancy::class)->enter(1, function (): int {
        DB::table('tenant_probes')->insert(['site_id' => 1, 'value' => 'saved-by-the-request', 'created_at' => now(), 'updated_at' => now()]);
        Audit::record(str_repeat('x', 300));

        return (int) DB::scalar('select 1');
    });

    expect($answer)->toBe(1)
        ->and(DB::connection('pgsql_admin')->table('tenant_probes')->where('value', 'saved-by-the-request')->count())->toBe(1);
});

it('reports a failed entry and lets the request carry on', function (): void {
    Exceptions::fake();
    seedTenancy();

    $answer = app(Tenancy::class)->enter(1, function (): int {
        Audit::record(str_repeat('x', 300));

        return (int) DB::scalar('select 1');
    });

    expect($answer)->toBe(1);
    Exceptions::assertReportedCount(1);
});

it('hands a concurrency error back to the caller instead of hiding an aborted transaction', function (): void {
    // Laravel rethrows a lock conflict out of the audit savepoint without rolling back to it:
    // the request transaction is aborted, so swallowing it would let the caller go on as if it
    // succeeded while its COMMIT rolls everything back. A trigger raises the real SQLSTATE.
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    $admin->unprepared(<<<'SQL'
        create or replace function test_audit_lock_conflict() returns trigger language plpgsql as $fn$
        begin
            raise exception 'could not serialize access due to concurrent update' using errcode = '40001';
        end
        $fn$;
        drop trigger if exists test_audit_lock_conflict on audit_logs;
        create trigger test_audit_lock_conflict before insert on audit_logs
            for each row when (new.action = 'test.audit.conflict')
            execute function test_audit_lock_conflict();
        SQL);

    try {
        expect(fn () => app(Tenancy::class)->enter(1, function (): void {
            DB::table('tenant_probes')->insert(['site_id' => 1, 'value' => 'lost-with-the-request', 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('test.audit.conflict');
        }))->toThrow(DeadlockException::class);
    } finally {
        $admin->unprepared('drop trigger if exists test_audit_lock_conflict on audit_logs; drop function if exists test_audit_lock_conflict();');
    }

    expect(DB::connection('pgsql_admin')->table('tenant_probes')->where('value', 'lost-with-the-request')->count())->toBe(0)
        ->and(AuditLog::count())->toBe(0);
});

it('drops a request id that is not a plain token instead of losing the entry', function (string $sent, ?string $stored): void {
    // X-Request-Id comes from the client: an oversized or odd value is not ours to keep.
    request()->headers->set('X-Request-Id', $sent);

    Audit::record('auth.login.succeeded');

    expect(AuditLog::sole()->getAttribute('request_id'))->toBe($stored);
})->with([
    'a plain id is kept' => ['9f1c2d3e-4b5a-6789-abcd-ef0123456789', '9f1c2d3e-4b5a-6789-abcd-ef0123456789'],
    'too long' => [str_repeat('a', 300), null],
    'markup' => ['<script>alert(1)</script>', null],
]);

it('adds and removes the impersonator columns reversibly', function (): void {
    /** @var Migration $migration */
    $migration = require database_path('migrations/2028_01_01_000000_add_impersonator_to_audit_logs.php');
    $columns = ['impersonator_id', 'impersonator_label'];

    // Migrations run on the privileged connection (DDL needs table ownership). Restore the columns
    // before asserting so a failing assertion never leaves them dropped for the rest of the suite.
    $original = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_admin');
    try {
        $migration->down();
        $dropped = ! Schema::hasColumns('audit_logs', $columns);
        $migration->up();
        $migration->up();   // re-runnable after a mid-run failure
    } finally {
        DB::setDefaultConnection($original);
    }

    Audit::record('cms.entry.updated');

    expect($dropped)->toBeTrue()
        ->and(Schema::hasColumns('audit_logs', $columns))->toBeTrue()
        ->and(DB::table('audit_logs')->select($columns)->sole())
        ->toEqual((object) ['impersonator_id' => null, 'impersonator_label' => null]);
});

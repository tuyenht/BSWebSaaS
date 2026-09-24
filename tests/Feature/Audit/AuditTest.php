<?php

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * P0.7 — audit trail (design.md §29). Records sensitive actions with a ulid + action
 * taxonomy + context, and is BEST-EFFORT: a failure must never bubble to the caller.
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

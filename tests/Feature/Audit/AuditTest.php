<?php

use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/*
 * P0.7 — audit trail (design.md §29). Records sensitive actions with a ulid + action
 * taxonomy + context, and is BEST-EFFORT: a failure must never bubble to the caller.
 */

beforeEach(fn () => DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade'));

it('records an audit entry with action + context', function () {
    Audit::record('rbac.role.granted', ['site_id' => null, 'meta' => ['role' => 'super_admin']]);

    $log = AuditLog::sole();

    expect($log->action)->toBe('rbac.role.granted')
        ->and($log->ulid)->toBeString()->toHaveLength(26)
        ->and($log->meta)->toBe(['role' => 'super_admin']);
});

it('never throws and writes nothing when the entry is invalid (best-effort)', function () {
    // action exceeds varchar(255) → DB error → swallowed (no exception bubbles here),
    // and nothing is persisted.
    Audit::record(str_repeat('x', 300));

    expect(AuditLog::count())->toBe(0);
});

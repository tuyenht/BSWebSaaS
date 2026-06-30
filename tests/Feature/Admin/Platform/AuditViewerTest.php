<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use Illuminate\Support\Facades\DB;

/*
 * S4 — operator Audit viewer (read-only). `audit_logs` is GLOBAL (no RLS; nullable site_id for
 * context) so operators get cross-site visibility directly. Gated by platform.audit.view
 * (super_admin/support, NOT billing). Newest first; filterable by action.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
});

function auditOperator(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

it('lists audit entries for an operator', function () {
    AuditLog::query()->create([
        'action' => 'platform.entitlement.toggle',
        'actor_label' => 'admin@baoson.net',
        'site_id' => 1,
        'meta' => [],
    ]);
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(auditOperator('support'))->get("http://{$host}/audit")
        ->assertOk()
        ->assertSee('platform.entitlement.toggle')
        ->assertSee('admin@baoson.net');
});

it('filters audit entries by action', function () {
    AuditLog::query()->create(['action' => 'rbac.role.assign', 'actor_label' => 'x', 'meta' => []]);
    AuditLog::query()->create(['action' => 'site.provision', 'actor_label' => 'y', 'meta' => []]);
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(auditOperator('super_admin'))->get("http://{$host}/audit?action=rbac")
        ->assertOk()
        ->assertSee('rbac.role.assign')
        ->assertDontSee('site.provision');
});

it('forbids billing (no audit.view) from the audit log', function () {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(auditOperator('billing'))->get("http://{$host}/audit")->assertForbidden();
});

<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/*
 * S1 — operator Tenant list + detail (read-only). Tenants is a GLOBAL table (no RLS), so the
 * operator queries it directly on the platform plane (no tenant context). Addressed by ulid
 * (D2 — never the raw bigint id). Gated by platform.tenants.view (super_admin/support/billing);
 * a tenant user is forbidden. The list count of sites must not N+1.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // tenant T1 (id 1) + sites A(1)/B(2) + domains a.test/b.test
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
});

function actingAsOperator(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

it('lists tenants for an operator, linked by ulid', function (): void {
    $host = (string) config('tenancy.platform_host');
    $user = actingAsOperator('support');
    $tenant = Tenant::query()->firstOrFail();

    $this->actingAs($user)->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('T1')
        ->assertSee(route('platform.tenants.show', $tenant->ulid));
});

it('filters the tenant list by search', function (): void {
    $host = (string) config('tenancy.platform_host');
    DB::connection('pgsql_admin')->table('tenants')->insert([
        'ulid' => 'TENANT0000000000000000002', 'name' => 'Acme Corp', 'status' => 'active',
        'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = actingAsOperator('super_admin');

    $this->actingAs($user)->get("http://{$host}/tenants?q=Acme")
        ->assertOk()
        ->assertSee('Acme Corp')
        // By T1's detail link, not its two-letter name: "T1" can turn up anywhere in the page (a token, a hash).
        ->assertSee(route('platform.tenants.show', 'TENANT0000000000000000002'))
        ->assertDontSee(route('platform.tenants.show', 'TENANT0000000000000000001'));
});

it('shows a tenant detail with its sites and primary domain', function (): void {
    $host = (string) config('tenancy.platform_host');
    $user = actingAsOperator('super_admin');
    $tenant = Tenant::query()->firstOrFail();

    // The shared seedTenancy() stores a 25-char ulid in a char(26) column → Postgres pads a
    // trailing space; trim it for the request URI. Real Str::ulid() values are 26 chars, so
    // this only bites the seed, not production. CHAR comparison ignores the trailing pad.
    $this->actingAs($user)->get('http://'.$host.'/tenants/'.rtrim((string) $tenant->ulid))
        ->assertOk()
        ->assertSee('T1')
        ->assertSee('a.test'); // site A's primary domain
});

it('forbids a tenant user on the operator tenants page', function (): void {
    $host = (string) config('tenancy.platform_host');
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($user)->get("http://{$host}/tenants")->assertForbidden();
});

it('lists tenants without an N+1 on the site count', function (): void {
    DB::connection('pgsql_admin')->table('tenants')->insert([
        ['ulid' => 'TENANT0000000000000000002', 'name' => 'C2', 'status' => 'active', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()],
        ['ulid' => 'TENANT0000000000000000003', 'name' => 'C3', 'status' => 'active', 'settings' => '{}', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Mirrors the Index component query: withCount folds the site count into the select, so
    // the query count is constant (paginator count + select) regardless of how many tenants.
    $queries = countQueries(function (): void {
        Tenant::query()->withCount('sites')->orderBy('id')->paginate(20);
    });

    expect($queries)->toBeLessThanOrEqual(3);
});

<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Support\Facades\DB;

/*
 * S2 — operator Site list + detail (read-only). `sites`/`domains` are GLOBAL (no RLS) → queried
 * directly on the platform plane. Gated by platform.sites.view (super_admin/support, NOT billing);
 * addressed by ulid. The list eager-loads domain/tenant/site_type (no N+1).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites A(1)/B(2) + domains a.test/b.test
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
});

function siteOperator(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

it('lists sites for an operator with their primary domain', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(siteOperator('support'))->get("http://{$host}/sites")
        ->assertOk()
        ->assertSee('a.test')
        ->assertSee('b.test');
});

it('filters sites by status', function (): void {
    $host = (string) config('tenancy.platform_host');
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'archived']);

    $this->actingAs(siteOperator('super_admin'))->get("http://{$host}/sites?status=archived")
        ->assertOk()
        ->assertSee('b.test')
        ->assertDontSee('a.test');
});

it('shows a site detail by ulid with its domains', function (): void {
    $host = (string) config('tenancy.platform_host');
    $site = Site::query()->firstOrFail();

    // char(26) ulid column pads a sub-26-char seed value with a trailing space; trim for the URI.
    $this->actingAs(siteOperator('super_admin'))->get('http://'.$host.'/sites/'.rtrim((string) $site->ulid))
        ->assertOk()
        ->assertSee('a.test')
        // The default-locale column was dropped (it now lives in site_locales.is_default), so the
        // god-view detail no longer carries a dead, always-empty "Default language" field.
        ->assertDontSee(__('admin.default_locale'));
});

it('forbids billing (no sites.view) from the sites page', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(siteOperator('billing'))->get("http://{$host}/sites")->assertForbidden();
});

it('lists sites without an N+1 on domain/tenant/site_type', function (): void {
    // Mirrors the Index query: eager loads fold to one query per relation, constant in row count.
    $queries = countQueries(function (): void {
        Site::query()->with(['domains', 'tenant', 'siteType'])->orderBy('id')->paginate(20);
    });

    expect($queries)->toBeLessThanOrEqual(6);
});

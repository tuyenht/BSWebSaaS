<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Query Budget (CLAUDE.md §6) for the admin list screens. The storefront hot routes are already
 * budgeted (QueryBudgetTest, StorefrontRenderTest); the two admin lists were not. Each seeds
 * several rows and asserts a flat ceiling, so a dropped eager-load (N+1 across the members /
 * entries loop) scales the query count past the budget and fails CI.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lists admin entries on a flat query budget (no N+1 across rows)', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view']);

        // Several page entries so an N+1 over rows / their translations would blow the ceiling.
        foreach (range(1, 10) as $i) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
            $entry->translations()->create([
                'locale' => 'vi', 'title' => "Page {$i}", 'slug' => "page-{$i}", 'status' => 'draft', 'body' => ['blocks' => []],
            ]);
        }
    });

    $count = countQueries(fn () => $this->actingAs($editor)->get('http://a.test/admin/content')->assertOk());

    // Flat ceiling: nav/entitlement (P2) + ONE single-row admin_theme read on the unique
    // (site_id, group_name, key) index (P5 chrome resolver). An N+1 scales with the 10
    // seeded rows, so it still blows well past this.
    expect($count)->toBeLessThanOrEqual(19);
});

it('lists admin members on a flat query budget (no N+1 across rows)', function (): void {
    $admin = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        grantRole($admin, 'admin', ['users.manage', 'entries.view']);

        // Several members each with a role so an N+1 over their roles would blow the ceiling.
        foreach (range(1, 10) as $i) {
            grantRole(User::factory()->create(), 'editor', ['entries.view']);
        }
    });

    $count = countQueries(fn () => $this->actingAs($admin)->get('http://a.test/admin/users')->assertOk());

    // Same flat ceiling as the entries list: +1 admin_theme single-row read (P5).
    expect($count)->toBeLessThanOrEqual(19);
});

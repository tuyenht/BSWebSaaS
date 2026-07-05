<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Policies\CatalogPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Catalog P0 foundation (design.md §11): the Product model is site-scoped (BelongsToSite → RLS,
 * auto-verified structurally by RlsCoverageTest) and every action routes through CatalogPolicy —
 * coarse catalog.view / catalog.manage, shipped with the resource at birth so no write surface is
 * ever ungated.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('creates a product scoped to the current site with an auto-filled ulid', function (): void {
    $product = app(Tenancy::class)->enter(1, fn (): Product => Product::create(['status' => 'published']));

    expect($product->site_id)->toBe(1)
        ->and($product->ulid)->not->toBeEmpty()
        ->and(app(Tenancy::class)->enter(1, fn (): int => Product::count()))->toBe(1);
});

it('gates the catalog: view needs catalog.view, writes need catalog.manage', function (): void {
    $viewer = User::factory()->create();
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($viewer, $manager): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
    });

    $policy = new CatalogPolicy;
    app(Tenancy::class)->enter(1, function () use ($policy, $viewer, $manager): void {
        expect($policy->viewAny($viewer->fresh()))->toBeTrue()   // view-only can look
            ->and($policy->create($viewer->fresh()))->toBeFalse() // …but not write
            ->and($policy->update($manager->fresh()))->toBeTrue() // manager can write
            ->and($policy->delete($manager->fresh()))->toBeTrue();
    });
});

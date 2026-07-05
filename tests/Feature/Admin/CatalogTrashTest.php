<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * P1 slice-2c — the catalog Trash (D4b): soft-delete keeps the product restorable and its
 * sku/slug reserved; force-delete cascades translations/images and frees them. Every mutation
 * re-authorizes through CatalogPolicy inside the component (the livewire/update endpoint
 * bypasses route middleware), so a catalog.view-only member can look but never delete.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Seed a product (+vi translation) in the CURRENT site context. */
function seedTrashProduct(string $name = 'Đồ gá', string $slug = 'do-ga', ?string $sku = null): Product
{
    $product = Product::query()->create(['status' => 'draft', 'sku' => $sku]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $product;
}

it('trashes a product out of the main list and restores it from the trash view', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $product = seedTrashProduct();

        Livewire::actingAs($manager->fresh())
            ->test(Index::class)
            ->assertSee('Đồ gá')
            ->call('trash', $product->ulid)
            ->assertDontSee('Đồ gá'); // gone from the main list re-render

        expect(Product::withTrashed()->find($product->id)?->trashed())->toBeTrue()
            ->and(Product::query()->count())->toBe(0); // scope hides it (D4b)

        // The trash view lists it; restore brings it back.
        Livewire::actingAs($manager->fresh())
            ->test(Index::class)
            ->set('showTrash', true)
            ->assertSee('Đồ gá')
            ->call('restore', $product->ulid)
            ->assertDontSee('Đồ gá');

        expect(Product::query()->count())->toBe(1);
    });
});

it('force-deletes a trashed product, cascading its translations and gallery rows', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $product = seedTrashProduct();

        $media = Media::query()->create([
            'collection' => 'library', 'slug' => 'anh', 'hash' => str_repeat('a', 64),
            'storage_key' => 's/library/anh.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
        ]);
        $product->images()->create(['media_id' => $media->id, 'position' => 0]);
        $product->delete();

        Livewire::actingAs($manager->fresh())
            ->test(Index::class)
            ->set('showTrash', true)
            ->call('forceDelete', $product->ulid);

        expect(Product::withTrashed()->count())->toBe(0)
            ->and(DB::table('product_translations')->count())->toBe(0) // FK cascade
            ->and(DB::table('product_images')->count())->toBe(0)       // FK cascade
            ->and(Media::query()->count())->toBe(1);                   // the media itself stays in the library
    });
});

it('refuses trash, restore and force-delete for view-only members (component-level gate)', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $live = seedTrashProduct('Sản phẩm A', 'sp-a');
        $trashed = seedTrashProduct('Sản phẩm B', 'sp-b');
        $trashed->delete();

        Livewire::actingAs($viewer->fresh())->test(Index::class)->call('trash', $live->ulid)->assertForbidden();
        Livewire::actingAs($viewer->fresh())->test(Index::class)->call('restore', $trashed->ulid)->assertForbidden();
        Livewire::actingAs($viewer->fresh())->test(Index::class)->call('forceDelete', $trashed->ulid)->assertForbidden();

        expect(Product::query()->count())->toBe(1)
            ->and(Product::withTrashed()->count())->toBe(2); // nothing changed
    });
});

it('keeps the sku reserved while trashed and frees it on force-delete', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $trashed = seedTrashProduct('Đồ gá cũ', 'do-ga-cu', 'DG-001');
        $trashed->delete();

        // While in the Trash, the sku is still occupied (matches the partial unique index) —
        // a new product using it gets the friendly validation error, not a raw 500.
        Livewire::actingAs($manager->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá mới')
            ->set('slug', 'do-ga-moi')
            ->set('sku', 'DG-001')
            ->call('save')
            ->assertHasErrors('sku');

        // Force-delete frees the sku; the same save now passes.
        Livewire::actingAs($manager->fresh())
            ->test(Index::class)
            ->call('forceDelete', $trashed->ulid);

        Livewire::actingAs($manager->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá mới')
            ->set('slug', 'do-ga-moi')
            ->set('sku', 'DG-001')
            ->call('save')
            ->assertHasNoErrors();

        expect(Product::query()->value('sku'))->toBe('DG-001');
    });
});

it('opens the trash view from the query string (200, trashed rows visible)', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        seedTrashProduct('Đồ gá sống', 'sp-song');
        seedTrashProduct('Đồ gá rác', 'sp-rac')->delete();
    });

    $this->actingAs($manager->fresh())
        ->get('http://a.test/admin/catalog?trash=1')
        ->assertOk()
        ->assertSee('Đồ gá rác')
        ->assertDontSee('Đồ gá sống');
});

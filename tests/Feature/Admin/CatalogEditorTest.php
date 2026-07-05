<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * P1 slice-2a — the catalog product editor: a structured form (no block-engine) persisting the
 * product row + its default-locale translation + the product_cat pivot, every row stamped with
 * the current site_id (§2). Create/edit are gated by catalog.manage at the route AND by
 * CatalogPolicy in mount (the livewire/update endpoint bypasses route middleware). Uniqueness
 * (slug per locale, sku per site) is validated app-side so the partial UNIQUE(site_id, sku)
 * index never surfaces as a raw 500.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Seed a localized product_cat category in the CURRENT site context; returns its id. */
function seedProductCategory(string $slug = 'do-ga', string $name = 'Đồ gá'): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category->id;
}

it('gates the create and edit routes — 200 with catalog.manage, 403 with view only', function (): void {
    $manager = User::factory()->create();
    $viewer = User::factory()->create();

    $ulid = app(Tenancy::class)->enter(1, function () use ($manager, $viewer): string {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        return Product::query()->create(['status' => 'draft'])->ulid;
    });

    // 200s also prove the component is registered with Livewire (an unregistered
    // admin component renders as a 500 "Unable to find component").
    $this->actingAs($manager->fresh())->get('http://a.test/admin/catalog/create')->assertOk();
    $this->actingAs($manager->fresh())->get("http://a.test/admin/catalog/{$ulid}/edit")->assertOk();
    $this->actingAs($viewer->fresh())->get('http://a.test/admin/catalog/create')->assertForbidden();
    $this->actingAs($viewer->fresh())->get("http://a.test/admin/catalog/{$ulid}/edit")->assertForbidden();
});

it('refuses non-managers at the component itself (livewire/update bypasses route middleware)', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        Livewire::actingAs($viewer->fresh())->test(Editor::class)->assertForbidden();
    });
});

it('creates a product with its translation and category pivot, all stamped with the site id', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $categoryId = seedProductCategory();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá hàn khung')
            ->set('slug', 'do-ga-han-khung')
            ->set('description', '<p>Thép C45, dung sai ±0.01mm</p>')
            ->set('categoryId', $categoryId)
            ->set('price', '25000000')
            ->set('sku', 'DG-001')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->first();
        expect($product)->not->toBeNull()
            ->and($product->site_id)->toBe(1)
            ->and($product->status)->toBe('published')
            ->and($product->sku)->toBe('DG-001')
            ->and($product->price)->toBe(25000000);

        $translation = $product->translationFor('vi');
        expect($translation)->not->toBeNull()
            ->and($translation?->name)->toBe('Đồ gá hàn khung')
            ->and($translation?->slug)->toBe('do-ga-han-khung')
            ->and($translation?->description)->toBe('<p>Thép C45, dung sai ±0.01mm</p>')
            ->and($translation?->site_id)->toBe(1);

        $pivot = DB::table('product_taxonomies')->where('product_id', $product->id)->first();
        expect($pivot)->not->toBeNull()
            ->and((int) $pivot->site_id)->toBe(1)
            ->and((int) $pivot->taxonomy_id)->toBe($categoryId);
    });
});

it('loads an existing product into the form and updates it in place', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        $product = Product::query()->create(['status' => 'draft', 'sku' => 'DG-001', 'price' => 1000000]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga', 'description' => 'Cũ']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('name', 'Đồ gá')
            ->assertSet('slug', 'do-ga')
            ->assertSet('price', '1000000')
            ->assertSet('sku', 'DG-001')
            ->set('name', 'Đồ gá CNC')
            ->call('save')
            ->assertHasNoErrors();

        expect(Product::query()->count())->toBe(1)             // updated in place, no new product
            ->and($product->translations()->count())->toBe(1)  // updateOrCreate, no duplicate row
            ->and($product->translationFor('vi')?->name)->toBe('Đồ gá CNC');
    });
});

it('rejects a duplicate slug and a duplicate sku with friendly errors', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        $existing = Product::query()->create(['status' => 'draft', 'sku' => 'DG-001']);
        $existing->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm khác')
            ->set('slug', 'do-ga')     // taken by $existing
            ->set('sku', 'DG-001')     // taken by $existing
            ->call('save')
            ->assertHasErrors(['slug', 'sku']);

        expect(Product::query()->count())->toBe(1); // nothing new persisted

        // Saving the existing product with its own slug/sku is not a self-collision.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $existing->ulid])
            ->call('save')
            ->assertHasNoErrors();
    });
});

it('cannot attach a category from another site (RLS-scoped exists rule)', function (): void {
    $editor = User::factory()->create();
    $foreignCategory = app(Tenancy::class)->enter(2, fn (): int => seedProductCategory('cat-b', 'Cat B'));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreignCategory): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm')
            ->set('slug', 'san-pham')
            ->set('categoryId', $foreignCategory) // lives in site 2 — invisible under RLS
            ->call('save')
            ->assertHasErrors('categoryId');

        expect(Product::query()->count())->toBe(0);
    });
});

it('stores cleared price and sku as NULL — brochure rows share the partial sku index', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        // TWO skuless products: if '' were stored instead of NULL, the second save would
        // violate the partial UNIQUE(site_id, sku) index and blow up as a QueryException.
        foreach ([['SP một', 'sp-mot'], ['SP hai', 'sp-hai']] as [$name, $slug]) {
            Livewire::actingAs($editor->fresh())
                ->test(Editor::class)
                ->set('name', $name)
                ->set('slug', $slug)
                ->set('price', '')  // cleared number input arrives as ''
                ->set('sku', '')
                ->call('save')
                ->assertHasNoErrors();
        }

        expect(Product::query()->count())->toBe(2)
            ->and(Product::query()->whereNull('sku')->count())->toBe(2)
            ->and(Product::query()->whereNull('price')->count())->toBe(2);
    });
});

<?php

use App\Modules\Catalog\Actions\ChangeProductMainCategory;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Actions\ForceDeleteProducts;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * Every catalog action asks CatalogPolicy itself before it writes, so a product cannot be renamed,
 * readdressed, published, moved to another category or deleted for good by someone who may only look
 * at the products, whichever screen or code path called it. A member who manages the catalog passes,
 * and with nobody signed in (the console, the nightly Trash purge) the actions run as before.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function gateProduct(string $status = 'draft'): Product
{
    $product = Product::query()->create(['status' => $status]);
    $product->translations()->create(['locale' => 'vi', 'name' => 'Đĩa phay mặt', 'slug' => 'dia-phay-mat']);

    return $product;
}

function gateCategory(string $name): Taxonomy
{
    return app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => $name]));
}

/** @param list<string> $permissions */
function gateMember(array $permissions): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-member-'.count($permissions), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh();
}

/**
 * Each write of each catalog action, as a call on a product of site 1 (and a category, for the move).
 *
 * @return array<string, Closure(Product, Taxonomy): mixed>
 */
function gateWrites(): array
{
    return [
        'rename' => fn (Product $p, Taxonomy $c): mixed => app(RenameProduct::class)->handle($p, 'Tên mới', 'vi'),
        'change slug' => fn (Product $p, Taxonomy $c): mixed => app(ChangeProductSlug::class)->handle($p, 'dia-moi', 'vi'),
        'change status' => fn (Product $p, Taxonomy $c): mixed => app(ChangeProductStatus::class)->handle($p, 'published'),
        'publish effects' => fn (Product $p, Taxonomy $c): mixed => app(ChangeProductStatus::class)->publishEffects($p),
        'change main category' => fn (Product $p, Taxonomy $c): mixed => app(ChangeProductMainCategory::class)
            ->handle(Product::query()->whereKey($p->id)->with(['taxonomies', 'translations'])->get(), $c),
        'file under a category' => fn (Product $p, Taxonomy $c): mixed => app(ChangeProductMainCategory::class)->file($p, [$c->id], $c, []),
        'mark moved' => fn (Product $p, Taxonomy $c): mixed => app(ChangeProductMainCategory::class)->markMoved([$p->id]),
        'delete for good' => fn (Product $p, Taxonomy $c): mixed => app(ForceDeleteProducts::class)->handle([$p]),
    ];
}

/**
 * What the checks compare before and after: the product row, its text and change mark, its categories and routes.
 *
 * @return array<string, mixed>
 */
function gateSnapshot(int $productId): array
{
    return [
        'product' => DB::table('products')->where('id', $productId)->first(['status', 'published_at', 'deleted_at', 'updated_at']),
        'translation' => DB::table('product_translations')->where('product_id', $productId)->first(['name', 'slug', 'content_changed_at']),
        'categories' => DB::table('product_taxonomies')->where('product_id', $productId)->count(),
        'routes' => DB::table('routes')->where('product_id', $productId)->count(),
    ];
}

it('refuses every catalog write to a member who may only view the products, and changes nothing', function (string $write): void {
    $viewer = gateMember(['catalog.view']);

    app(Tenancy::class)->enter(1, function () use ($viewer, $write): void {
        $category = gateCategory('Đồ gá');
        $product = gateProduct();
        if ($write === 'delete for good') {
            $product->delete();
        }
        $before = gateSnapshot($product->id);

        $this->actingAs($viewer);
        expect(fn () => gateWrites()[$write]($product->fresh() ?? Product::withTrashed()->findOrFail($product->id), $category))
            ->toThrow(AuthorizationException::class);
        expect(gateSnapshot($product->id))->toEqual($before);
    });
})->with(array_keys(gateWrites()));

it('lets a member who manages the catalog make every catalog write', function (string $write): void {
    $manager = gateMember(['catalog.view', 'catalog.manage']);

    app(Tenancy::class)->enter(1, function () use ($manager, $write): void {
        $category = gateCategory('Đồ gá');
        $product = gateProduct();
        if ($write === 'delete for good') {
            $product->delete();
        }

        $before = gateSnapshot($product->id);
        $this->travel(1)->minutes();

        $this->actingAs($manager);
        gateWrites()[$write]($product->fresh() ?? Product::withTrashed()->findOrFail($product->id), $category);
        expect(gateSnapshot($product->id))->not->toEqual($before);
    });
})->with(array_keys(gateWrites()));

it('runs every catalog write with nobody signed in, as the console and the nightly purge do', function (string $write): void {
    app(Tenancy::class)->enter(1, function () use ($write): void {
        $category = gateCategory('Đồ gá');
        $product = gateProduct();
        if ($write === 'delete for good') {
            $product->delete();
        }

        $before = gateSnapshot($product->id);
        $this->travel(1)->minutes();

        gateWrites()[$write]($product->fresh() ?? Product::withTrashed()->findOrFail($product->id), $category);
        expect(gateSnapshot($product->id))->not->toEqual($before);
    });
})->with(array_keys(gateWrites()));

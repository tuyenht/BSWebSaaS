<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Actions\ChangeProductMainCategory;
use App\Modules\Catalog\Actions\ForceDeleteProducts;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A member limited to some product categories sees and changes only the products whose main
 * category is inside those limits — in the products list (every row write, every bulk action, the
 * undo), the product editor (open, save, the category picked), the preview and the catalog actions
 * themselves — and files a product only under such a category, never under none. A product with no
 * category is outside every limit. A member not limited on products is unchanged.
 */

function cpsCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    return app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('product_cat', $parent, new TaxonomyData(names: ['vi' => $name])));
}

function cpsProduct(string $name, string $slug, ?Taxonomy $category, string $status = 'draft'): Product
{
    return app(Tenancy::class)->enter(1, function () use ($name, $slug, $category, $status): Product {
        $product = Product::query()->create(['status' => $status]);
        $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
        if ($category !== null) {
            $product->assignCategories([$category->id]);
        }

        return $product->fresh() ?? $product;
    });
}

/** A catalog manager (limitable level); limited to $categories when any are given. */
function cpsManager(Taxonomy ...$categories): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));
    foreach ($categories as $category) {
        DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $user->id, 'taxonomy_id' => $category->id]);
    }
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

function cpsIn(Closure $callback): mixed
{
    app(CategoryScopes::class)->forget();

    return app(Tenancy::class)->enter(1, $callback);
}

/** The product as stored (any state), read inside the test's site context — the writes there are not committed yet. */
function cpsRow(Product $product): ?object
{
    return DB::table('products')->where('site_id', 1)->where('id', $product->id)->first();
}

function cpsMain(Product $product): ?int
{
    $id = DB::table('product_taxonomies')->where('site_id', 1)->where('product_id', $product->id)->where('position', 0)->value('taxonomy_id');

    return $id === null ? null : (int) $id;
}

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->moulds = cpsCategory('Khuôn');
    $this->small = cpsCategory('Khuôn nhỏ', $this->moulds);
    $this->jigs = cpsCategory('Đồ gá');
    $this->inside = cpsProduct('Khuôn ép nhựa', 'khuon-ep-nhua', $this->small);
    $this->outside = cpsProduct('Đồ gá hàn', 'do-ga-han', $this->jigs);
    $this->loose = cpsProduct('Chưa phân loại', 'chua-phan-loai', null);
    $this->lead = cpsManager($this->moulds);
});

it('lists only the products inside the limits, and every product for a member not limited on products', function (): void {
    $free = cpsManager();
    cpsIn(function () use ($free): void {
        $html = Livewire::actingAs($this->lead)->test(Index::class)->html();
        expect($html)->toContain('Khuôn ép nhựa')->not->toContain('Đồ gá hàn')->not->toContain('Chưa phân loại');

        $all = Livewire::actingAs($free)->test(Index::class)->html();
        expect($all)->toContain('Khuôn ép nhựa')->toContain('Đồ gá hàn')->toContain('Chưa phân loại');
    });
});

it('changes nothing outside the limits from a row of the list, as if the product were gone', function (): void {
    cpsIn(function (): void {
        $test = Livewire::actingAs($this->lead)->test(Index::class);
        $test->call('trash', $this->outside->ulid)->assertSet('toastMsg', __('catalog.row_gone'));
        $test->call('trash', $this->loose->ulid);
        $test->call('toggleFlag', $this->outside->ulid, 'featured', true);
        $test->call('setStatus', $this->outside->ulid, 'published');
        $test->call('openCategory', $this->outside->ulid)->assertSet('catOpen', false);

        expect(cpsRow($this->outside)->deleted_at)->toBeNull()
            ->and(cpsRow($this->loose)->deleted_at)->toBeNull()
            ->and((bool) cpsRow($this->outside)->is_featured)->toBeFalse()
            ->and(cpsRow($this->outside)->status)->toBe('draft');

        $test->call('trash', $this->inside->ulid);
        expect(cpsRow($this->inside)->deleted_at)->not->toBeNull();
    });
});

it('restores and deletes for good only the products inside the limits', function (): void {
    cpsIn(function (): void {
        Product::query()->whereKey([$this->inside->id, $this->outside->id])->get()->each->delete();
        $test = Livewire::actingAs($this->lead)->test(Index::class)->call('setTab', 'trash');

        $test->call('restore', $this->outside->ulid);
        try {
            $test->call('forceDelete', $this->outside->ulid);   // found nowhere: 404
        } catch (ModelNotFoundException) {
        }
        expect(cpsRow($this->outside)->deleted_at)->not->toBeNull()
            ->and(cpsRow($this->outside))->not->toBeNull();

        Livewire::actingAs($this->lead)->test(Index::class)->call('setTab', 'trash')
            ->call('toggleSelect', [$this->inside->ulid, $this->outside->ulid])->call('bulkRestore');
        expect(cpsRow($this->inside)->deleted_at)->toBeNull()
            ->and(cpsRow($this->outside)->deleted_at)->not->toBeNull();
    });
});

it('runs a bulk action on the selected products inside the limits only', function (): void {
    cpsIn(function (): void {
        Livewire::actingAs($this->lead)->test(Index::class)
            ->call('toggleSelect', [$this->inside->ulid, $this->outside->ulid, $this->loose->ulid])
            ->call('bulkTrash');

        expect(cpsRow($this->inside)->deleted_at)->not->toBeNull()
            ->and(cpsRow($this->outside)->deleted_at)->toBeNull()
            ->and(cpsRow($this->loose)->deleted_at)->toBeNull();
    });
});

it('moves products only into a category inside the limits, and says why not', function (): void {
    cpsIn(function (): void {
        $test = Livewire::actingAs($this->lead)->test(Index::class)
            ->call('openCategory', $this->inside->ulid)->assertSet('catOpen', true)
            ->call('changeCategory', $this->jigs->ulid);

        expect(cpsMain($this->inside))->toBe($this->small->id)
            ->and($test->get('toastMsg'))->toContain('Khuôn');

        $test->call('openCategory', $this->inside->ulid)->call('changeCategory', $this->moulds->ulid);
        expect(cpsMain($this->inside))->toBe($this->moulds->id);
    });
});

it('refuses the catalog actions themselves a product or a category outside the limits', function (): void {
    cpsIn(function (): void {
        test()->actingAs($this->lead);
        $rows = Product::query()->whereKey($this->inside->id)->with(['taxonomies', 'translations'])->get();
        expect(fn () => app(ChangeProductMainCategory::class)->handle($rows, $this->jigs))->toThrow(AuthorizationException::class)
            ->and(cpsMain($this->inside))->toBe($this->small->id);

        $outside = Product::query()->whereKey($this->outside->id)->with(['taxonomies', 'translations'])->get();
        expect(fn () => app(ChangeProductMainCategory::class)->handle($outside, $this->moulds))->toThrow(AuthorizationException::class)
            ->and(cpsMain($this->outside))->toBe($this->jigs->id);

        Product::query()->whereKey([$this->inside->id, $this->outside->id])->get()->each->delete();
        expect(fn () => app(ForceDeleteProducts::class)->handle(Product::onlyTrashed()->orderBy('id')->get()))->toThrow(AuthorizationException::class)
            // all or nothing: the product inside the limits stays too
            ->and(cpsRow($this->inside))->not->toBeNull()
            ->and(cpsRow($this->outside))->not->toBeNull();
    });
});

it('opens and saves in the editor only a product inside the limits, under a category inside them', function (): void {
    cpsIn(function (): void {
        Livewire::actingAs($this->lead)->test(Editor::class, ['ulid' => $this->outside->ulid])->assertForbidden();
        Livewire::actingAs($this->lead)->test(Editor::class, ['ulid' => $this->loose->ulid])->assertForbidden();

        $edit = Livewire::actingAs($this->lead)->test(Editor::class, ['ulid' => $this->inside->ulid])->assertOk();
        expect(array_column($edit->viewData('categories'), 'id'))->toEqualCanonicalizing([$this->moulds->id, $this->small->id]);
        $edit->set('categoryId', $this->jigs->id)->call('save')->assertHasErrors(['categoryId']);
        expect(cpsMain($this->inside))->toBe($this->small->id);
        $edit->set('categoryId', null)->call('save')->assertHasErrors(['categoryId' => 'required']);
        $edit->set('categoryId', $this->moulds->id)->call('save')->assertHasNoErrors();
        expect(cpsMain($this->inside))->toBe($this->moulds->id);
    });
});

it('creates a product only under a category inside the limits', function (): void {
    $free = cpsManager();
    cpsIn(function () use ($free): void {
        $new = fn (): mixed => Livewire::actingAs($this->lead)->test(Editor::class)->set('name', 'Khuôn dập')->set('slug', 'khuon-dap');

        $new()->call('save')->assertHasErrors(['categoryId' => 'required']);
        $new()->set('categoryId', $this->jigs->id)->call('save')->assertHasErrors(['categoryId']);
        expect(DB::table('product_translations')->where('site_id', 1)->where('slug', 'khuon-dap')->exists())->toBeFalse();

        // labelled before its first save: the label is written once the product is filed inside the limits
        $new()->set('categoryId', $this->small->id)->set('featured', true)->call('save')->assertHasNoErrors();
        $id = DB::table('product_translations')->where('site_id', 1)->where('slug', 'khuon-dap')->value('product_id');
        expect((int) DB::table('product_taxonomies')->where('site_id', 1)->where('product_id', $id)->where('position', 0)->value('taxonomy_id'))->toBe($this->small->id)
            ->and(DB::table('products')->where('site_id', 1)->where('id', $id)->value('is_featured'))->toBeTrue();

        // a member not limited on products may still save one under no category
        Livewire::actingAs($free)->test(Editor::class)->set('name', 'Bàn gá')->set('slug', 'ban-ga')->call('save')->assertHasNoErrors();
    });
});

it('never names a product outside the limits whose SKU is taken', function (): void {
    cpsIn(function (): void {
        DB::table('products')->where('id', $this->outside->id)->update(['sku' => 'DG-01']);

        Livewire::actingAs($this->lead)->test(Editor::class, ['ulid' => $this->inside->ulid])
            ->set('sku', 'DG-01')
            ->assertHasErrors(['sku'])
            ->assertSee(__('catalog.sku_taken'))
            ->assertDontSee('Đồ gá hàn');
    });
});

it('previews only a product inside the limits', function (): void {
    $fresh = function (): void {
        app()->forgetScopedInstances();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        app(TeamContext::class)->forget();
    };

    $fresh();
    $this->actingAs($this->lead)->get("http://a.test/admin/catalog/{$this->inside->ulid}/preview")->assertOk();
    $fresh();
    $this->actingAs($this->lead)->get("http://a.test/admin/catalog/{$this->outside->ulid}/preview")->assertNotFound();
});

<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The products list's quick edit of a name and an address. Both go through one Action each, the same
 * the editor shares: the name is required and at most 200 characters; the address is made plain,
 * refused when a product, a trashed product, a category or a page holds it, and moves the route of a
 * live product so the old address redirects. Each write is authorized before any lookup.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function qeProduct(string $name = 'Dao phay mặt', string $slug = 'dao-phay-mat', string $status = 'draft'): Product
{
    $product = Product::query()->create(['status' => $status]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $product;
}

function qeManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));

    return $user->fresh();
}

function qeSlugOf(Product $product): string
{
    return (string) $product->translations()->where('locale', 'vi')->value('slug');
}

it('renames a product and puts the old name back on undo', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $before = $product->fresh()->updated_at;
        $this->travel(5)->minutes();

        $test = Livewire::actingAs($manager)->test(Index::class)
            ->call('saveName', $product->ulid, "  Dao phay ngón\tĐường kính 10  ")
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['name'] === 'Dao phay ngón Đường kính 10');

        expect($product->translations()->value('name'))->toBe('Dao phay ngón Đường kính 10')
            ->and(qeSlugOf($product))->toBe('dao-phay-mat')
            ->and($product->fresh()->updated_at->gt($before))->toBeTrue()
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_renamed'))
            ->and($test->get('undoOp'))->not->toBeNull();

        $test->call('undoLast');
        expect($product->translations()->value('name'))->toBe('Dao phay mặt')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_undone'));
    });
});

it('keeps the old name for an empty name and keeps what was typed for a name that is too long', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class);

        $test->call('saveName', $product->ulid, "  \t ")
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === false && $r['name'] === 'Dao phay mặt');
        expect($test->get('toastMsg'))->toBe(__('catalog.name_required'));

        $long = str_repeat('Ư', 201);
        $test->call('saveName', $product->ulid, $long)
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === true && $r['error'] === __('catalog.name_too_long', ['max' => 200]));

        $edge = str_repeat('Ư', 200);
        $test->call('saveName', $product->ulid, $edge)->assertReturned(fn (array $r): bool => $r['ok'] === true);
        expect($product->translations()->value('name'))->toBe($edge);
    });
});

it('turns a typed address into a plain Vietnamese one and says nothing changed when it is the same', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class)
            ->call('saveSlug', $product->ulid, '  Dao Phay Ngón Đường Kính 10  ')
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['slug'] === 'dao-phay-ngon-duong-kinh-10');

        expect(qeSlugOf($product))->toBe('dao-phay-ngon-duong-kinh-10')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_slug_changed', ['path' => '/dao-phay-ngon-duong-kinh-10']))
            ->and(SiteRoute::query()->where('product_id', $product->id)->exists())->toBeFalse();

        $test->call('saveSlug', $product->ulid, 'dao-phay-ngon-duong-kinh-10');
        expect($test->get('undoOp'))->toBe(['kind' => 'slug', 'ulid' => $product->ulid, 'slug' => 'dao-phay-mat']);
    });
});

it('refuses an empty address, one with no letter or digit in it, and keeps the typed text', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class);

        foreach (['', '   ', '---', '!!!'] as $typed) {
            $test->call('saveSlug', $product->ulid, $typed)
                ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === true && $r['error'] === __('catalog.slug_invalid'));
        }
        expect(qeSlugOf($product))->toBe('dao-phay-mat');
    });
});

it('refuses an address that another product or a product in the Trash already holds, naming it', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        qeProduct('Mũi khoan thép gió', 'mui-khoan');
        $gone = qeProduct('Ốc vít cũ', 'oc-vit-cu');
        $gone->delete();
        $test = Livewire::actingAs($manager)->test(Index::class);

        $test->call('saveSlug', $product->ulid, 'MUI-KHOAN')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['error'] === __('catalog.slug_owner_product', ['slug' => 'mui-khoan', 'name' => 'Mũi khoan thép gió']));
        $test->call('saveSlug', $product->ulid, 'Ốc vít cũ')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['error'] === __('catalog.slug_owner_product_trash', ['slug' => 'oc-vit-cu', 'name' => 'Ốc vít cũ']));
        expect(qeSlugOf($product))->toBe('dao-phay-mat');
    });
});

it('refuses an address that a category or a page of the website already owns', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $manager->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh(), 'vi', 'gioi-thieu');

        $category = app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => 'Dao cắt'], slugs: ['vi' => 'dao-cat']));
        expect(SiteRoute::query()->where('taxonomy_id', $category->id)->value('path'))->toBe('/dao-cat');

        $test = Livewire::actingAs($manager)->test(Index::class);
        $test->call('saveSlug', $product->ulid, 'Giới thiệu')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === true && $r['error'] === __('catalog.slug_owner_page', ['slug' => 'gioi-thieu']));
        $test->call('saveSlug', $product->ulid, 'dao-cat')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['error'] === __('catalog.slug_owner_category', ['slug' => 'dao-cat', 'name' => 'Dao cắt']));
        expect(qeSlugOf($product))->toBe('dao-phay-mat');
    });
});

it('moves a live product to its new address, redirects the old one and says so, then undoes it', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct('Dao phay mặt', 'dao-phay-mat', 'published');
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'dao-phay-mat');

        $test = Livewire::actingAs($manager)->test(Index::class)->call('saveSlug', $product->ulid, 'dao-phay-cnc');

        expect(SiteRoute::query()->where('product_id', $product->id)->value('path'))->toBe('/dao-phay-cnc')
            ->and(Redirect::query()->where('from_path', '/dao-phay-mat')->value('to_path'))->toBe('/dao-phay-cnc')
            ->and(Redirect::query()->where('from_path', '/dao-phay-mat')->value('status_code'))->toBe(301)
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_slug_changed', ['path' => '/dao-phay-cnc']).' '.__('catalog.toast_slug_redirect'));

        $test->call('undoLast');
        expect(qeSlugOf($product))->toBe('dao-phay-mat')
            ->and(SiteRoute::query()->where('product_id', $product->id)->value('path'))->toBe('/dao-phay-mat')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_undone'));
    });
});

it('saves nothing when the route turns out to be taken at the last moment', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = qeProduct('Dao phay mặt', 'dao-phay-mat', 'published');
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'dao-phay-mat');

        // the address looks free when it is checked; another request takes it before the route moves
        $other = qeProduct('Sản phẩm khác', 'san-pham-khac', 'published');
        $armed = true;
        DB::beforeExecuting(function (string $query) use (&$armed, $other): void {
            if ($armed && str_starts_with($query, 'update "product_translations"')) {
                $armed = false;
                SiteRoute::query()->create(['locale' => 'vi', 'path' => '/dao-phay-cnc', 'product_id' => $other->id]);
            }
        });

        $refused = null;
        try {
            app(ChangeProductSlug::class)->handle($product, 'dao-phay-cnc', 'vi');
        } catch (ProductEditRefused $e) {
            $refused = $e->getMessage();
        }

        expect($refused)->toBe(__('catalog.slug_conflict'))
            ->and(qeSlugOf($product))->toBe('dao-phay-mat');
    });
});

it('says the old address cannot come back when another product took it meanwhile, and when the product is gone', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class)->call('saveSlug', $product->ulid, 'dao-phay-moi');
        qeProduct('Sản phẩm khác', 'dao-phay-mat');

        $test->call('undoLast');
        expect($test->get('toastTone'))->toBe('error')
            ->and($test->get('toastMsg'))->toBe(__('catalog.undo_slug_taken'))
            ->and(qeSlugOf($product))->toBe('dao-phay-moi');

        $other = qeProduct('Đồ gá', 'do-ga');
        $test->call('saveName', $other->ulid, 'Đồ gá hàn');
        $other->delete();
        $test->call('undoLast');
        expect($test->get('toastMsg'))->toBe(__('catalog.undo_gone'))
            ->and($other->translations()->value('name'))->toBe('Đồ gá hàn');
    });
});

it('refuses a viewer, a user with no role and a guest, and never reaches another website\'s product', function (): void {
    $viewer = User::factory()->create();
    $manager = qeManager();
    $foreign = null;

    app(Tenancy::class)->enter(2, function () use (&$foreign): void {
        $foreign = qeProduct('Của site khác', 'cua-site-khac');
    });

    app(Tenancy::class)->enter(1, function () use ($viewer, $manager, $foreign): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $product = qeProduct();

        $as = fn () => Livewire::actingAs($viewer->fresh())->test(Index::class);
        $as()->call('saveName', $product->ulid, 'Tên mới')->assertForbidden();
        $as()->call('saveSlug', $product->ulid, 'ten-moi')->assertForbidden();
        expect($product->translations()->value('name'))->toBe('Dao phay mặt')->and(qeSlugOf($product))->toBe('dao-phay-mat');

        $mine = Livewire::actingAs($manager)->test(Index::class);
        $mine->call('saveName', $foreign->ulid, 'Chiếm tên');
        expect($mine->get('toastMsg'))->toBe(__('catalog.row_gone'));
        $mine->call('saveSlug', $foreign->ulid, 'chiem-duong-dan');
        expect($mine->get('toastMsg'))->toBe(__('catalog.row_gone'));

        $guest = function () use ($manager, $product) {
            $test = Livewire::actingAs($manager)->test(Index::class)->call('saveName', $product->ulid, 'Dao phay mặt');
            auth()->guard()->logout();

            return $test;
        };
        $guest()->call('saveName', $product->ulid, 'Tên mới')->assertForbidden();
        $guest()->call('saveSlug', $product->ulid, 'ten-moi')->assertForbidden();
        expect($product->translations()->value('name'))->toBe('Dao phay mặt')->and(qeSlugOf($product))->toBe('dao-phay-mat');
    });

    expect(DB::connection('pgsql_admin')->table('product_translations')->where('product_id', $foreign->id)->value('name'))->toBe('Của site khác');
    Livewire::actingAs(User::factory()->create())->test(Index::class)->assertForbidden();
});

it('refuses a product in the Trash and draws the quick edits only for a manager on a live row', function (): void {
    $manager = qeManager();
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager, $viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $live = qeProduct();
        $trashed = qeProduct('Đã xóa', 'da-xoa');
        $trashed->delete();

        $test = Livewire::actingAs($manager)->test(Index::class);
        $test->call('saveName', $trashed->ulid, 'Không được');
        expect($test->get('toastMsg'))->toBe(__('catalog.row_gone'));
        $test->call('saveSlug', $trashed->ulid, 'khong-duoc');
        expect(Product::onlyTrashed()->find($trashed->id)->translations()->value('slug'))->toBe('da-xoa');

        $html = Livewire::actingAs($manager)->test(Index::class)->html();
        expect($html)->toContain(__('catalog.name_input_label'))->toContain(__('catalog.slug_input_label'))->toContain(__('catalog.slug_edit_action'))
            ->toContain('saveName(this.ulid')->toContain(e(__('catalog.quick_edit_named', ['name' => 'Dao phay mặt'])));

        $static = Livewire::actingAs($viewer->fresh())->test(Index::class)->html();
        expect($static)->not->toContain(__('catalog.name_input_label'))->not->toContain(__('catalog.slug_edit_action'));
        expect($live->fresh())->not->toBeNull();
    });
});

it('applies the name rule and the address rule the same way outside the list', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = qeProduct();
        $rename = app(RenameProduct::class);

        expect($rename->clean("  Ổ trục\nquay "))->toBe('Ổ trục quay')
            ->and(fn () => $rename->clean(''))->toThrow(ProductEditRefused::class, __('catalog.name_required'));
        expect(app(ChangeProductSlug::class)->clean('Ổ trục Đặc biệt', 'vi'))->toBe('o-truc-dac-biet')
            ->and(app(ChangeProductSlug::class)->otherProduct('DAO-PHAY-MAT', 'vi', $product->id))->toBeNull()
            ->and(app(ChangeProductSlug::class)->otherProduct('DAO-PHAY-MAT', 'vi', null))->not->toBeNull();
    });
});

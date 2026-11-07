<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Exceptions\ProductEditRefused;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\UniqueViolation;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
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

function qeProduct(string $name = 'Đĩa phay mặt', string $slug = 'dao-phay-mat', string $status = 'draft'): Product
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
            ->call('saveName', $product->ulid, "  Đĩa phay ngón\tĐường kính mười  ")
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['name'] === 'Đĩa phay ngón Đường kính mười');

        expect($product->translations()->value('name'))->toBe('Đĩa phay ngón Đường kính mười')
            ->and(qeSlugOf($product))->toBe('dao-phay-mat')
            ->and($product->fresh()->updated_at->gt($before))->toBeTrue()
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_renamed'))
            ->and($test->get('undoOp'))->not->toBeNull();

        $test->call('undoLast');
        expect($product->translations()->value('name'))->toBe('Đĩa phay mặt')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_undone'));
    });
});

it('keeps the old name for an empty name and keeps what was typed for a name that is too long', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class);

        $test->call('saveName', $product->ulid, "  \t ")
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === false && $r['name'] === 'Đĩa phay mặt');
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
            ->call('saveSlug', $product->ulid, '  Đĩa Phay Ngón Đường Kính Mười  ')
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['slug'] === 'dia-phay-ngon-duong-kinh-muoi');

        expect(qeSlugOf($product))->toBe('dia-phay-ngon-duong-kinh-muoi')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_slug_changed', ['path' => '/dia-phay-ngon-duong-kinh-muoi']))
            ->and(SiteRoute::query()->where('product_id', $product->id)->exists())->toBeFalse();

        $test->call('saveSlug', $product->ulid, 'dia-phay-ngon-duong-kinh-muoi');
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

        $category = app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => 'Đồ cắt'], slugs: ['vi' => 'dao-cat']));
        expect(SiteRoute::query()->where('taxonomy_id', $category->id)->value('path'))->toBe('/dao-cat');

        $test = Livewire::actingAs($manager)->test(Index::class);
        $test->call('saveSlug', $product->ulid, 'Giới thiệu')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === true && $r['error'] === __('catalog.slug_owner_page', ['slug' => 'gioi-thieu']));
        $test->call('saveSlug', $product->ulid, 'dao-cat')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['error'] === __('catalog.slug_owner_category', ['slug' => 'dao-cat', 'name' => 'Đồ cắt']));
        expect(qeSlugOf($product))->toBe('dao-phay-mat');
    });
});

it('moves a live product to its new address, redirects the old one and says so, then undoes it', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct('Đĩa phay mặt', 'dao-phay-mat', 'published');
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
        $product = qeProduct('Đĩa phay mặt', 'dao-phay-mat', 'published');
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
        expect($product->translations()->value('name'))->toBe('Đĩa phay mặt')->and(qeSlugOf($product))->toBe('dao-phay-mat');

        $mine = Livewire::actingAs($manager)->test(Index::class);
        $mine->call('saveName', $foreign->ulid, 'Chiếm tên');
        expect($mine->get('toastMsg'))->toBe(__('catalog.row_gone'));
        $mine->call('saveSlug', $foreign->ulid, 'chiem-duong-dan');
        expect($mine->get('toastMsg'))->toBe(__('catalog.row_gone'));

        $guest = function () use ($manager, $product) {
            $test = Livewire::actingAs($manager)->test(Index::class)->call('saveName', $product->ulid, 'Đĩa phay mặt');
            auth()->guard()->logout();

            return $test;
        };
        $guest()->call('saveName', $product->ulid, 'Tên mới')->assertForbidden();
        $guest()->call('saveSlug', $product->ulid, 'ten-moi')->assertForbidden();
        expect($product->translations()->value('name'))->toBe('Đĩa phay mặt')->and(qeSlugOf($product))->toBe('dao-phay-mat');
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
        // the row's x-data names only the product (a changed x-data makes Alpine rebuild the row and
        // drop what is being typed); the name and the address travel in data-name / data-slug
        expect(preg_match('/<div role="row"[^>]*data-row-ulid="'.$live->ulid.'"[^>]*>/', $html, $row))->toBe(1)
            ->and($row[0])->toContain('data-name="Đĩa phay mặt"')->toContain('data-slug="dao-phay-mat"')
            ->and(preg_match('/x-data="([^"]*)"/', $row[0], $data))->toBe(1)
            ->and($data[1])->not->toContain('Đĩa phay mặt')->not->toContain('dao-phay-mat');
        expect($html)->toContain(__('catalog.name_input_label'))->toContain(__('catalog.slug_input_label'))->toContain(__('catalog.slug_edit_action'))
            ->toContain('saveName(this.ulid')->toContain(e(__('catalog.quick_edit_named', ['name' => 'Đĩa phay mặt'])));

        $static = Livewire::actingAs($viewer->fresh())->test(Index::class)->html();
        expect($static)->not->toContain(__('catalog.name_input_label'))->not->toContain(__('catalog.slug_edit_action'));
        expect(Product::onlyTrashed()->find($trashed->id)->translations()->value('name'))->toBe('Đã xóa');

        // the Trash view draws its rows without any quick edit control
        $trash = Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash')->html();
        expect($trash)->toContain('Đã xóa')
            ->not->toContain(__('catalog.name_input_label'))->not->toContain(__('catalog.slug_input_label'))->not->toContain(__('catalog.slug_edit_action'))
            ->not->toContain(e(__('catalog.quick_edit_named', ['name' => 'Đã xóa'])));
        expect($live->fresh()->translations()->value('name'))->toBe('Đĩa phay mặt');
    });
});

it('applies the name rule and the address rule the same way outside the list', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = qeProduct();
        $rename = app(RenameProduct::class);

        expect($rename->clean("  Ổ trục\nquạt "))->toBe('Ổ trục quạt')
            ->and(fn () => $rename->clean(''))->toThrow(ProductEditRefused::class, __('catalog.name_required'));
        expect(app(ChangeProductSlug::class)->clean('Ổ trục Đặc biệt', 'vi'))->toBe('o-truc-dac-biet')
            ->and(app(ChangeProductSlug::class)->otherProduct('DAO-PHAY-MAT', 'vi', $product->id))->toBeNull()
            ->and(app(ChangeProductSlug::class)->otherProduct('DAO-PHAY-MAT', 'vi', null))->not->toBeNull();
    });
});

it('refuses an address the application itself answers on, with the reserved-path words', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();

        Livewire::actingAs($manager)->test(Index::class)->call('saveSlug', $product->ulid, 'Login')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === true && $r['slug'] === 'login' && $r['error'] === __('catalog.slug_owner_page', ['slug' => 'login']));
        expect(qeSlugOf($product))->toBe('dao-phay-mat');
    });
});

it('turns a route written by another request at the last instant into the plain conflict message', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = qeProduct('Đĩa phay mặt', 'dao-phay-mat', 'published');
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'dao-phay-mat');
        $other = qeProduct('Sản phẩm khác', 'san-pham-khac', 'published');

        // the check has passed; before the route moves, another request takes the same path
        $armed = true;
        DB::beforeExecuting(function (string $query) use (&$armed, $other): void {
            if ($armed && str_starts_with($query, 'update "routes"')) {
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
            ->and(qeSlugOf($product))->toBe('dao-phay-mat')
            ->and(SiteRoute::query()->where('product_id', $product->id)->value('path'))->toBe('/dao-phay-mat');
    });
});

it('shows the cleaned address on a refusal and treats a name of control characters only as empty', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        qeProduct('Mũi khoét thép gió', 'mui-khoet');
        $test = Livewire::actingAs($manager)->test(Index::class);

        $test->call('saveSlug', $product->ulid, '  Mũi Khoét ')
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === true && $r['slug'] === 'mui-khoet');

        $test->call('saveName', $product->ulid, "\u{0001}\u{0007} ")
            ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === false && $r['name'] === 'Đĩa phay mặt');
        expect($test->get('toastMsg'))->toBe(__('catalog.name_required'));
    });
});

it('cannot undo an address change once the product went to the Trash', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class)->call('saveSlug', $product->ulid, 'dao-phay-moi');
        $product->delete();

        $test->call('undoLast');
        expect($test->get('toastMsg'))->toBe(__('catalog.undo_gone'))
            ->and(Product::onlyTrashed()->find($product->id)->translations()->value('slug'))->toBe('dao-phay-moi');
    });
});

function qeViolation(string $index): UniqueConstraintViolationException
{
    return new UniqueConstraintViolationException('pgsql', 'insert into x', [], new PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "'.$index.'"'));
}

it('maps a unique violation by the index that was violated and rethrows any other', function (): void {
    $route = UniqueViolation::refusal(qeViolation('routes_site_id_locale_path_unique'));
    $redirect = UniqueViolation::refusal(qeViolation('redirects_site_id_locale_from_path_unique'));
    $sku = UniqueViolation::refusal(qeViolation('products_site_sku_unique'));

    expect($route->getMessage())->toBe(__('catalog.slug_conflict'))->and($route->field)->toBe('slug')
        ->and($redirect->getMessage())->toBe(__('catalog.slug_conflict'))
        ->and($sku->getMessage())->toBe(__('catalog.sku_taken'))->and($sku->field)->toBe('sku');

    expect(fn () => UniqueViolation::refusal(qeViolation('users_email_unique')))->toThrow(UniqueConstraintViolationException::class);

    // the key values and the SQL carry what was typed: an index name typed there is never read
    expect(fn () => UniqueViolation::refusal(qeViolation('users_email_unique"'."\nDETAIL:  Key (path)=(products_site_sku_unique) already exists.")))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('says the address is taken when a route is written by another request while publishing from the list, and saves nothing', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct('Đĩa phay mặt', 'dao-phay-mat', 'draft');
        $other = qeProduct('Sản phẩm khác', 'san-pham-khac', 'published');

        $armed = true;
        DB::beforeExecuting(function (string $query) use (&$armed, $other): void {
            if ($armed && str_starts_with($query, 'insert into "routes"')) {
                $armed = false;
                SiteRoute::query()->create(['locale' => 'vi', 'path' => '/dao-phay-mat', 'product_id' => $other->id]);
            }
        });

        $test = Livewire::actingAs($manager)->test(Index::class)->call('setStatus', $product->ulid, 'published');

        expect($test->get('toastMsg'))->toBe(__('catalog.status_route_conflict'))
            ->and($product->fresh()->status)->toBe('draft');
    });
});

it('trims every kind of space and zero-width mark from a name and refuses a name made only of them', function (): void {
    $manager = qeManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = qeProduct();
        $test = Livewire::actingAs($manager)->test(Index::class);

        foreach (["\u{00A0}\u{00A0}", "\u{200B}\u{FEFF}\u{200D}", " \u{00A0}\u{200B} "] as $blank) {
            $test->call('saveName', $product->ulid, $blank)
                ->assertReturned(fn (array $r): bool => $r['ok'] === false && $r['keep'] === false && $r['name'] === 'Đĩa phay mặt');
            expect($test->get('toastMsg'))->toBe(__('catalog.name_required'));
        }

        $test->call('saveName', $product->ulid, "\u{00A0}\u{200B}Đĩa phay ngón\u{FEFF}\u{00A0}")
            ->assertReturned(fn (array $r): bool => $r['ok'] === true && $r['name'] === 'Đĩa phay ngón');
        expect(app(RenameProduct::class)->clean("\u{00A0}x\u{200B}"))->toBe('x');
    });
});

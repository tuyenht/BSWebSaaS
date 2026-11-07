<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The products list's row actions: the status menu, the "Nổi bật" / "Bán chạy" labels, the Trash
 * and the Restore, each with "Hoàn tác". Every write is authorized at class level before any lookup,
 * and a product is looked up live (a Trash row refuses a status or label change).
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

function rowProduct(string $name = 'Đồ gá hàn', string $slug = 'do-ga-han', string $status = 'draft'): Product
{
    $product = Product::query()->create(['status' => $status]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $product;
}

function rowManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));

    return $user->fresh();
}

it('offers each state the other two moves with their hints, and a static pill to a viewer or a Trash row', function (): void {
    $viewer = User::factory()->create();
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($viewer, $manager): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        rowProduct('Bản nháp mẫu', 'ban-nhap', 'draft');
        $trashed = rowProduct('Đồ cũ', 'do-cu', 'published');
        $trashed->delete();

        $draft = Livewire::actingAs($manager)->test(Index::class)
            ->assertSee(__('catalog.move_published'))->assertSee(__('catalog.move_published_hint'))
            ->assertSee(__('catalog.move_archived'))->assertSee(__('catalog.move_archived_hint'))
            ->assertDontSee(__('catalog.move_draft_hint'));
        expect($draft->html())->toContain('aria-haspopup="true"');

        Livewire::actingAs($viewer->fresh())->test(Index::class)
            ->assertDontSee(__('catalog.move_published_hint'))
            ->assertDontSee(__('catalog.status_menu_label'));

        Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash')
            ->assertDontSee(__('catalog.move_published_hint'))
            ->assertSee(__('catalog.restore_action'));
    });

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $live = rowProduct('Đang bán', 'dang-ban', 'published');
        $live->forceFill(['status' => 'published'])->save();
        Livewire::actingAs($manager)->test(Index::class)
            ->assertSee(__('catalog.move_draft_hint'))->assertSee(__('catalog.move_archived_hint'));
    });
});

it('publishes with the first publication date and the address, keeps the date on a second publish, and undoes it', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct('Đồ gá hàn', 'do-ga-han');

        $test = Livewire::actingAs($manager)->test(Index::class)->call('setStatus', $product->ulid, 'published');
        $first = $product->fresh();
        expect($first->status)->toBe('published')
            ->and($first->published_at)->not->toBeNull()
            ->and(SiteRoute::query()->where('product_id', $product->id)->value('path'))->toBe('/do-ga-han')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_status_published', ['name' => 'Đồ gá hàn']))
            ->and($test->get('undoOp'))->not->toBeNull();

        $stamp = $first->published_at?->toIso8601String();
        $this->travel(2)->hours();
        $test->call('undoLast');
        expect($product->fresh()->status)->toBe('draft')
            ->and($product->fresh()->published_at?->toIso8601String())->toBe($stamp)
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_undone'))
            ->and($test->get('undoOp'))->toBeNull();

        $test->call('setStatus', $product->ulid, 'archived')->call('setStatus', $product->ulid, 'published');
        expect($product->fresh()->published_at?->toIso8601String())->toBe($stamp);
    });
});

it('moves a published product to draft or off the website and back', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct('Đồ gá kiểm', 'jig-kiem');
        $test = Livewire::actingAs($manager)->test(Index::class)->call('setStatus', $product->ulid, 'published');

        $test->call('setStatus', $product->ulid, 'archived');
        expect($product->fresh()->status)->toBe('archived')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_status_archived', ['name' => 'Đồ gá kiểm']));
        $test->call('undoLast');
        expect($product->fresh()->status)->toBe('published');

        $test->call('setStatus', $product->ulid, 'draft');
        expect($product->fresh()->status)->toBe('draft')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_status_draft', ['name' => 'Đồ gá kiểm']));
    });
});

it('refuses to publish onto an address another page owns: a message, nothing saved', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $manager->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh(), 'vi', 'gioi-thieu');
        $product = rowProduct('Trùng đường dẫn', 'gioi-thieu');

        $test = Livewire::actingAs($manager)->test(Index::class)->call('setStatus', $product->ulid, 'published');

        expect($product->fresh()->status)->toBe('draft')
            ->and($product->fresh()->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('product_id', $product->id)->exists())->toBeFalse()
            ->and($test->get('toastTone'))->toBe('error')
            ->and($test->get('toastMsg'))->toBe(__('catalog.status_route_conflict'))
            ->and($test->get('undoOp'))->toBeNull();
    });
});

it('puts a label on and takes it off, each with an undo', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct();
        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleFlag', $product->ulid, 'featured');
        expect($product->fresh()->is_featured)->toBeTrue()
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_flag_on', ['label' => __('catalog.tag_featured'), 'name' => 'Đồ gá hàn']));

        $test->call('undoLast');
        expect($product->fresh()->is_featured)->toBeFalse();

        $test->call('toggleFlag', $product->ulid, 'best')->call('toggleFlag', $product->ulid, 'best');
        expect($product->fresh()->is_bestseller)->toBeFalse()
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_flag_off', ['label' => __('catalog.tag_best'), 'name' => 'Đồ gá hàn']));
        $test->call('undoLast');
        expect($product->fresh()->is_bestseller)->toBeTrue();
    });
});

it('moves a product to the Trash and back with undo, and restores it as a draft that undo sends back', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct('Đồ gá hàn', 'do-ga-han', 'published');
        $product->forceFill(['status' => 'published'])->save();

        $test = Livewire::actingAs($manager)->test(Index::class)->call('trash', $product->ulid);
        expect(Product::query()->count())->toBe(0)
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_trashed', ['name' => 'Đồ gá hàn']));
        $test->call('undoLast');
        expect(Product::query()->find($product->id)?->status)->toBe('published');

        $test->call('trash', $product->ulid)->call('restore', $product->ulid);
        expect(Product::query()->find($product->id)?->status)->toBe('draft')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_restored', ['name' => 'Đồ gá hàn']));
        $test->call('undoLast');
        expect(Product::query()->count())->toBe(0)
            ->and(Product::onlyTrashed()->find($product->id)?->status)->toBe('published');
    });
});

it('says why an undo cannot be done when the product is gone, never doing nothing silently', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct();
        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleFlag', $product->ulid, 'featured');
        $product->forceDelete();
        $test->call('undoLast');
        expect($test->get('toastTone'))->toBe('error')
            ->and($test->get('toastMsg'))->toBe(__('catalog.undo_gone'));

        $other = rowProduct('Hai', 'hai');
        $test->call('trash', $other->ulid);
        $other->forceDelete();
        $test->call('undoLast');
        expect($test->get('toastMsg'))->toBe(__('catalog.undo_gone'));

        $test->call('setStatus', 'khong-co', 'published');
        expect($test->get('toastMsg'))->toBe(__('catalog.row_gone'));
    });
});

it('refuses a status or label change on a product in the Trash and on a value the menu never sends', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct();
        $product->delete();
        $test = Livewire::actingAs($manager)->test(Index::class);
        $test->call('setStatus', $product->ulid, 'published');
        expect(Product::onlyTrashed()->find($product->id)?->status)->toBe('draft')
            ->and($test->get('toastMsg'))->toBe(__('catalog.row_gone'));
        $test->call('toggleFlag', $product->ulid, 'featured');
        expect(Product::onlyTrashed()->find($product->id)?->is_featured)->toBeFalse();

        $live = rowProduct('Hai', 'hai');
        Livewire::actingAs($manager)->test(Index::class)->call('setStatus', $live->ulid, 'deleted')->assertStatus(422);
        Livewire::actingAs($manager)->test(Index::class)->call('toggleFlag', $live->ulid, 'is_hidden')->assertStatus(422);
        expect($live->fresh()->status)->toBe('draft');
    });
});

it('refuses every row action to a viewer and a user without a role, and never reaches another website\'s product', function (): void {
    $viewer = User::factory()->create();
    $manager = rowManager();
    $foreign = null;

    app(Tenancy::class)->enter(2, function () use (&$foreign): void {
        $foreign = rowProduct('Của site khác', 'cua-site-khac');
    });

    app(Tenancy::class)->enter(1, function () use ($viewer, $manager, $foreign): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $product = rowProduct();

        $as = fn () => Livewire::actingAs($viewer->fresh())->test(Index::class);
        $as()->call('setStatus', $product->ulid, 'published')->assertForbidden();
        $as()->call('toggleFlag', $product->ulid, 'featured')->assertForbidden();
        $as()->call('trash', $product->ulid)->assertForbidden();
        expect($product->fresh()->status)->toBe('draft')->and($product->fresh()->is_featured)->toBeFalse();

        $mine = Livewire::actingAs($manager)->test(Index::class);
        $mine->call('setStatus', $foreign->ulid, 'published');
        expect($mine->get('toastMsg'))->toBe(__('catalog.row_gone'));
    });

    expect(DB::connection('pgsql_admin')->table('products')->where('id', $foreign->id)->value('status'))->toBe('draft');
    // a signed-in user with no role on this website is stopped before any action can run
    Livewire::actingAs(User::factory()->create())->test(Index::class)->assertForbidden();
});

it('lets a viewer open only the website link of a published row, and a manager the full menu', function (): void {
    $viewer = User::factory()->create();
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($viewer, $manager): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $product = rowProduct('Đang bán', 'dang-ban', 'published');
        $product->forceFill(['status' => 'published'])->save();
        rowProduct('Chưa đăng', 'chua-dang');

        $view = Livewire::actingAs($viewer->fresh())->test(Index::class)->assertSee(__('catalog.view_on_site'));
        expect(substr_count($view->html(), __('catalog.view_on_site')))->toBe(1)
            ->and($view->html())->not->toContain(__('catalog.menu_trash'));

        $html = Livewire::actingAs($manager)->test(Index::class)->html();
        expect(substr_count($html, __('catalog.view_on_site')))->toBe(1)
            ->and(substr_count($html, __('catalog.menu_trash')))->toBe(2)
            ->and($html)->toContain(__('catalog.flag_add_featured'))->toContain(__('catalog.flag_add_best'))
            ->and($html)->toContain(__('catalog.menu_trash_hint'))->toContain(__('catalog.copy_path'));
    });
});

it('tells a Trash row\'s name what to do instead of opening an editor', function (): void {
    $manager = rowManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $product = rowProduct();
        $product->delete();
        $test = Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash')->assertSeeHtml('wire:click="trashedNote"')
            ->call('trashedNote');
        expect($test->get('toastMsg'))->toBe(__('catalog.trash_note'));
    });
});

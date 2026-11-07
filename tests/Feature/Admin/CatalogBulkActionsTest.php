<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Actions\ChangeProductMainCategory;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The products list's selection and bulk bar: ticking rows, the bar's actions (Đăng, Gỡ khỏi website,
 * Đổi chuyên mục, Chuyển vào Thùng rác; on the Trash Khôi phục and Xóa vĩnh viễn), each with the rows it
 * skips counted, one message, and an undo that puts every row back through the same Actions. The ticks
 * are only the server's note: every action looks the rows up again in this website, in the group on
 * screen, and drops what is not there.
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

function bulkProduct(string $name, string $slug, string $status = 'draft', ?Taxonomy $category = null, ?string $sku = null): Product
{
    $product = Product::query()->create(['status' => $status, 'sku' => $sku]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
    if ($category !== null) {
        $product->assignCategories([$category->id]);
    }
    if ($status === 'published') {
        $product->forceFill(['published_at' => now()])->save();
    }

    return $product;
}

function bulkCategory(string $name): Taxonomy
{
    return app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => $name]));
}

function bulkManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));

    return $user->fresh();
}

function bulkItems(Taxonomy $category): int
{
    return (int) DB::table('taxonomies')->where('id', $category->id)->value('items_count');
}

it('ticks rows and the whole page, unticks them again, and drops the ticks when the list changes', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');
        $b = bulkProduct('Khuôn đúc', 'khuon-duc');
        $c = bulkProduct('Mũi khoan thép', 'mui-khoan-thep');

        $test = Livewire::actingAs($manager)->test(Index::class)
            ->call('toggleSelect', [$a->ulid])
            ->assertSet('selected', [$a->ulid])
            ->call('toggleSelect', [$a->ulid, $b->ulid, $c->ulid])
            ->assertSet('selected', [$a->ulid, $b->ulid, $c->ulid]);
        $test->call('toggleSelect', [$a->ulid, $b->ulid, $c->ulid])->assertSet('selected', []);

        $test->call('toggleSelect', [$a->ulid])->set('search', 'khuôn')->assertSet('selected', []);
        $test->set('search', '')->call('toggleSelect', [$a->ulid])->call('setTab', 'published')->assertSet('selected', []);
        $test->call('toggleSelect', [$a->ulid])->call('gotoPage', 1)->assertSet('selected', []);
        $test->call('toggleSelect', [$a->ulid])->call('clearSelection')->assertSet('selected', []);
    });
});

it('keeps at most one page of ticks', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $ulids = array_map(fn (int $i): string => bulkProduct("Sản phẩm {$i}", "sp-{$i}")->ulid, range(1, 52));
        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', $ulids);

        expect($test->get('selected'))->toHaveCount(50);
    });
});

it('shows the boxes and the bar to a member who manages products and not to a viewer', function (): void {
    $manager = bulkManager();
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager, $viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');

        Livewire::actingAs($manager)->test(Index::class)
            ->assertDontSee(__('catalog.bulk_region'))
            ->call('toggleSelect', [$a->ulid])
            ->assertSee(__('catalog.bulk_region'))
            ->assertSee(__('catalog.bulk_selected', ['count' => 1]))
            ->assertSee(__('catalog.bulk_sel_named', ['name' => 'Đồ gá hàn']), false);

        Livewire::actingAs($viewer->fresh())->test(Index::class)
            ->assertDontSee(__('catalog.bulk_sel_header', ['n' => 1]))
            ->assertDontSee(__('catalog.bulk_sel_named', ['name' => 'Đồ gá hàn']), false)
            ->call('toggleSelect', [$a->ulid])->assertForbidden();
    });
});

it('drops ticks that are another website, the Trash or nothing at all before it acts', function (): void {
    $manager = bulkManager();

    $foreign = app(Tenancy::class)->enter(2, fn (): string => bulkProduct('Hàng kho khác', 'hang-kho-khac')->ulid);

    app(Tenancy::class)->enter(1, function () use ($manager, $foreign): void {
        $mine = bulkProduct('Đồ gá hàn', 'do-ga-han');
        $trashed = bulkProduct('Khuôn cũ', 'khuon-cu');
        $trashed->delete();

        $test = Livewire::actingAs($manager)->test(Index::class)
            ->call('toggleSelect', [$mine->ulid, $foreign, $trashed->ulid, '01J00000000000000000000000'])
            ->call('bulkPublish');

        expect($test->get('toastMsg'))->toBe(__('catalog.toast_status_published', ['name' => 'Đồ gá hàn']))
            ->and($mine->fresh()->status)->toBe('published')
            ->and(Product::withTrashed()->where('ulid', $trashed->ulid)->value('status'))->toBe('draft');
    });

    app(Tenancy::class)->enter(2, fn () => expect(Product::query()->where('ulid', $foreign)->value('status'))->toBe('draft'));

    app(Tenancy::class)->enter(1, function () use ($manager, $foreign): void {
        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$foreign, '01J00000000000000000000000'])->call('bulkTrash');

        expect($test->get('toastMsg'))->toBe(__('catalog.row_gone'))->and($test->get('toastTone'))->toBe('error')->and($test->get('selected'))->toBe([]);
    });
});

it('publishes the products that are not live, counts the ones it leaves, and undoes each one', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');
        $b = bulkProduct('Khuôn đúc', 'khuon-duc', 'archived');
        $c = bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'published');

        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$a->ulid, $b->ulid, $c->ulid])->call('bulkPublish');

        // the skipped one is told in the bar, not in the message
        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_status_published', ['count' => 2]))
            ->and($a->fresh()->status)->toBe('published')->and($a->fresh()->published_at)->not->toBeNull()
            ->and($b->fresh()->status)->toBe('published')
            ->and(SiteRoute::query()->whereIn('product_id', [$a->id, $b->id])->pluck('path')->sort()->values()->all())->toBe(['/do-ga-han', '/khuon-duc'])
            ->and($test->get('selected'))->toHaveCount(3);

        $test->call('undoLast');
        expect($a->fresh()->status)->toBe('draft')->and($b->fresh()->status)->toBe('archived')->and($c->fresh()->status)->toBe('published')
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_undone'))->and($test->get('undoOp'))->toBeNull();
    });
});

it('takes the live products off the website, leaves the others alone, and says so when nothing could be done', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han', 'published');
        $b = bulkProduct('Khuôn đúc', 'khuon-duc');
        $c = bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'published');

        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$a->ulid, $b->ulid, $c->ulid])->call('bulkArchive');

        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_status_archived', ['count' => 2]))
            ->and($a->fresh()->status)->toBe('archived')->and($b->fresh()->status)->toBe('draft')->and($c->fresh()->status)->toBe('archived');

        $test->call('undoLast');
        expect($a->fresh()->status)->toBe('published')->and($c->fresh()->status)->toBe('published');

        $none = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$b->ulid])->call('bulkArchive');
        expect($none->get('toastMsg'))->toBe(__('catalog.bulk_none_can', ['label' => __('catalog.bulk_archive'), 'why' => __('catalog.bulk_why_unpublished', ['count' => 1])]))
            ->and($none->get('toastTone'))->toBe('info')->and($none->get('undoOp'))->toBeNull()->and($b->fresh()->status)->toBe('draft');
    });
});

it('refuses to publish a product whose address another page owns and still publishes the rest', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $manager->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu', 'entry_id' => $page->id]);
        $clash = bulkProduct('Giới thiệu', 'gioi-thieu');
        $free = bulkProduct('Khuôn đúc', 'khuon-duc');

        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$clash->ulid, $free->ulid])->call('bulkPublish');

        expect($test->get('toastMsg'))->toBe(__('catalog.toast_status_published', ['name' => 'Khuôn đúc']).' '.__('catalog.bulk_failed_route', ['count' => 1]))
            ->and($clash->fresh()->status)->toBe('draft')->and($free->fresh()->status)->toBe('published');
    });
});

it('moves the ticked products to the Trash, out of their category count, and brings them back with the undo', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $category = bulkCategory('Đồ gá');
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han', 'published', $category);
        $b = bulkProduct('Khuôn đúc', 'khuon-duc', 'draft', $category);
        bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'draft', $category);
        expect(bulkItems($category))->toBe(3);

        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$a->ulid, $b->ulid])->call('bulkTrash');

        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_trashed', ['count' => 2]))
            ->and(Product::query()->count())->toBe(1)->and(bulkItems($category))->toBe(1)->and($test->get('selected'))->toBe([]);

        $test->call('undoLast');
        expect(Product::query()->count())->toBe(3)->and(bulkItems($category))->toBe(3)->and($a->fresh()->status)->toBe('published');

        $one = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$b->ulid])->call('bulkTrash');
        expect($one->get('toastMsg'))->toBe(__('catalog.toast_trashed', ['name' => 'Khuôn đúc']));
    });
});

it('restores the ticked products from the Trash as drafts and puts them back with the undo', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $category = bulkCategory('Đồ gá');
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han', 'published', $category);
        $b = bulkProduct('Khuôn đúc', 'khuon-duc', 'archived', $category);
        $live = bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'published', $category);
        $a->delete();
        $b->delete();
        expect(bulkItems($category))->toBe(1);

        $test = Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash')->call('toggleSelect', [$a->ulid, $b->ulid, $live->ulid])->call('bulkRestore');

        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_restored', ['count' => 2]))
            ->and($a->fresh()->status)->toBe('draft')->and($b->fresh()->status)->toBe('draft')->and(bulkItems($category))->toBe(3);

        $test->call('undoLast');
        expect(Product::onlyTrashed()->pluck('status', 'ulid')->all())->toBe([$a->ulid => 'published', $b->ulid => 'archived'])->and(bulkItems($category))->toBe(1);
    });
});

it('deletes the ticked products from the Trash for good, frees their SKU and address, and cannot be undone', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $category = bulkCategory('Đồ gá');
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han', 'published', $category, 'DG-001');
        $b = bulkProduct('Khuôn đúc', 'khuon-duc', 'published', $category, 'KD-002');
        $live = bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'draft', $category, 'MK-003');
        SiteRoute::query()->firstOrCreate(['product_id' => $a->id, 'locale' => 'vi'], ['path' => '/do-ga-han']);
        expect(bulkItems($category))->toBe(3);
        $a->delete();
        $b->delete();
        expect(bulkItems($category))->toBe(1);

        $test = Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash')->call('toggleSelect', [$a->ulid, $b->ulid, $live->ulid])->call('bulkForceDelete');

        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_purged', ['count' => 2]))->and($test->get('undoOp'))->toBeNull()
            ->and(Product::withTrashed()->pluck('ulid')->all())->toBe([$live->ulid])
            ->and(DB::table('product_translations')->whereIn('product_id', [$a->id, $b->id])->count())->toBe(0)
            ->and(SiteRoute::query()->whereIn('product_id', [$a->id, $b->id])->count())->toBe(0)
            ->and(bulkItems($category))->toBe(1);

        $again = bulkProduct('Đồ gá hàn mới', 'do-ga-han', 'draft', null, 'DG-001');
        expect($again->exists)->toBeTrue();

        $one = Livewire::actingAs($manager)->test(Index::class)->set('tab', 'trash');
        $live->delete();
        $one->call('toggleSelect', [$live->ulid])->call('bulkForceDelete');
        expect($one->get('toastMsg'))->toBe(__('catalog.bulk_purged_one', ['name' => 'Mũi khoan thép']));
    });
});

it('changes the main category of drafts and live products, redirects only the addresses that moved, and puts it all back', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'category']]);
        $from = bulkCategory('Đồ gá');
        $to = bulkCategory('Khuôn mẫu');
        $other = bulkCategory('Phụ kiện');
        $live = bulkProduct('Đồ gá hàn', 'do-ga-han', 'published', $from);
        $draft = bulkProduct('Khuôn đúc', 'khuon-duc', 'draft', $from);
        $already = bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'published', $to);
        $both = bulkProduct('Bàn kẹp', 'ban-kep', 'draft', $from);
        $both->assignCategories([$from->id, $other->id]);
        $toSlug = $to->translations()->where('locale', 'vi')->value('slug');
        $fromSlug = $from->translations()->where('locale', 'vi')->value('slug');
        $liveRoute = app(RouteSync::class)->syncProduct($live->id, $from, 'vi', 'do-ga-han');
        expect($liveRoute)->toBe("/{$fromSlug}/do-ga-han");

        $test = Livewire::actingAs($manager)->test(Index::class)
            ->call('toggleSelect', [$live->ulid, $draft->ulid, $already->ulid, $both->ulid])
            ->call('openCategory')
            ->assertSet('catOpen', true)->assertSet('catTargets', [$live->ulid, $draft->ulid, $already->ulid, $both->ulid])
            ->assertSee(__('catalog.cat_title_many', ['count' => 4]))
            ->call('changeCategory', $to->ulid);

        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_cat_done', ['count' => 3, 'category' => 'Khuôn mẫu']).' '.__('catalog.bulk_cat_redirect', ['count' => 1]))
            ->and($test->get('catOpen'))->toBeFalse()->and($test->get('catTargets'))->toBe([])
            ->and($live->primaryCategory()?->id)->toBe($to->id)
            ->and($both->fresh()->taxonomies()->pluck('taxonomies.id')->all())->toBe([$to->id, $other->id])
            ->and(SiteRoute::query()->where('product_id', $live->id)->value('path'))->toBe("/{$toSlug}/do-ga-han")
            ->and(Redirect::query()->where('from_path', $liveRoute)->value('to_path'))->toBe("/{$toSlug}/do-ga-han")
            ->and(SiteRoute::query()->where('product_id', $draft->id)->exists())->toBeFalse()
            ->and(bulkItems($from))->toBe(0)->and(bulkItems($to))->toBe(4);

        $test->call('undoLast');
        expect($live->fresh()->primaryCategory()?->id)->toBe($from->id)
            ->and($both->fresh()->taxonomies()->pluck('taxonomies.id')->all())->toBe([$from->id, $other->id])
            ->and(SiteRoute::query()->where('product_id', $live->id)->value('path'))->toBe($liveRoute)
            ->and(bulkItems($from))->toBe(3)->and(bulkItems($to))->toBe(1)
            ->and($test->get('toastMsg'))->toBe(__('catalog.toast_undone'));
    });
});

it('says nothing changed when the products already have that main category, and changes one row from its menu', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $to = bulkCategory('Khuôn mẫu');
        $a = bulkProduct('Mũi khoan thép', 'mui-khoan-thep', 'published', $to);
        $b = bulkProduct('Đồ gá hàn', 'do-ga-han');

        $same = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$a->ulid])->call('openCategory')->call('changeCategory', $to->ulid);
        expect($same->get('toastMsg'))->toBe(__('catalog.bulk_cat_same', ['category' => 'Khuôn mẫu']))->and($same->get('undoOp'))->toBeNull();

        $row = Livewire::actingAs($manager)->test(Index::class)->call('openCategory', $b->ulid)
            ->assertSet('catTargets', [$b->ulid])->assertSee(__('catalog.cat_title_one'))->call('changeCategory', $to->ulid);
        expect($row->get('toastMsg'))->toBe(__('catalog.bulk_cat_done', ['count' => 1, 'category' => 'Khuôn mẫu']))
            ->and($b->fresh()->primaryCategory()?->id)->toBe($to->id);

        $gone = Livewire::actingAs($manager)->test(Index::class)->call('openCategory', '01J00000000000000000000000');
        expect($gone->get('catOpen'))->toBeFalse()->and($gone->get('toastMsg'))->toBe(__('catalog.row_gone'));
    });
});

it('lists the product categories in the dialog with the names above each one', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $parent = bulkCategory('Đồ gá');
        app(CreateTaxonomy::class)->handle('product_cat', $parent, new TaxonomyData(names: ['vi' => 'Gá hàn']));
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han', 'draft', $parent);

        $html = Livewire::actingAs($manager)->test(Index::class)->call('openCategory', $a->ulid)->html();

        expect($html)->toContain('role="radiogroup"')->toContain('Gá hàn')->toContain('Đồ gá › ')->toContain(__('catalog.cat_search'));
    });
});

it('refuses every bulk write to a viewer, a member of another website and a signed-out browser', function (): void {
    $viewer = User::factory()->create();
    $stranger = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'cat-viewer', ['catalog.view']));
    app(Tenancy::class)->enter(2, fn () => grantRole($stranger, 'cat-manager', ['catalog.view', 'catalog.manage']));

    app(Tenancy::class)->enter(1, function () use ($viewer, $stranger): void {
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');

        foreach (['bulkPublish', 'bulkArchive', 'bulkTrash', 'bulkRestore', 'bulkForceDelete', 'openCategory', 'clearSelection'] as $method) {
            if ($method === 'clearSelection') {
                continue;
            }
            Livewire::actingAs($viewer->fresh())->test(Index::class)->call($method)->assertForbidden();
        }
        Livewire::actingAs($viewer->fresh())->test(Index::class)->call('changeCategory', '01J00000000000000000000000')->assertForbidden();
        Livewire::actingAs($stranger->fresh())->test(Index::class)->assertForbidden();
        Livewire::test(Index::class)->assertForbidden();

        expect($a->fresh()->status)->toBe('draft');
    });
});

it('asks for the undo again: a viewer cannot replay one', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');
        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$a->ulid])->call('bulkTrash');
        expect($test->get('undoOp')['kind'])->toBe('bulk_untrash')->and(Product::onlyTrashed()->count())->toBe(1);

        // the same open screen, the member's right taken away meanwhile
        Role::findByName('cat-manager', PermissionRegistry::GUARD)->syncPermissions(['catalog.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $test->call('undoLast')->assertForbidden();
        expect(Product::onlyTrashed()->count())->toBe(1);
    });
});

it('acts only on ticked rows that are still in the group, filters and search on screen', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');
        $b = bulkProduct('Khuôn đúc', 'khuon-duc');
        $c = bulkProduct('Mũi khoan thép', 'mui-khoan-thep');

        // on the Draft group: tick A and B, publish them (they leave the group), tick C, move to the Trash: only C goes
        $test = Livewire::actingAs($manager)->test(Index::class)->call('setTab', 'draft')->call('toggleSelect', [$a->ulid, $b->ulid])->call('bulkPublish');
        expect($test->get('selected'))->toBe([]);
        $test->call('toggleSelect', [$c->ulid])->call('bulkTrash');
        expect($test->get('toastMsg'))->toBe(__('catalog.toast_trashed', ['name' => 'Mũi khoan thép']))
            ->and($a->fresh()->status)->toBe('published')->and($b->fresh()->status)->toBe('published')
            ->and(Product::query()->count())->toBe(2);

        // a row that leaves the group between the tick and the click (someone else published it) is left alone
        $d = bulkProduct('Bàn kẹp', 'ban-kep');
        $e = bulkProduct('Dưỡng đo', 'duong-do');
        $race = Livewire::actingAs($manager)->test(Index::class)->call('setTab', 'draft')->call('toggleSelect', [$d->ulid, $e->ulid]);
        $d->forceFill(['status' => 'archived'])->save();
        $race->call('bulkTrash');
        expect($race->get('toastMsg'))->toBe(__('catalog.toast_trashed', ['name' => 'Dưỡng đo']))
            ->and(Product::query()->where('ulid', $d->ulid)->exists())->toBeTrue();

        // a search that no longer matches takes the tick away; a sort keeps it
        $f = bulkProduct('Cữ chặn', 'cu-chan');
        $kept = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$f->ulid])->call('setSort', 'name');
        expect($kept->get('selected'))->toBe([$f->ulid]);
        $kept->set('search', 'khuôn')->assertSet('selected', []);
    });
});

it('leaves the category totals alone when a product cannot move to an address that is taken', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'category']]);
        $from = bulkCategory('Đồ gá');
        $to = bulkCategory('Khuôn mẫu');
        $toSlug = $to->translations()->where('locale', 'vi')->value('slug');
        $clash = bulkProduct('Đồ gá hàn', 'do-ga-han', 'published', $from);
        app(RouteSync::class)->syncProduct($clash->id, $from, 'vi', 'do-ga-han');
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $manager->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Đồ gá', 'slug' => 'do-ga-han', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => "/{$toSlug}/do-ga-han", 'entry_id' => $page->id]);
        expect(bulkItems($from))->toBe(1)->and(bulkItems($to))->toBe(0);

        $test = Livewire::actingAs($manager)->test(Index::class)->call('toggleSelect', [$clash->ulid])->call('openCategory')->call('changeCategory', $to->ulid);

        expect($test->get('toastMsg'))->toBe(__('catalog.bulk_cat_failed', ['count' => 1]))
            ->and($clash->fresh()->primaryCategory()?->id)->toBe($from->id)
            ->and(bulkItems($from))->toBe(1)->and(bulkItems($to))->toBe(0);
    });
});

it('refuses a category that is not a product category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $post = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Bài viết mới']));
        $a = bulkProduct('Đồ gá hàn', 'do-ga-han');

        expect(fn () => app(ChangeProductMainCategory::class)->handle(new EloquentCollection([$a->load(['taxonomies', 'translations'])]), $post))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('keeps its query count flat for any number of ticked rows', function (): void {
    $manager = bulkManager();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        $category = bulkCategory('Đồ gá');
        $other = bulkCategory('Khuôn mẫu');
        $make = function (int $n, string $status) use ($category): array {
            return array_map(fn (int $i): string => bulkProduct("Sản phẩm {$status} {$i} đẹp", "sp-{$status}-{$i}", $status, $category)->ulid, range(1, $n));
        };
        // ticks the rows, makes the steps in $before, then counts only the one call that follows
        $measure = function (string $method, array $ulids, array $args = [], string $tab = 'all', array $before = []) use ($manager): int {
            $test = Livewire::actingAs($manager)->test(Index::class)->set('tab', $tab)->call('toggleSelect', $ulids);
            foreach ($before as [$step, $stepArgs]) {
                $test->call($step, ...$stepArgs);
            }

            return countQueries(fn () => $test->call($method, ...$args));
        };
        $run = fn (string $method, array $ulids, array $args = [], string $tab = 'all'): int => $measure($method, $ulids, $args, $tab);
        $dialog = [['openCategory', []]];

        $trash2 = $run('bulkTrash', $make(2, 'draft'));
        $trash6 = $run('bulkTrash', $make(6, 'draft'));
        $archive2 = $run('bulkArchive', $make(2, 'published'));
        $archive6 = $run('bulkArchive', $make(6, 'published'));
        $publish2 = $run('bulkPublish', $make(2, 'archived'));
        $publish6 = $run('bulkPublish', $make(6, 'archived'));
        $open2 = $run('openCategory', $make(2, 'draft'));
        $open6 = $run('openCategory', $make(6, 'draft'));
        $move2 = $measure('changeCategory', $make(2, 'draft'), [$other->ulid], 'all', $dialog);
        $move6 = $measure('changeCategory', $make(6, 'draft'), [$other->ulid], 'all', $dialog);

        $drafts = $make(8, 'draft');
        Product::query()->whereIn('ulid', $drafts)->get()->each->delete();
        $restore2 = $run('bulkRestore', array_slice($drafts, 0, 2), [], 'trash');
        $restore6 = $run('bulkRestore', array_slice($drafts, 2, 6), [], 'trash');

        $drafts = $make(8, 'archived');
        Product::query()->whereIn('ulid', $drafts)->get()->each->delete();
        $purge2 = $run('bulkForceDelete', array_slice($drafts, 0, 2), [], 'trash');
        $purge6 = $run('bulkForceDelete', array_slice($drafts, 2, 6), [], 'trash');

        // the undo of a status change, of a move to the Trash and of a category change
        $undoStatus2 = $measure('undoLast', $make(2, 'published'), [], 'all', [['bulkArchive', []]]);
        $undoStatus6 = $measure('undoLast', $make(6, 'published'), [], 'all', [['bulkArchive', []]]);
        $undoTrash2 = $measure('undoLast', $make(2, 'draft'), [], 'all', [['bulkTrash', []]]);
        $undoTrash6 = $measure('undoLast', $make(6, 'draft'), [], 'all', [['bulkTrash', []]]);
        $undoMove2 = $measure('undoLast', $make(2, 'draft'), [], 'all', [...$dialog, ['changeCategory', [$other->ulid]]]);
        $undoMove6 = $measure('undoLast', $make(6, 'draft'), [], 'all', [...$dialog, ['changeCategory', [$other->ulid]]]);

        // published products move their address with the category: the route is composed and the old one redirected
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'category']]);
        $moveLive2 = $measure('changeCategory', $make(2, 'published'), [$other->ulid], 'all', $dialog);
        $moveLive6 = $measure('changeCategory', $make(6, 'published'), [$other->ulid], 'all', $dialog);

        // fixed set-up statements plus a constant few per row (its lock and its write, and for a moved or published row its
        // route sync): never a lookup of a category, a name or a route per row; opening the dialog reads no row per ticked row
        expect([$trash2, $trash6])->toBe([15, 23])
            ->and([$restore2, $restore6])->toBe([19, 27])
            ->and([$purge2, $purge6])->toBe([16, 24])
            ->and([$archive2, $archive6])->toBe([17, 21])
            ->and([$publish2, $publish6])->toBe([25, 41])
            ->and([$open2, $open6])->toBe([16, 16])
            ->and([$move2, $move6])->toBe([31, 55])
            ->and([$moveLive2, $moveLive6])->toBe([31, 59])
            ->and([$undoStatus2, $undoStatus6])->toBe([23, 35])
            ->and([$undoTrash2, $undoTrash6])->toBe([19, 27])
            ->and([$undoMove2, $undoMove6])->toBe([29, 55]);
    });
});

<?php

use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;

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

    // Flat ceiling: the nav/entitlement/theme baseline + the Pages list's own flat set — the
    // entries load plus its translations/routes/author eager loads (the language, path and
    // creator columns) and ONE grouped status-count (tabs + trash). All flat: an N+1 would
    // scale with the 10 seeded rows and blow past this; the no-growth guarantee is additionally
    // pinned in PagesIndexTest ("renders at a flat query budget regardless of row count").
    expect($count)->toBeLessThanOrEqual(22);
});

it('lists admin entries on the same budget for a member who may edit only their own records', function (): void {
    $author = User::factory()->create();
    $other = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $other): void {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own']);

        // Half the rows are theirs, half someone else's: the per-row edit right (policy, own vs
        // other, category limits) must come from data already loaded, not a query per row.
        foreach (range(1, 10) as $i) {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $i % 2 ? $author->id : $other->id]);
            $entry->translations()->create([
                'locale' => 'vi', 'title' => "Page {$i}", 'slug' => "page-{$i}", 'status' => 'draft', 'body' => ['blocks' => []],
            ]);
        }
    });

    $count = countQueries(fn () => $this->actingAs($author)->get('http://a.test/admin/content')->assertOk());

    expect($count)->toBeLessThanOrEqual(22);
});

it('lists admin catalog on a flat query budget (no N+1 across rows)', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);

        // Ten products, each with a translation + category + gallery image, so an N+1 over any
        // of the four row decorations (name, category, price badge, thumbnail) blows the ceiling.
        foreach (range(1, 10) as $i) {
            $media = Media::query()->create([
                'collection' => 'library', 'slug' => "anh-{$i}", 'hash' => hash('sha256', "img-{$i}"),
                'storage_key' => "s/library/anh-{$i}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
            ]);
            $product = Product::query()->create(['status' => 'draft', 'sku' => "SKU-{$i}", 'price' => 1000000]);
            $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i}", 'slug' => "sp-{$i}"]);
            $product->assignCategories([$category->id]);
            $product->images()->create(['media_id' => $media->id, 'position' => 0]);
        }
    });

    $count = countQueries(fn () => $this->actingAs($viewer)->get('http://a.test/admin/catalog')->assertOk());

    // Nav/entitlement/theme baseline (~19, same as the other lists) + the catalog page's own
    // flat set: paginator count, rows, 4 eager loads, trash count. An N+1 scales with the 10
    // seeded rows and still blows well past this.
    expect($count)->toBeLessThanOrEqual(26);
});

it('keeps the catalog list flat with a search, a sort and a second page, whatever the number of rows', function (): void {
    $viewer = User::factory()->create();

    $seed = function (int $from, int $to) use ($viewer): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to, $viewer): void {
            grantRole($viewer, 'cat-viewer', ['catalog.view']);
            SiteModule::query()->firstOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
            $category = Taxonomy::query()->firstOrCreate(['vocabulary' => 'product_cat', 'position' => 0]);
            $category->translations()->firstOrCreate(['locale' => 'vi'], ['name' => 'Đồ gá', 'slug' => 'do-ga']);

            foreach (range($from, $to) as $i) {
                $media = Media::query()->create([
                    'collection' => 'library', 'slug' => "anh-{$i}", 'hash' => hash('sha256', "img-{$i}"),
                    'storage_key' => "s/library/anh-{$i}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
                ]);
                $product = Product::query()->create(['status' => 'published', 'sku' => "SKU-{$i}", 'price' => 1000000 + $i]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i}", 'slug' => "sp-{$i}"]);
                $product->assignCategories([$category->id]);
                $second = Media::query()->create([
                    'collection' => 'library', 'slug' => "anh-{$i}-b", 'hash' => hash('sha256', "img-{$i}-b"),
                    'storage_key' => "s/library/anh-{$i}-b.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
                ]);
                $product->images()->create(['media_id' => $media->id, 'position' => 0]);
                $product->images()->create(['media_id' => $second->id, 'position' => 1]);
            }
        });
    };
    $url = 'http://a.test/admin/catalog?q=Sản&sort=name&tab=published&per_page=10&page=2';

    $seed(1, 30);
    $this->actingAs($viewer)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $count = countQueries(fn () => $this->actingAs($viewer)->get($url)->assertOk()->assertSee('Sản phẩm'));

    $seed(31, 60);
    $this->actingAs($viewer)->get($url)->assertOk(); // granting the role again clears the permission cache
    $more = countQueries(fn () => $this->actingAs($viewer)->get($url)->assertOk());

    // 10 rows on the page either way: twice the products must not add a single query
    expect($more)->toBe($count)->and($count)->toBe(18);
});

it('keeps the catalog list flat with every filter on and a second page, whatever the number of rows and categories', function (): void {
    $viewer = User::factory()->create();

    $seed = function (int $from, int $to) use ($viewer): array {
        $keys = [];
        app(Tenancy::class)->enter(1, function () use ($from, $to, $viewer, &$keys): void {
            grantRole($viewer, 'cat-viewer', ['catalog.view']);
            SiteModule::query()->firstOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
            SiteSetting::query()->firstOrCreate(['group_name' => 'catalog', 'key' => 'mode'], ['value' => ['value' => 'commerce']]);
            SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
            $root = Taxonomy::query()->firstOrCreate(['vocabulary' => 'product_cat', 'position' => 0]);
            $root->translations()->firstOrCreate(['locale' => 'vi'], ['name' => 'Gốc', 'slug' => 'goc']);
            $keys[] = $root->ulid;

            foreach (range($from, $to) as $i) {
                $child = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $root->id, 'position' => $i]);
                $child->translations()->create(['locale' => 'vi', 'name' => "Nhóm {$i}", 'slug' => "nhom-{$i}"]);
                $product = Product::query()->create(['status' => 'published', 'sku' => "SKU-{$i}", 'price' => 1000, 'sale_price' => 500, 'stock' => 5, 'is_featured' => true]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i}", 'slug' => "sp-{$i}"]);
                $product->assignCategories([$child->id]);
            }
        });

        return $keys;
    };
    $keys = $seed(1, 30);
    $url = 'http://a.test/admin/catalog?cat[]='.$keys[0].'&tr[]=none&tag[]=featured&stock[]=in&sale[]=on&per_page=10&page=2';

    $this->actingAs($viewer)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $count = countQueries(fn () => $this->actingAs($viewer)->get($url)->assertOk()->assertSee('Sản phẩm'));

    $seed(31, 60);
    $this->actingAs($viewer)->get($url)->assertOk();
    $more = countQueries(fn () => $this->actingAs($viewer)->get($url)->assertOk());

    // twice the products and categories must not add a single query
    expect($more)->toBe($count)->and($count)->toBe(17);
});

it('pages the Trash of products by cursor on the deletion time, forward and back, meeting each row once', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 7) as $i) {
            $product = Product::query()->create(['status' => 'published', 'sku' => "TR-{$i}"]);
            $product->translations()->create(['locale' => 'vi', 'name' => "Hàng đã xoá {$i}", 'slug' => "da-xoa-{$i}"]);
            $product->delete();
            // spread the deletion times, with a tie on the last pair so the id breaks it
            Product::withTrashed()->whereKey($product->id)->toBase()
                ->update(['deleted_at' => '2026-11-0'.min($i, 6).' 08:00:00+00']);
        }
        $list = new ProductListQuery(1, 'trash', '', 'vi');
        $whole = $list->rows('updated', 0, 20)->modelKeys();
        expect($whole)->toHaveCount(7)->and(array_unique($whole))->toHaveCount(7);

        $forward = [];
        $anchor = null;
        do {
            $rows = $list->rows('updated', 0, 3, $anchor);
            $forward = [...$forward, ...$rows->modelKeys()];
            $anchor = $rows->isEmpty() ? null : ['v' => $rows->last()->getAttribute('__sv'), 'id' => $rows->last()->id];
        } while ($anchor !== null);
        expect($forward)->toBe($whole);

        $backward = [];
        $rows = $list->rows('updated', 4, 3);
        $backward = $rows->modelKeys();
        while ($rows->isNotEmpty()) {
            $rows = $list->rows('updated', 0, 3, ['v' => $rows->first()->getAttribute('__sv'), 'id' => $rows->first()->id], before: true);
            $backward = [...$rows->modelKeys(), ...$backward];
        }
        expect($backward)->toBe($whole);
    });
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

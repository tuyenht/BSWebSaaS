<?php

use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($count)->toBeLessThanOrEqual(23);
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

    // 10 rows on the page either way: twice the products must not add a single query. The search adds
    // one statement to the list without it: the word counts that choose its shape (ProductListQuery::whereWords)
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($more)->toBe($count)->and($count)->toBe(20);
});

it('keeps the catalog list flat for a member who manages products, with every row menu drawn, on a second page, whatever the number of rows', function (): void {
    $manager = User::factory()->create();

    $seed = function (int $from, int $to) use ($manager): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to, $manager): void {
            grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
            SiteModule::query()->firstOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
            $category = Taxonomy::query()->firstOrCreate(['vocabulary' => 'product_cat', 'position' => 0]);
            $category->translations()->firstOrCreate(['locale' => 'vi'], ['name' => 'Đồ gá', 'slug' => 'do-ga']);

            foreach (range($from, $to) as $i) {
                $product = Product::query()->create(['status' => 'published', 'sku' => "SKU-{$i}", 'price' => 1000000 + $i, 'is_featured' => $i % 2 === 0]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i} đẹp", 'slug' => "sp-{$i}"]);
                $product->assignCategories([$category->id]);
            }
        });
    };
    $url = 'http://a.test/admin/catalog?q=Sản&sort=name&tab=published&per_page=10&page=2';

    $seed(1, 30);
    $this->actingAs($manager)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $count = countQueries(fn () => $this->actingAs($manager)->get($url)->assertOk()->assertSee(__('catalog.menu_trash'))->assertSee(__('catalog.status_menu_label'))->assertSee(__('catalog.slug_edit_action'))
        // a row's Xem button: its name starts with every seeded product's name (the page script also mentions data-row-view)
        ->assertSee('aria-label="'.e(Str::before(__('catalog.view_named', ['name' => '|']), '|')).'Sản phẩm ', false));

    $seed(31, 60);
    $this->actingAs($manager)->get($url)->assertOk();
    $more = countQueries(fn () => $this->actingAs($manager)->get($url)->assertOk());

    // the status menu, the labels, the Trash, the quick edits and the Xem button are decided from the rows already loaded: twice the products adds no query
    // (the search adds one statement, its word counts, to the list without it)
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($more)->toBe($count)->and($count)->toBe(19);
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
                $child->translations()->create(['locale' => 'vi', 'name' => "Nhóm {$i} máy", 'slug' => "nhom-{$i}"]);
                $product = Product::query()->create(['status' => 'published', 'sku' => "SKU-{$i}", 'price' => 1000, 'sale_price' => 500, 'stock' => 5, 'is_featured' => true]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i} đẹp", 'slug' => "sp-{$i}"]);
                $product->assignCategories([$child->id]);
                $media = Media::query()->create([
                    'collection' => 'library', 'slug' => "anh-{$i}", 'hash' => hash('sha256', "img-{$i}"),
                    'storage_key' => "s/library/anh-{$i}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
                ]);
                $second = Media::query()->create([
                    'collection' => 'library', 'slug' => "anh-{$i}-b", 'hash' => hash('sha256', "img-{$i}-b"),
                    'storage_key' => "s/library/anh-{$i}-b.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
                ]);
                $product->images()->create(['media_id' => $media->id, 'position' => 0]);
                $product->images()->create(['media_id' => $second->id, 'position' => 1]);
            }
        });

        return $keys;
    };
    $keys = $seed(1, 30);
    $url = 'http://a.test/admin/catalog?cat[]='.$keys[0].'&tr[]=none&tag[]=featured&stock[]=in&sale[]=on&per_page=10&page=2';

    $this->actingAs($viewer)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $rendered = 0;
    $count = countQueries(function () use ($viewer, $url, &$rendered): void {
        $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
        $rendered = count(array_unique(preg_match_all('/Sản phẩm \d+/u', (string) $html, $found) ? $found[0] : []));
    });
    expect($rendered)->toBe(10);

    $seed(31, 60);
    $this->actingAs($viewer)->get($url)->assertOk();
    $more = countQueries(function () use ($viewer, $url, &$rendered): void {
        $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();
        $rendered = count(array_unique(preg_match_all('/Sản phẩm \d+/u', (string) $html, $found) ? $found[0] : []));
    });

    // twice the products and categories must not add a single query, and the page still shows 10 rows
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($rendered)->toBe(10)->and($more)->toBe($count)->and($count)->toBe(19);
});

it('keeps the catalog list flat with every cell and column drawn, on a second page, whatever the number of rows', function (): void {
    $viewer = User::factory()->create();

    $seed = function (int $from, int $to) use ($viewer): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to, $viewer): void {
            grantRole($viewer, 'cat-viewer', ['catalog.view', 'catalog.manage']);
            SiteModule::query()->firstOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
            SiteSetting::query()->firstOrCreate(['group_name' => 'catalog', 'key' => 'mode'], ['value' => ['value' => 'commerce']]);
            SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
            $root = Taxonomy::query()->firstOrCreate(['vocabulary' => 'product_cat', 'position' => 0]);
            $root->translations()->firstOrCreate(['locale' => 'vi'], ['name' => 'Gốc', 'slug' => 'goc']);

            foreach (range($from, $to) as $i) {
                $child = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $root->id, 'position' => $i]);
                $child->translations()->create(['locale' => 'vi', 'name' => "Nhóm {$i} máy", 'slug' => "nhom-{$i}"]);
                $product = Product::query()->create([
                    'status' => 'published', 'sku' => "SKU-{$i}", 'price' => 1000000 + $i, 'sale_price' => 900000, 'sale_ends_at' => '2099-01-01 00:00:00+00',
                    'stock' => $i % 7, 'is_featured' => $i % 2 === 0, 'is_bestseller' => $i % 3 === 0,
                ]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i} đẹp", 'slug' => "sp-{$i}"]);
                if ($i % 2 === 0) {
                    $product->translations()->create(['locale' => 'en', 'name' => "Product {$i}", 'slug' => "product-{$i}"]);
                }
                $product->assignCategories([$child->id]);
                foreach ([0, 1] as $n) {
                    $media = Media::query()->create([
                        'collection' => 'library', 'slug' => "anh-{$i}-{$n}", 'hash' => hash('sha256', "img-{$i}-{$n}"),
                        'storage_key' => "s/library/anh-{$i}-{$n}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
                    ]);
                    $product->images()->create(['media_id' => $media->id, 'position' => $n]);
                }
            }
        });
    };
    $url = 'http://a.test/admin/catalog?per_page=10&page=2';
    $rows = function () use ($viewer, $url): int {
        $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

        return count(array_unique(preg_match_all('/Sản phẩm \d+/u', (string) $html, $found) ? $found[0] : []));
    };

    $seed(1, 30);
    $this->actingAs($viewer)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $shown = 0;
    $count = countQueries(function () use ($rows, &$shown): void {
        $shown = $rows();
    });
    expect($shown)->toBe(10);

    $seed(31, 60);
    $this->actingAs($viewer)->get($url)->assertOk(); // granting the role again clears the permission cache
    $more = countQueries(function () use ($rows, &$shown): void {
        $shown = $rows();
    });

    // twice the rows: not one more query, and the page still shows 10 rows
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($shown)->toBe(10)->and($more)->toBe($count)->and($count)->toBe(18);

    // past the cap the value counts are skipped and the page costs exactly one statement less
    config(['admin.catalog_list_cap' => 20]);
    $this->actingAs($viewer)->get($url)->assertOk();
    $past = countQueries(fn () => $this->actingAs($viewer)->get($url)->assertOk());
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($past)->toBe(17);
});

it('reads the category chain of a full-path address in a fixed number of queries, with a three-level tree, whatever the number of rows', function (): void {
    $viewer = User::factory()->create();

    $seed = function (int $from, int $to) use ($viewer): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to, $viewer): void {
            grantRole($viewer, 'cat-viewer', ['catalog.view', 'catalog.manage']);
            SiteModule::query()->firstOrCreate(['module_key' => 'catalog'], ['enabled' => true]);
            SiteSetting::query()->firstOrCreate(['group_name' => 'catalog', 'key' => 'mode'], ['value' => ['value' => 'commerce']]);
            SiteSetting::query()->firstOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'category_path']]);
            app(PermalinkConfig::class)->flush();
            $root = Taxonomy::query()->firstOrCreate(['vocabulary' => 'product_cat', 'position' => 0]);
            $root->translations()->firstOrCreate(['locale' => 'vi'], ['name' => 'Gốc', 'slug' => 'goc']);
            $mid = Taxonomy::query()->firstOrCreate(['vocabulary' => 'product_cat', 'parent_id' => $root->id, 'position' => 0]);
            $mid->translations()->firstOrCreate(['locale' => 'vi'], ['name' => 'Giữa', 'slug' => 'giua']);

            foreach (range($from, $to) as $i) {
                $leaf = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $mid->id, 'position' => $i]);
                $leaf->translations()->create(['locale' => 'vi', 'name' => "Nhóm {$i} máy", 'slug' => "nhom-{$i}"]);
                $product = Product::query()->create(['status' => 'published', 'sku' => "SKU-{$i}", 'price' => 1000, 'stock' => 5]);
                $product->translations()->create(['locale' => 'vi', 'name' => "Sản phẩm {$i} đẹp", 'slug' => "sp-{$i}"]);
                $product->assignCategories([$leaf->id]);
            }
        });
    };
    $url = 'http://a.test/admin/catalog?per_page=10';
    $rows = function () use ($viewer, $url): int {
        $html = $this->actingAs($viewer)->get($url)->assertOk()->getContent();

        return count(array_unique(preg_match_all('/Sản phẩm \d+/u', (string) $html, $found) ? $found[0] : []));
    };

    $seed(1, 30);
    $this->actingAs($viewer)->get($url)->assertOk(); // the permission and settings caches fill on the first request
    $shown = 0;
    $count = countQueries(function () use ($rows, &$shown): void {
        $shown = $rows();
    });
    expect($shown)->toBe(10);

    $seed(31, 60);
    $this->actingAs($viewer)->get($url)->assertOk();
    $more = countQueries(function () use ($rows, &$shown): void {
        $shown = $rows();
    });

    // the chain adds two queries per level above the main category (the parents, then their names): two levels, four queries, for the whole page
    // +1: the top bar's bell reads the unread count (one bounded query on every admin page).
    expect($shown)->toBe(10)->and($more)->toBe($count)->and($count)->toBe(21);
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

    $this->actingAs($admin)->get('http://a.test/admin/users')->assertOk(); // the permission and settings caches fill on the first request
    $count = countQueries(fn () => $this->actingAs($admin)->get('http://a.test/admin/users')->assertOk());

    // 14 for the list itself + 1 for the "Lời mời" tab label (its waiting invitations, read up to 99 + 1)
    // + 1: the top bar's bell reads the unread count (one bounded query on every admin page)
    expect($count)->toBe(16);
});

it('keeps the members list flat with a search, a role filter and a second page, whatever the number of members', function (): void {
    $admin = User::factory()->create(['name' => 'Ánh Quản Trị']);
    $seed = function (int $from, int $to) use ($admin): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to, $admin): void {
            grantRole($admin, 'admin', ['users.manage', 'users.invite', 'entries.view']);
            foreach (range($from, $to) as $i) {
                $member = User::factory()->create(['name' => sprintf('Nguyễn %03d Mỹ', $i)]);
                grantRole($member, 'editor', ['entries.view']);
                grantRole($member, 'author', ['entries.view']);
            }
        });
    };
    $read = function () use ($admin): int {
        $boundary = app(Tenancy::class)->enter(1, fn () => User::query()->where('name', 'Nguyễn 020 Mỹ')->value('ulid'));
        $url = 'http://a.test/admin/users?q=nguyen&nhom[0]=editor&after='.$boundary;
        $this->actingAs($admin)->get($url)->assertOk(); // the permission and settings caches fill on the first request

        return countQueries(fn () => $this->actingAs($admin)->get($url)->assertOk()->assertSee('Nguyễn 021 Mỹ'));
    };

    $seed(1, 45);
    $few = $read();
    $seed(46, 300);
    $many = $read();

    // 20 rows on the page either way: the site's membership growing more than six-fold adds no query.
    // 16 for the list + the "Lời mời" tab label (up to 99 + 1 rows) + the mails of the last 24 hours (an inviter's near-limit note)
    // + 1: the top bar's bell reads the unread count
    expect($many)->toBe($few)->and($few)->toBe(19);
});

it('keeps the invitations view flat whatever the number of invitations', function (): void {
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs restart identity cascade');
    $admin = User::factory()->create(['name' => 'Ánh Quản Trị']);
    $seed = function (int $from, int $to) use ($admin): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to, $admin): void {
            grantRole($admin, 'admin', ['users.manage', 'users.invite', 'entries.view']);
            grantRole(User::factory()->create(), 'editor', ['entries.view']);
            $editor = Role::findByName('editor', PermissionRegistry::GUARD);
            foreach (range($from, $to) as $i) {
                $invitation = Invitation::query()->forceCreate([
                    'email' => sprintf('moi-%03d@example.test', $i), 'token_hash' => hash('sha256', 'moi-'.$i),
                    'invited_by' => $admin->id, 'expires_at' => now()->addDays(5), 'sent_at' => now()->subMinutes($i),
                    'created_at' => now()->subMinutes($i),
                ]);
                $invitation->roles()->syncWithPivotValues([$editor->id], ['site_id' => 1]);
            }
        });
    };
    $read = function () use ($admin): int {
        $url = 'http://a.test/admin/users?xem=loi-moi&q=moi';
        $this->actingAs($admin)->get($url)->assertOk(); // the permission and settings caches fill on the first request

        return countQueries(fn () => $this->actingAs($admin)->get($url)->assertOk()->assertSee('moi-001@'));
    };

    $seed(1, 30);
    $few = $read();
    $seed(31, 200);
    $many = $read();

    // the counts per state, the role filter's counts, one keyset page with its roles and inviters, and
    // the inviters still on the site, and the top bar's bell: 170 more invitations add no query
    expect($many)->toBe($few)->and($few)->toBe(20);
});

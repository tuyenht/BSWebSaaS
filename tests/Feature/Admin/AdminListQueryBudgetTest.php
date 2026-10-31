<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

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

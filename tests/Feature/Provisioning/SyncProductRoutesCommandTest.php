<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * `catalog:sync-routes` repairs the public address of products saved before the editor wrote
 * routes: a published product with no route gets one, a route left at an old slug moves (with a
 * 301), a path another page owns is reported, drafts are left alone. Report-only unless --apply.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A product on the current site with a vi translation; optionally a route stored at $routePath. */
function catalogProduct(string $slug, string $status = 'published', ?string $routePath = null): Product
{
    $product = Product::query()->create(['status' => $status]);
    $product->translations()->create(['locale' => 'vi', 'name' => 'SP '.$slug, 'slug' => $slug]);

    if ($routePath !== null) {
        SiteRoute::query()->create(['locale' => 'vi', 'path' => $routePath, 'product_id' => $product->id]);
    }

    return $product;
}

function storedProductPath(Product $product): ?string
{
    $path = SiteRoute::query()->where('product_id', $product->id)->where('locale', 'vi')->value('path');

    return is_string($path) ? $path : null;
}

it('reports missing and stale addresses without writing anything', function (): void {
    [$missing, $stale] = app(Tenancy::class)->enter(1, fn (): array => [
        catalogProduct('jig-a'),
        catalogProduct('ten-moi', routePath: '/ten-cu'),
    ]);

    $this->artisan('catalog:sync-routes')
        ->expectsOutputToContain('Report only')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($missing, $stale): void {
        expect(storedProductPath($missing))->toBeNull()
            ->and(storedProductPath($stale))->toBe('/ten-cu');
    });
});

it('creates missing addresses and moves stale ones with a 301, on every site, then has nothing to do', function (): void {
    [$missing, $stale, $draft, $fine] = app(Tenancy::class)->enter(1, fn (): array => [
        catalogProduct('jig-a'),
        catalogProduct('ten-moi', routePath: '/ten-cu'),
        catalogProduct('ban-nhap', status: 'draft'),
        catalogProduct('da-dung', routePath: '/da-dung'),
    ]);
    $otherSite = app(Tenancy::class)->enter(2, fn (): Product => catalogProduct('jig-b'));

    $this->artisan('catalog:sync-routes', ['--apply' => true])->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($missing, $stale, $draft, $fine): void {
        expect(storedProductPath($missing))->toBe('/jig-a')
            ->and(storedProductPath($stale))->toBe('/ten-moi')
            ->and(Redirect::query()->where('from_path', '/ten-cu')->where('to_path', '/ten-moi')->where('status_code', 301)->exists())->toBeTrue()
            ->and(storedProductPath($draft))->toBeNull()
            ->and(storedProductPath($fine))->toBe('/da-dung');
    });
    app(Tenancy::class)->enter(2, fn () => expect(storedProductPath($otherSite))->toBe('/jig-b'));

    $this->artisan('catalog:sync-routes')->expectsOutputToContain('nothing to do')->assertSuccessful();
});

it('reports a path another page owns and still repairs the rest', function (): void {
    [$clash, $missing] = app(Tenancy::class)->enter(1, function (): array {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => User::factory()->create()->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh(), 'vi', 'lien-he');

        return [catalogProduct('lien-he'), catalogProduct('jig-c')];
    });

    $this->artisan('catalog:sync-routes', ['--apply' => true])
        ->expectsOutputToContain('conflict')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($clash, $missing): void {
        expect(storedProductPath($clash))->toBeNull()
            ->and(storedProductPath($missing))->toBe('/jig-c');
    });
});

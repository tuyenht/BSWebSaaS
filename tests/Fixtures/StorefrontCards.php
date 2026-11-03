<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Media\Models\Media;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Storefront content for the block, card-list and pagination tests: a provisioned site, media rows,
 * categories with stored routes and ltree paths, posts and products. Every write but site() must run
 * inside the site's tenant context (Tenancy::enter).
 */
final class StorefrontCards
{
    /** Wipe every tenant row (sites cascade) and the global authz tables. */
    public static function reset(): void
    {
        DB::connection('pgsql_admin')->statement('truncate tenant_probes, domains, sites, tenants, tenant_users, users restart identity cascade');
        truncateRbac();
    }

    /** Provision a site the way a customer gets one (home, /lien-he with its form, /tin-tuc). */
    public static function site(string $name = 'Xưởng Mẫu', string $host = 'mau.platform.test'): int
    {
        app(ProvisionSite::class)->handle(new ProvisionSiteData(
            tenantName: $name, siteName: $name, hostname: $host,
            ownerEmail: 'owner@'.$host, ownerName: 'Owner', ownerPassword: 'a-strong-password',
        ));

        return (int) Site::query()->where('name', $name)->value('id');
    }

    /** Media URLs that do not depend on the machine's .env. */
    public static function pinMediaConfig(string $driver = 'cloudflare'): void
    {
        config(['media.driver' => $driver, 'media.cloudflare.base' => 'https://files.test']);
    }

    public static function media(string $slug, int $width = 1600, int $height = 900): Media
    {
        $hash = hash('sha256', $slug);

        return Media::query()->create([
            'collection' => 'library', 'slug' => $slug, 'hash' => $hash,
            'storage_key' => 's/library/'.$slug.'-'.substr($hash, 0, 12).'.webp',
            'mime' => 'image/webp', 'size' => 50_000, 'width' => $width, 'height' => $height,
        ]);
    }

    /** The news category every provisioned site gets (/tin-tuc). */
    public static function news(): Taxonomy
    {
        $id = TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'tin-tuc')->value('taxonomy_id');

        return Taxonomy::query()->findOrFail($id);
    }

    /** A category with a translation, an ltree path and (unless $routed is false) its flat route. */
    public static function category(string $name, string $slug, ?Taxonomy $parent = null, ?string $listTemplate = null, int $position = 0, bool $routed = true): Taxonomy
    {
        $category = Taxonomy::query()->create([
            'vocabulary' => 'post_cat', 'parent_id' => $parent?->id, 'list_template' => $listTemplate, 'position' => $position,
        ]);
        app(TaxonomyPaths::class)->refreshBranch($category->id);
        $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
        if ($routed) {
            app(RouteSync::class)->syncTaxonomy($category, 'vi', $slug);
        }

        return $category->fresh() ?? $category;
    }

    /** Bind a category as the catalog section (its page lists products). */
    public static function catalog(Taxonomy $category): void
    {
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
    }

    /**
     * A published post whose primary category is $category, routed at /{category slug}/{slug}.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function post(Taxonomy $category, string $title, string $slug, ?CarbonImmutable $publishedAt = null, array $meta = [], string $excerpt = ''): Entry
    {
        $entry = Entry::query()->create([
            'type' => 'post', 'status' => 'published', 'published_at' => $publishedAt ?? CarbonImmutable::parse('2026-10-01 08:00:00', 'UTC'), 'meta' => $meta,
        ]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'published', 'excerpt' => $excerpt !== '' ? $excerpt : null,
            'body' => ['blocks' => []],
        ]);
        $entry->assignCategories([$category->id]);
        $prefix = (string) $category->translationFor('vi')?->slug;
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/'.$prefix.'/'.$slug, 'entry_id' => $entry->id]);

        return $entry;
    }

    /** A published product, optionally filed under $category and carrying $image as its first photo. */
    public static function product(string $name, string $slug, ?Taxonomy $category = null, ?Media $image = null, ?CarbonImmutable $publishedAt = null, string $summary = ''): Product
    {
        $product = Product::query()->create(['status' => 'published', 'published_at' => $publishedAt ?? CarbonImmutable::parse('2026-10-01 08:00:00', 'UTC')]);
        $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug, 'summary' => $summary !== '' ? $summary : null]);
        if ($category !== null) {
            $product->taxonomies()->attach($category->id, ['position' => 0, 'site_id' => $product->site_id]);
        }
        if ($image !== null) {
            $product->images()->create(['media_id' => $image->id, 'position' => 0]);
        }
        app(RouteSync::class)->syncProduct($product->id, null, 'vi', $slug);

        return $product;
    }

    /**
     * A published page at $path with a Page-JSON body.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function page(string $path, string $title, array $blocks): Entry
    {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => trim($path, '/') !== '' ? trim($path, '/') : 'home', 'status' => 'published',
            'body' => ['blocks' => $blocks],
        ]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);

        return $entry;
    }
}

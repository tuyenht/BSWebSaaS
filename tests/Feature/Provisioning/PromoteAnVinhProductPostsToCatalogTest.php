<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Provisioning\Actions\PromoteAnVinhProductPostsToCatalog;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;

/*
 * PromoteAnVinhProductPostsToCatalog: promotes "product-post" entries (posts filed under the
 * san-pham category, the shape SeedAnVinhContent seeds) to real catalog Product rows at the
 * IDENTICAL /san-pham/{slug} route — the F3 route-handover described in the product-migration
 * spec. Fixtures reproduce the pre-migration shape directly (a `products` section bound to a
 * `san-pham` post_cat taxonomy, posts pinned to the `category` permalink preset) rather than
 * invoking the full An Vinh seeder, so each test stays focused on the handover itself.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, redirects, routes, product_translations, product_images,
         product_taxonomies, products, entry_taxonomies, entry_translations, entries,
         taxonomy_translations, taxonomies, media, site_profile_translations, site_profiles,
         site_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users
         restart identity cascade'
    );
    truncateRbac();
});

/**
 * Provision an An-Vinh-shaped site, seed a `products` section bound to a `san-pham` (post_cat)
 * taxonomy with one `product_cat` sub-category, and $count product-posts filed under
 * [san-pham, subCat] — the exact shape SeedAnVinhContent::seedProducts() produces. Posts are
 * pinned to the PRE-migration `category` permalink preset, so each one's live route is already
 * /san-pham/{slug}: precisely what the migrated Product's route must also resolve to.
 *
 * @return array{site: Site, slugs: list<string>}
 */
function seedAnVinhProductPosts(string $hostname, int $count = 2, string $status = 'published'): array
{
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: $hostname, siteName: $hostname, hostname: $hostname,
        ownerEmail: 'owner@'.$hostname, ownerName: 'Owner', ownerPassword: 'a-strong-password',
    ));

    $site = Site::query()->where('name', $hostname)->firstOrFail();

    $slugs = app(Tenancy::class)->enter($site->id, function () use ($count, $status): array {
        SiteSetting::query()->updateOrCreate(
            ['group_name' => 'permalinks', 'key' => 'products'],
            ['value' => ['pattern' => 'category']],
        );

        $sanPham = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $sanPham->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create([
            'key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $sanPham->id,
        ]);

        $subCat = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $sanPham->id, 'position' => 0]);
        $subCat->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);

        $slugs = [];
        for ($i = 1; $i <= $count; $i++) {
            $slug = "jig-kiem-{$i}";
            $slugs[] = $slug;

            $entry = Entry::query()->create([
                'type' => 'post',
                'status' => $status,
                'published_at' => $status === 'published' ? now() : null,
                'meta' => ['image_url' => "an-vinh/san-pham/sp-jig-{$i}-abc123def456.jpg"],
            ]);

            $entry->translations()->create([
                'locale' => 'vi',
                'title' => "Jig kiểm số {$i}",
                'slug' => $slug,
                'status' => $status,
                'excerpt' => "Đồ gá kiểm tra số {$i}, đảm bảo độ chính xác.",
                'seo_description' => "Đồ gá kiểm tra số {$i}.",
                'body' => ['blocks' => [
                    ['type' => 'richtext', 'props' => ['heading' => "Jig kiểm số {$i}", 'paragraphs' => ["Đồ gá kiểm tra số {$i}, đảm bảo độ chính xác."]]],
                    ['type' => 'richtext', 'props' => ['heading' => 'Thông số kỹ thuật', 'paragraphs' => ['Vật liệu: Thép SKD11', 'Độ chính xác: ±0.05mm']]],
                ]],
            ]);

            $entry->assignCategories([$sanPham->id, $subCat->id]);

            app(RouteSync::class)->sync($entry, 'vi', $slug);
        }

        return $slugs;
    });

    // In real usage, seeding the pre-migration state and running the migration are two SEPARATE
    // `php artisan` process invocations, so PermalinkConfig's per-request memo (scoped; flushed by
    // Octane between operations, and naturally empty at the start of a new process) never straddles
    // the two. Forcing that same flush here keeps this in-process fixture+action test honest: the
    // action's own pin write must be what the next read observes, not a stale in-memory 'category'
    // cached from this fixture's own RouteSync::sync() call above.
    app()->forgetScopedInstances();

    return ['site' => $site, 'slugs' => $slugs];
}

it('promotes product-posts to Product rows at the identical route, with no redirect', function (): void {
    ['site' => $site, 'slugs' => $slugs] = seedAnVinhProductPosts('anvinh-promo-a.platform.test', 2);

    $summary = app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    expect($summary)->toHaveCount(2);
    foreach ($summary as $row) {
        expect($row['action'])->toBe('created');
        expect($row['productUrl'])->toStartWith('/san-pham/');
    }

    app(Tenancy::class)->enter($site->id, function () use ($slugs): void {
        expect(Redirect::query()->count())->toBe(0); // same path throughout — no 301 history

        foreach ($slugs as $slug) {
            $route = SiteRoute::query()->where('path', "/san-pham/{$slug}")->where('locale', 'vi')->first();
            expect($route)->not->toBeNull();
            expect($route->product_id)->not->toBeNull();
            expect($route->entry_id)->toBeNull();

            $translation = ProductTranslation::query()->where('slug', $slug)->first();
            expect($translation)->not->toBeNull();
            expect($translation->name)->toBe(str_replace('jig-kiem-', 'Jig kiểm số ', $slug));
            expect($translation->description)->toContain('Thông số kỹ thuật')->toContain('SKD11');

            $product = Product::query()->findOrFail($translation->product_id);
            expect($product->status)->toBe('published');
            expect($product->published_at)->not->toBeNull();
            expect($product->images()->count())->toBe(1);
            expect($product->taxonomies()->count())->toBe(1); // do-ga only — san-pham is not a product_cat term

            // The old post is retired: trashed (recoverable), route gone.
            $entry = Entry::withTrashed()->whereHas('translations', fn ($q) => $q->where('slug', $slug))->first();
            expect($entry)->not->toBeNull();
            expect($entry->deleted_at)->not->toBeNull();
            expect(SiteRoute::query()->where('entry_id', $entry->id)->count())->toBe(0);
        }
    });

    // End-to-end proof the URL truly still resolves — same path, now serving the product.
    $html = $this->get("http://anvinh-promo-a.platform.test/san-pham/{$slugs[0]}")->assertOk()->getContent();
    expect($html)->toContain('Jig kiểm số 1');
});

it('is idempotent — a second run creates no duplicates', function (): void {
    ['site' => $site] = seedAnVinhProductPosts('anvinh-promo-b.platform.test', 2);

    app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());
    $secondSummary = app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    // No source posts remain (all trashed by the first run) — the natural idempotency net.
    expect($secondSummary)->toBe([]);

    app(Tenancy::class)->enter($site->id, function (): void {
        expect(Product::query()->count())->toBe(2);
        expect(ProductTranslation::query()->count())->toBe(2);
        expect(SiteRoute::query()->where('path', 'like', '/san-pham/%')->count())->toBe(2);
    });
});

it('updates an existing Product by slug instead of duplicating it', function (): void {
    ['site' => $site, 'slugs' => $slugs] = seedAnVinhProductPosts('anvinh-promo-c.platform.test', 1);
    $slug = $slugs[0];

    // Simulate a Product that already exists at the target slug (e.g. a prior partial run).
    app(Tenancy::class)->enter($site->id, function () use ($slug): void {
        $product = Product::query()->create(['status' => 'draft']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'OLD NAME', 'slug' => $slug]);
    });

    $summary = app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    expect($summary)->toHaveCount(1);
    expect($summary[0]['action'])->toBe('updated');

    app(Tenancy::class)->enter($site->id, function () use ($slug): void {
        expect(Product::query()->count())->toBe(1); // updated in place, never duplicated
        $translation = ProductTranslation::query()->where('slug', $slug)->firstOrFail();
        expect($translation->name)->not->toBe('OLD NAME');
        expect($translation->name)->toBe('Jig kiểm số 1');
    });
});

it('dry-run computes the summary but writes nothing', function (): void {
    ['site' => $site, 'slugs' => $slugs] = seedAnVinhProductPosts('anvinh-promo-d.platform.test', 2);

    $summary = app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle(dryRun: true));

    expect($summary)->toHaveCount(2);
    foreach ($summary as $row) {
        expect($row['action'])->toBe('would_create');
        expect($row['productUrl'])->toStartWith('/san-pham/');
    }

    app(Tenancy::class)->enter($site->id, function () use ($slugs): void {
        expect(Product::query()->count())->toBe(0);
        expect(ProductTranslation::query()->count())->toBe(0);
        expect(Entry::query()->where('type', 'post')->count())->toBe(2); // still live, not trashed
        expect(Entry::withTrashed()->where('type', 'post')->whereNotNull('deleted_at')->count())->toBe(0);

        foreach ($slugs as $slug) {
            $route = SiteRoute::query()->where('path', "/san-pham/{$slug}")->first();
            expect($route?->entry_id)->not->toBeNull();
            expect($route?->product_id)->toBeNull();
        }

        // Even the permalink pin write was rolled back — proves a truly zero-write preview.
        $pattern = SiteSetting::query()->where('group_name', 'permalinks')->where('key', 'products')->value('value');
        expect($pattern['pattern'] ?? null)->toBe('category');
    });
});

it('leaves the promoted post recoverable in Trash (restorable, not purged)', function (): void {
    ['site' => $site, 'slugs' => $slugs] = seedAnVinhProductPosts('anvinh-promo-e.platform.test', 1);
    $slug = $slugs[0];

    app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    app(Tenancy::class)->enter($site->id, function () use ($slug): void {
        $entry = Entry::withTrashed()->whereHas('translations', fn ($q) => $q->where('slug', $slug))->firstOrFail();
        expect($entry->deleted_at)->not->toBeNull();

        $entry->restore();

        expect($entry->fresh()->deleted_at)->toBeNull();
        expect($entry->translationFor('vi')->title)->toBe('Jig kiểm số 1');
        expect($entry->translationFor('vi')->body['blocks'] ?? [])->not->toBe([]);
    });
});

it('maps entry status/published_at onto the product 1:1 for a draft post', function (): void {
    ['site' => $site] = seedAnVinhProductPosts('anvinh-promo-f.platform.test', 1, status: 'draft');

    app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    app(Tenancy::class)->enter($site->id, function (): void {
        $product = Product::query()->firstOrFail();
        expect($product->status)->toBe('draft');
        expect($product->published_at)->toBeNull();
    });
});

it('the console command previews with --dry-run then applies for real, idempotently', function (): void {
    ['site' => $site] = seedAnVinhProductPosts('anvinh-promo-cmd.platform.test', 1);

    $this->artisan('anvinh:promote-products', ['--host' => 'anvinh-promo-cmd.platform.test', '--dry-run' => true])
        ->assertSuccessful();

    app(Tenancy::class)->enter($site->id, function (): void {
        expect(Product::query()->count())->toBe(0);
    });

    $this->artisan('anvinh:promote-products', ['--host' => 'anvinh-promo-cmd.platform.test'])
        ->assertSuccessful();

    app(Tenancy::class)->enter($site->id, function (): void {
        expect(Product::query()->count())->toBe(1);
    });

    // Re-run is a safe no-op — no duplicates, command still succeeds.
    $this->artisan('anvinh:promote-products', ['--host' => 'anvinh-promo-cmd.platform.test'])
        ->assertSuccessful();

    app(Tenancy::class)->enter($site->id, function (): void {
        expect(Product::query()->count())->toBe(1);
    });
});

it('the console command fails cleanly for an unknown host', function (): void {
    $this->artisan('anvinh:promote-products', ['--host' => 'does-not-exist.test'])
        ->assertFailed();
});

it('records a 301 when a source post lived at a different path than the product', function (): void {
    ['site' => $site, 'slugs' => $slugs] = seedAnVinhProductPosts('anvinh-promo-g.platform.test', 1);
    $slug = $slugs[0];

    // A tenant whose product-posts were NOT category-pinned (flat-era history): the live route
    // sits at /{slug}, so the promoted product's /san-pham/{slug} is a genuine URL change.
    app(Tenancy::class)->enter($site->id, function () use ($slug): void {
        SiteRoute::query()->where('path', "/san-pham/{$slug}")->update(['path' => "/{$slug}"]);
    });
    app()->forgetScopedInstances();

    app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    app(Tenancy::class)->enter($site->id, function () use ($slug): void {
        // The old flat path bridges to the product's new home — never a silent 404.
        $redirect = Redirect::query()->where('from_path', "/{$slug}")->first();
        expect($redirect)->not->toBeNull();
        expect($redirect->to_path)->toBe("/san-pham/{$slug}");
        expect($redirect->status_code)->toBe(301);

        // The product route itself is live at the new path.
        $route = SiteRoute::query()->where('path', "/san-pham/{$slug}")->firstOrFail();
        expect($route->product_id)->not->toBeNull();
    });

    // End-to-end: the old URL 301s to the product, which serves 200.
    $this->get("http://anvinh-promo-g.platform.test/{$slug}")
        ->assertStatus(301)
        ->assertRedirect("http://anvinh-promo-g.platform.test/san-pham/{$slug}");
    $this->get("http://anvinh-promo-g.platform.test/san-pham/{$slug}")->assertOk();
});

it('rolls the whole run back at a lock conflict instead of reporting it as one failed post', function (): void {
    // A lock conflict in a post's savepoint leaves the caller's transaction aborted (Laravel
    // rethrows it without rolling the savepoint back): the next posts could not run, and COMMIT
    // would silently undo the ones listed as promoted. A trigger raises the real SQLSTATE.
    ['site' => $site, 'slugs' => $slugs] = seedAnVinhProductPosts('anvinh-promo-conflict.platform.test', 2);

    $admin = DB::connection('pgsql_admin');
    $admin->unprepared(<<<'SQL'
        create or replace function test_promote_lock_conflict() returns trigger language plpgsql as $fn$
        begin
            raise exception 'could not serialize access due to concurrent update' using errcode = '40001';
        end
        $fn$;
        drop trigger if exists test_promote_lock_conflict on products;
        create trigger test_promote_lock_conflict before insert on products
            for each row execute function test_promote_lock_conflict();
        SQL);

    try {
        expect(fn () => app(Tenancy::class)->enter($site->id, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle()))
            ->toThrow(DeadlockException::class);
    } finally {
        $admin->unprepared('drop trigger if exists test_promote_lock_conflict on products; drop function if exists test_promote_lock_conflict();');
    }

    app(Tenancy::class)->enter($site->id, function () use ($slugs): void {
        expect(Product::query()->count())->toBe(0);
        foreach ($slugs as $slug) {
            expect(SiteRoute::query()->where('path', "/san-pham/{$slug}")->value('entry_id'))->not->toBeNull();
        }
    });
});

<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Admin\Support\AuditCatalog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductTrash;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The nightly emptying of each website's product Trash: products left there longer than
 * ProductTrash::DAYS are deleted for good with everything the manual "Xóa vĩnh viễn" removes; live
 * products and other websites' Trash are untouched. The list words each Trash row by the days left.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'catalog', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** A product in the current site, in the Trash since $trashedAt (null: live). */
function purgeProduct(string $name, string $slug, ?Carbon $trashedAt, ?string $sku = null, ?Taxonomy $category = null): Product
{
    $product = Product::query()->create(['status' => 'draft', 'sku' => $sku]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
    if ($category !== null) {
        $product->assignCategories([$category->id]);
    }
    if ($trashedAt !== null) {
        $product->delete();
        Product::withTrashed()->whereKey($product->id)->toBase()->update(['deleted_at' => $trashedAt->utc()->toDateTimeString().'+00']);
    }

    return $product;
}

it('deletes only products trashed over the retention window, in every website', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $kept = [];
    $gone = [];
    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, function () use ($site, &$kept, &$gone): void {
            $gone[$site] = purgeProduct('Đã quá hạn', 'qua-han', now()->subDays(30)->subHour())->id;
            $kept[$site] = [
                purgeProduct('Sắp tới hạn', 'sap-toi-han', now()->subDays(30)->addHour())->id,
                purgeProduct('Còn mới', 'con-moi', now()->subDay())->id,
                purgeProduct('Đang bán', 'dang-ban', null)->id,
            ];
        });
    }

    $this->artisan('products:purge-trash')->expectsOutputToContain('Purged 2 product(s)')->assertSuccessful();

    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, function () use ($site, $kept, $gone): void {
            expect(Product::withTrashed()->pluck('id')->sort()->values()->all())->toBe(collect($kept[$site])->sort()->values()->all())
                ->and(Product::withTrashed()->whereKey($gone[$site])->exists())->toBeFalse();
        });
    }
});

it('removes what a manual permanent delete removes and frees the sku and the address', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $id = app(Tenancy::class)->enter(1, fn (): int => purgeProduct('Đồ gá cũ', 'do-ga-cu', now()->subDays(31), 'DG-009')->id);

    $this->artisan('products:purge-trash')->assertSuccessful();

    app(Tenancy::class)->enter(1, function (): void {
        $again = purgeProduct('Đồ gá mới', 'do-ga-cu', null, 'DG-009');
        expect($again->exists)->toBeTrue();
    });
    $audit = DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'catalog.product.purged')->get();
    expect($audit)->toHaveCount(1)
        ->and((int) $audit[0]->site_id)->toBe(1)
        ->and(json_decode((string) $audit[0]->meta, true))->toMatchArray(['products' => 1, 'scheduled' => true, 'trash_days' => ProductTrash::DAYS]);
});

/** Give a product a gallery image, a category link and a synced address; returns the category. */
function purgeFill(Product $product, Taxonomy $category, string $tag): void
{
    $media = Media::query()->create([
        'collection' => 'library', 'slug' => "anh-{$tag}", 'hash' => hash('sha256', "img-{$tag}"),
        'storage_key' => "s/library/anh-{$tag}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ]);
    $product->images()->create(['media_id' => $media->id, 'position' => 0]);
    $product->assignCategories([$category->id]);
    app(RouteSync::class)->syncProduct($product->id, $category, 'vi', "dia-chi-{$tag}");
}

/** @return array<string, int> row counts of one product's dependants (run inside Tenancy::enter) */
function purgeRows(int $productId): array
{
    return [
        'translations' => DB::table('product_translations')->where('product_id', $productId)->count(),
        'images' => DB::table('product_images')->where('product_id', $productId)->count(),
        'categories' => DB::table('product_taxonomies')->where('product_id', $productId)->count(),
        'routes' => SiteRoute::query()->where('product_id', $productId)->count(),
    ];
}

it('removes the translations, gallery, category links and address of a purged product only', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    [$goneId, $keptId] = app(Tenancy::class)->enter(1, function (): array {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => null, 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga-cat']);
        $gone = purgeProduct('Xóa lâu', 'xoa-lau', now()->subDays(40));
        $kept = purgeProduct('Mới xóa', 'moi-xoa', now()->subDays(2));
        purgeFill($gone, $category, 'a');
        purgeFill($kept, $category, 'b');

        return [$gone->id, $kept->id];
    });

    $this->artisan('products:purge-trash')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($goneId, $keptId): void {
        expect(Product::withTrashed()->whereKey($goneId)->exists())->toBeFalse()
            ->and(purgeRows($goneId))->toBe(['translations' => 0, 'images' => 0, 'categories' => 0, 'routes' => 0])
            ->and(Product::withTrashed()->whereKey($keptId)->exists())->toBeTrue()
            ->and(purgeRows($keptId))->toBe(['translations' => 1, 'images' => 1, 'categories' => 1, 'routes' => 1]);
    });
});

it('deletes every qualifying product of a site, more than one chunk of them', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    app(Tenancy::class)->enter(1, function (): void {
        $trashedAt = now()->subDays(40)->utc()->toDateTimeString().'+00';
        for ($i = 0; $i < 205; $i++) {
            Product::query()->create(['status' => 'draft', 'sku' => "SKU-{$i}"]);
        }
        Product::query()->toBase()->update(['deleted_at' => $trashedAt]);
        purgeProduct('Còn bán', 'con-ban-2', null);
    });

    $this->artisan('products:purge-trash')->expectsOutputToContain('Purged 205 product(s)')->assertSuccessful();

    app(Tenancy::class)->enter(1, function (): void {
        expect(Product::withTrashed()->count())->toBe(1);
    });
});

it('locks the rows it picks, so a product restored first is never deleted', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $id = app(Tenancy::class)->enter(1, fn (): int => purgeProduct('Khôi phục kịp', 'khoi-phuc-kip', now()->subDays(40))->id);
    app(Tenancy::class)->enter(1, fn () => Product::onlyTrashed()->whereKey($id)->first()->restore());

    DB::connection()->enableQueryLog();
    $this->artisan('products:purge-trash')->expectsOutputToContain('Purged 0 product(s)')->assertSuccessful();
    $selects = collect(DB::connection()->getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, '"products"') && str_starts_with(strtolower($q), 'select') && str_contains($q, 'deleted_at'));
    DB::connection()->disableQueryLog();

    expect($selects->contains(fn (string $q): bool => str_contains(strtolower($q), 'for update')))->toBeTrue();
    app(Tenancy::class)->enter(1, function () use ($id): void {
        expect(Product::query()->whereKey($id)->exists())->toBeTrue();
    });
});

it('does not purge products trashed before the grace date until it has run out', function (): void {
    $since = Carbon::parse(ProductTrash::SINCE, 'UTC');
    $this->travelTo($since->copy()->addHours(2));
    $old = app(Tenancy::class)->enter(1, fn (): int => purgeProduct('Xóa từ lâu', 'xoa-tu-lau', $since->copy()->subDays(60))->id);

    $this->artisan('products:purge-trash')->expectsOutputToContain('Purged 0 product(s)')->assertSuccessful();
    $this->travelTo($since->copy()->addDays(30)->subHour());
    $this->artisan('products:purge-trash')->expectsOutputToContain('Purged 0 product(s)')->assertSuccessful();
    app(Tenancy::class)->enter(1, function () use ($old): void {
        expect(Product::onlyTrashed()->whereKey($old)->exists())->toBeTrue();
    });

    $this->travelTo($since->copy()->addDays(30)->addHour());
    $this->artisan('products:purge-trash')->expectsOutputToContain('Purged 1 product(s)')->assertSuccessful();
    app(Tenancy::class)->enter(1, function () use ($old): void {
        expect(Product::withTrashed()->whereKey($old)->exists())->toBeFalse();
    });
});

it('counts the days left from the grace date for what was already in the Trash', function (): void {
    $since = Carbon::parse(ProductTrash::SINCE, 'UTC');

    expect(ProductTrash::daysLeft($since->copy()->subDays(60), $since->copy()))->toBe(30)
        ->and(ProductTrash::daysLeft($since->copy()->subDays(60), $since->copy()->addDay()))->toBe(29)
        ->and(ProductTrash::daysLeft($since->copy()->addDay(), $since->copy()->addDays(2)))->toBe(29)
        ->and(ProductTrash::daysLeft($since->copy()->addDay(), $since->copy()->addDays(31)))->toBe(0);
});

it('shows "30 days left" on the grace date for a product trashed long before it', function (): void {
    $this->travelTo(Carbon::parse(ProductTrash::SINCE, 'UTC')->addHour());
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        purgeProduct('Xóa từ lâu', 'xoa-tu-lau', now()->subDays(60));

        $html = Livewire::actingAs($manager->fresh())->test(Index::class)->set('tab', 'trash')->html();

        expect($html)->toContain(__('catalog.trash_days_left', ['n' => 30]));
    });
});

it('words the retention note with the constant, word for word as designed in Vietnamese', function (): void {
    app()->setLocale('vi');

    expect(__('catalog.trash_auto_note', ['days' => ProductTrash::DAYS]))
        ->toBe('Mục trong Thùng rác tự xóa hẳn sau 30 ngày. Khôi phục để đưa về Bản nháp — mã SKU và đường dẫn vẫn giữ nguyên.');
});

it('refuses to delete a live product for good, one row at a time', function (): void {
    $manager = User::factory()->create();
    $ulid = app(Tenancy::class)->enter(1, function () use ($manager): string {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);

        return purgeProduct('Đang bán', 'dang-ban-2', null)->ulid;
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $ulid): void {
        Livewire::actingAs($manager->fresh())->test(Index::class)->call('forceDelete', $ulid)->assertStatus(404);
        expect(Product::query()->where('ulid', $ulid)->exists())->toBeTrue();
    });
});

it('lists the purge in an audit group with a sentence in every language', function (): void {
    $catalog = new AuditCatalog;

    expect($catalog->groupOf('catalog.product.purged'))->not->toBeNull()
        ->and($catalog->label('catalog.product.purged'))->not->toStartWith('admin.audit.act.');
});

it('leaves the category totals right after a purge', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $categoryId = app(Tenancy::class)->enter(1, function (): int {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => null, 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga-cat']);
        purgeProduct('Còn bán', 'con-ban', null, null, $category);
        purgeProduct('Đã xóa lâu', 'xoa-lau', now()->subDays(40), null, $category);

        return $category->id;
    });

    $this->artisan('products:purge-trash')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($categoryId): void {
        expect((int) Taxonomy::query()->whereKey($categoryId)->value('items_count'))->toBe(1);
    });
});

it('words a Trash row by the days left, never below zero', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        purgeProduct('Vừa xóa', 'vua-xoa', now()->subHours(2));
        purgeProduct('Xóa hôm qua', 'xoa-hom-qua', now()->subDay()->subHour());
        purgeProduct('Quá hạn chờ dọn', 'qua-han-cho', now()->subDays(45));
        purgeProduct('Sắp tới hạn', 'sap-toi-han-2', now()->subDays(29)->subHours(23));

        $html = Livewire::actingAs($manager->fresh())->test(Index::class)->set('tab', 'trash')->html();

        expect($html)->toContain(__('catalog.trash_days_left', ['n' => 30]))
            ->and($html)->toContain(__('catalog.trash_days_left', ['n' => 29]))
            ->and($html)->toContain(__('catalog.trash_days_left', ['n' => 1]))
            ->and($html)->toContain(__('catalog.trash_days_left', ['n' => 0]))
            ->and($html)->toContain(__('catalog.trash_auto_note', ['days' => ProductTrash::DAYS]));
    });
});

it('counts the days left as the design does', function (): void {
    $now = Carbon::parse('2027-01-15 10:00:00', 'UTC');

    expect(ProductTrash::daysLeft($now->copy(), $now))->toBe(30)
        ->and(ProductTrash::daysLeft($now->copy()->subDays(29)->subHours(23), $now))->toBe(1)
        ->and(ProductTrash::daysLeft($now->copy()->subDay(), $now))->toBe(29)
        ->and(ProductTrash::daysLeft($now->copy()->subDays(30), $now))->toBe(0)
        ->and(ProductTrash::daysLeft($now->copy()->subDays(99), $now))->toBe(0);
});

it('is scheduled daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'products:purge-trash'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

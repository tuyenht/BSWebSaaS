<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductTrash;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
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
    $this->travelTo(Carbon::parse('2026-11-08 10:00:00', 'UTC'));
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
    $this->travelTo(Carbon::parse('2026-11-08 10:00:00', 'UTC'));
    $id = app(Tenancy::class)->enter(1, fn (): int => purgeProduct('Đồ gá cũ', 'do-ga-cu', now()->subDays(31), 'DG-009')->id);

    $this->artisan('products:purge-trash')->assertSuccessful();

    expect(DB::table('product_translations')->where('product_id', $id)->count())->toBe(0)
        ->and(DB::table('product_images')->where('product_id', $id)->count())->toBe(0)
        ->and(DB::table('product_taxonomies')->where('product_id', $id)->count())->toBe(0);
    app(Tenancy::class)->enter(1, function (): void {
        $again = purgeProduct('Đồ gá mới', 'do-ga-cu', null, 'DG-009');
        expect($again->exists)->toBeTrue();
    });
    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'catalog.product.purged')->count())->toBe(1);
});

it('leaves the category totals right after a purge', function (): void {
    $this->travelTo(Carbon::parse('2026-11-08 10:00:00', 'UTC'));
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
    $this->travelTo(Carbon::parse('2026-11-08 10:00:00', 'UTC'));
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        purgeProduct('Vừa xóa', 'vua-xoa', now()->subHours(2));
        purgeProduct('Xóa hôm qua', 'xoa-hom-qua', now()->subDay()->subHour());
        purgeProduct('Quá hạn chờ dọn', 'qua-han-cho', now()->subDays(45));

        $html = Livewire::actingAs($manager->fresh())->test(Index::class)->set('tab', 'trash')->html();

        expect($html)->toContain(__('catalog.trash_days_left', ['n' => 30]))
            ->and($html)->toContain(__('catalog.trash_days_left', ['n' => 29]))
            ->and($html)->toContain(__('catalog.trash_days_left', ['n' => 0]))
            ->and($html)->toContain(__('catalog.trash_auto_note'));
    });
});

it('counts the days left as the design does', function (): void {
    $now = Carbon::parse('2026-11-08 10:00:00', 'UTC');

    expect(ProductTrash::daysLeft($now->copy(), $now))->toBe(30)
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

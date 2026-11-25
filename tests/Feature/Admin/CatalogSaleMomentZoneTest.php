<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * A sale moment typed in the website's clock (UTC+7) in the evening hours, where the local day is
 * one day ahead of UTC, is stored as the same instant: 23:30 in Hà Nội is 16:30 UTC the same day.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    enableCatalog();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

it('stores a sale start and end typed between 17:00 and 23:59 site time as the same instant in UTC', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));
    $user = $user->fresh();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = Product::query()->create(['status' => 'draft']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá hàn', 'slug' => 'do-ga-han']);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('price', '1250000')
            ->set('salePrice', '1090000')
            ->set('saleStartsAt', '2026-12-01T17:00')
            ->set('saleEndsAt', '2026-12-01T23:30')
            ->call('save')
            ->assertHasNoErrors();

        $row = DB::selectOne(
            "select to_char(sale_starts_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as starts, to_char(sale_ends_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as ends from products where id = ?",
            [$product->id],
        );

        expect($row->starts)->toBe('2026-12-01 10:00')
            ->and($row->ends)->toBe('2026-12-01 16:30');
    });
});

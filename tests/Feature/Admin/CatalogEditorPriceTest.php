<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Actions\FlagProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The product editor's price card (board 09): the price, the stock, the sale price with its start and
 * end, and the labels «Nổi bật» / «Bán chạy». Amounts are whole đồng typed the Vietnamese way and read
 * strictly; each field is checked when it is left and again on save, and a save a field stops says
 * how many need fixing. The labels are written by the list's own action. A website that only presents
 * its products folds the card behind a note.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    enableCatalog();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');   // the words checked below are the Vietnamese ones
});

function priceManager(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'cat-manager', ['catalog.view', 'catalog.manage']));

    return $user->fresh();
}

/** A draft product of the current site with its vi translation and the given columns. */
function priceProduct(array $columns = [], string $name = 'Đồ gá hàn', string $slug = 'do-ga-han'): Product
{
    $product = Product::query()->create($columns + ['status' => 'draft']);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $product->fresh();
}

function sellingSite(): void
{
    SiteSetting::query()->updateOrCreate(['group_name' => 'catalog', 'key' => 'mode'], ['value' => ['value' => 'commerce']]);
}

it('stores the price, the sale with its times and the stock as typed the Vietnamese way, in whole đồng and UTC', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('price', '1.250.000 ₫')
            ->assertSet('price', '1.250.000')
            ->set('salePrice', '1090000')
            ->assertSet('salePrice', '1.090.000')
            ->set('saleStartsAt', '2026-11-20T08:00')
            ->set('saleEndsAt', '2026-11-30T20:30')
            ->set('stock', '24')
            ->call('save')
            ->assertHasNoErrors();

        $stored = $product->fresh();
        expect($stored->price)->toBe(1250000)
            ->and($stored->sale_price)->toBe(1090000)
            ->and($stored->stock)->toBe(24)
            // the website's clock is Vietnam's (UTC+7)
            ->and($stored->sale_starts_at?->utc()->toIso8601String())->toBe('2026-11-20T01:00:00+00:00')
            ->and($stored->sale_ends_at?->utc()->toIso8601String())->toBe('2026-11-30T13:30:00+00:00');

        // opened again, the fields read as they were typed
        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('price', '1.250.000')
            ->assertSet('salePrice', '1.090.000')
            ->assertSet('saleStartsAt', '2026-11-20T08:00')
            ->assertSet('saleEndsAt', '2026-11-30T20:30')
            ->assertSet('stock', '24');
    });
});

it('stores blank amounts, dates and stock as NULL, and 0 as sold out', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 500000, 'sale_price' => 400000, 'stock' => 3, 'sale_ends_at' => now()->addDay()]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('salePrice', '')
            ->set('saleEndsAt', '')
            ->set('price', '')
            ->set('stock', '0')
            ->call('save')
            ->assertHasNoErrors();

        $stored = $product->fresh();
        expect($stored->price)->toBeNull()
            ->and($stored->sale_price)->toBeNull()
            ->and($stored->sale_ends_at)->toBeNull()
            ->and($stored->stock)->toBe(0);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])->set('stock', '')->call('save')->assertHasNoErrors();
        expect($product->fresh()->stock)->toBeNull();
    });
});

it('refuses an amount it cannot read as whole đồng, on leaving the field and on save, and stores nothing', function (string $typed, string $message): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user, $typed, $message): void {
        $product = priceProduct(['price' => 900000]);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('price', $typed)
            ->assertHasErrors(['price'])
            ->assertSee($message)
            ->assertSet('price', $typed); // kept as typed, for the person to fix

        $editor->call('save')->assertHasErrors(['price']);
        expect($product->fresh()->price)->toBe(900000);
    });
})->with([
    'a decimal part' => ['1.250.000,50', 'Nhập số tiền bằng chữ số, không có phần lẻ, ví dụ 1.250.000.'],
    'a comma decimal' => ['12,5', 'Nhập số tiền bằng chữ số, không có phần lẻ, ví dụ 1.250.000.'],
    'a letter' => ['12k', 'Nhập số tiền bằng chữ số, không có phần lẻ, ví dụ 1.250.000.'],
    'a minus sign' => ['-5000', 'Nhập số tiền bằng chữ số, không có phần lẻ, ví dụ 1.250.000.'],
    'more than 13 digits' => ['12.345.678.901.234.567', 'Số tiền tối đa 13 chữ số (9.999.999.999.999 ₫).'],
]);

it('wants a price before a sale price, said as soon as the sale price is left', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('salePrice', '900.000')
            ->assertHasErrors(['salePrice'])
            ->assertSee('Nhập giá bán trước, rồi mới đặt giá khuyến mãi.')
            ->call('save')
            ->assertHasErrors(['salePrice']);

        expect($product->fresh()->sale_price)->toBeNull();
    });
});

it('wants the sale price below the price, with an example, and clears the message once the price is raised', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 1250000]);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('salePrice', '1.250.000')
            ->assertHasErrors(['salePrice'])
            ->assertSee('Giá khuyến mãi cần thấp hơn giá bán (1.250.000 ₫). Ví dụ 1.125.000 ₫.');

        // the message stays while the field is still wrong
        $editor->call('save')->assertHasErrors(['salePrice']);
        expect($product->fresh()->sale_price)->toBeNull();

        $editor->set('price', '1.500.000')->assertHasNoErrors(['salePrice'])->call('save')->assertHasNoErrors();
        expect($product->fresh()->sale_price)->toBe(1250000);
    });
});

it('wants the sale to end after it starts, naming the start', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 1000000, 'sale_price' => 900000]);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('saleStartsAt', '2026-11-20T08:00')
            ->set('saleEndsAt', '2026-11-20T08:00')
            ->assertHasErrors(['saleEndsAt'])
            ->assertSee('Ngày kết thúc cần sau ngày bắt đầu (20/11/2026 08:00).');

        $editor->call('save')->assertHasErrors(['saleEndsAt']);
        // moving the start back fixes the end, checked again when the start is left
        $editor->set('saleStartsAt', '2026-11-19T08:00')->assertHasNoErrors(['saleEndsAt'])->call('save')->assertHasNoErrors();
        expect($product->fresh()->sale_ends_at)->not->toBeNull();
    });
});

it('refuses a time that is not a calendar time', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('saleStartsAt', '2026-02-30T08:00')
            ->assertHasErrors(['saleStartsAt'])
            ->assertSee('Chọn ngày giờ trong lịch, ví dụ 20/11/2026 08:00.');
    });
});

it('refuses a stock that is not a count', function (string $typed): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user, $typed): void {
        $product = priceProduct(['stock' => 5]);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('stock', $typed)
            ->assertHasErrors(['stock'])
            ->assertSee('Nhập số lượng bằng chữ số, ví dụ 24. Để trống nếu không theo dõi tồn kho.')
            ->call('save')
            ->assertHasErrors(['stock']);

        expect($product->fresh()->stock)->toBe(5);
    });
})->with(['-3', 'mười', '2,5', '12345678']);

it('says how many fields a save found to fix, in a message that stays, and points the cursor at the first', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('name', '')
            ->set('salePrice', '900.000')
            ->call('save')
            ->assertHasErrors(['name', 'salePrice'])
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => $params['text'] === 'Chưa lưu — còn 2 ô cần sửa. Con trỏ đã đưa tới ô đầu tiên.' && $params['stay'] === true);
    });
});

it('names a category another tab deleted when a save finds it gone', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);
        $product = priceProduct();
        $product->assignCategories([$category->id]);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('categoryId', $category->id);
        Taxonomy::query()->whereKey($category->id)->delete(); // as another tab would

        $editor->set('name', 'Đồ gá hàn mới')->call('save')
            ->assertHasErrors(['categoryId'])
            ->assertSee('Chuyên mục “Đồ gá” vừa bị xoá ở máy khác. Chọn chuyên mục khác rồi lưu lại.');
        expect($product->fresh()->translationFor('vi')?->name)->toBe('Đồ gá hàn');
    });
});

it('says which product holds a SKU, and whether it is in the Trash', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        priceProduct(['sku' => 'DP-10'], 'Dao phay D10', 'dao-phay-d10');
        $trashed = priceProduct(['sku' => 'DP-12'], 'Dao phay D12', 'dao-phay-d12');
        $trashed->delete();
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('sku', 'DP-10')
            ->assertHasErrors(['sku'])
            ->assertSee('Mã “DP-10” đang dùng cho “Dao phay D10”. Mỗi mã chỉ dùng cho một sản phẩm — thêm hậu tố, ví dụ “DP-10-B”.', false)
            ->set('sku', 'DP-12')
            ->assertHasErrors(['sku'])
            ->assertSee('Mã “DP-12” đang dùng cho “Dao phay D12” trong Thùng rác. Đổi mã khác, hoặc khôi phục sản phẩm đó rồi sửa ở đó.', false)
            ->call('save')
            ->assertHasErrors(['sku']);

        expect($product->fresh()->sku)->toBeNull();
    });
});

it('saves the labels with the form, through the action the products list uses', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('featured', true)
            ->set('bestseller', true)
            ->call('save')
            ->assertHasNoErrors();
        expect($product->fresh()->is_featured)->toBeTrue()->and($product->fresh()->is_bestseller)->toBeTrue();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('featured', true)
            ->set('featured', false)
            ->call('save');
        expect($product->fresh()->is_featured)->toBeFalse()->and($product->fresh()->is_bestseller)->toBeTrue();

        // a new product, labelled before its first save
        Livewire::actingAs($user)->test(Editor::class)->set('name', 'Mũi khoan')->set('featured', true)->call('save')->assertHasNoErrors();
        expect(Product::query()->where('is_featured', true)->count())->toBe(1)
            ->and(Product::query()->latest('id')->first()?->is_featured)->toBeTrue();
    });
});

it('keeps the label write to members who may change the product, and to known labels only', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        $product = priceProduct();

        expect(fn () => app(FlagProduct::class)->handle($product, ['status' => true]))->toThrow(InvalidArgumentException::class);

        auth()->setUser($viewer->fresh());
        expect(fn () => app(FlagProduct::class)->handle($product, ['featured' => true]))->toThrow(AuthorizationException::class);
        expect($product->fresh()->is_featured)->toBeFalse();
    });
});

it('folds the price card behind a note while the website only presents its products', function (): void {
    $user = priceManager();

    $ulid = app(Tenancy::class)->enter(1, fn (): string => priceProduct(['price' => 1250000])->ulid);

    $page = $this->actingAs($user)->get("http://a.test/admin/catalog/{$ulid}/edit")->assertOk();
    $page->assertSee('Website đang ở chế độ Giới thiệu — khách không thấy giá. Đổi ở Cài đặt › Sản phẩm. Giá đã nhập (1.250.000 ₫) vẫn được giữ.')
        ->assertSee('Mở khối giá')
        ->assertDontSee('Giá &amp; tồn kho', false);

    app(Tenancy::class)->enter(1, fn () => sellingSite());
    $this->actingAs($user)->get("http://a.test/admin/catalog/{$ulid}/edit")->assertOk()
        ->assertSee('Giá &amp; tồn kho', false)
        ->assertDontSee('Mở khối giá')
        ->assertSee('Đang tắt');
});

it('says what visitors see for a sale running now', function (): void {
    $user = priceManager();

    $ulid = app(Tenancy::class)->enter(1, function (): string {
        sellingSite();
        $product = priceProduct(['price' => 1250000, 'sale_price' => 1090000, 'sale_starts_at' => now()->subDay(), 'status' => 'published', 'published_at' => now()]);

        return $product->ulid;
    });

    $this->actingAs($user)->get("http://a.test/admin/catalog/{$ulid}/edit")->assertOk()
        ->assertSee('Đang áp dụng: khách thấy 1.090.000 ₫, giá gốc gạch ngang.')
        ->assertSee('Có — kèm giá 1.090.000 ₫ (khuyến mãi)');
});

it('draws the editor on the same queries whatever the price card holds', function (): void {
    $user = priceManager();

    [$plain, $full] = app(Tenancy::class)->enter(1, function (): array {
        sellingSite();

        return [
            priceProduct()->ulid,
            priceProduct(['price' => 1250000, 'sale_price' => 1090000, 'sale_starts_at' => CarbonImmutable::now()->subDay(), 'sale_ends_at' => CarbonImmutable::now()->addDay(),
                'stock' => 3, 'is_featured' => true, 'is_bestseller' => true, 'sku' => 'DP-1'], 'Dao phay', 'dao-phay')->ulid,
        ];
    });

    $this->actingAs($user)->get("http://a.test/admin/catalog/{$plain}/edit")->assertOk(); // warm the caches a signed-in member has
    $plainCount = countQueries(fn () => $this->actingAs($user)->get("http://a.test/admin/catalog/{$plain}/edit")->assertOk());
    $fullCount = countQueries(fn () => $this->actingAs($user)->get("http://a.test/admin/catalog/{$full}/edit")->assertOk());

    expect($fullCount)->toBe($plainCount);
});

it('saves the labels in the same row write as the other fields', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();
        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('price', '1.000.000')->set('stock', '2')->set('featured', true)->set('bestseller', true);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $editor->call('save')->assertHasNoErrors();
        $writes = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_starts_with($sql, 'update "products"'))->count();
        DB::disableQueryLog();

        expect($writes)->toBe(1);
    });
});

it('gives an example the person can use for a small price, and says why a price of 0 has no sale', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 5000]);

        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('salePrice', '5.000')
            ->assertSee('Giá khuyến mãi cần thấp hơn giá bán (5.000 ₫). Ví dụ 4.500 ₫.');
        $editor->set('price', '500')->set('salePrice', '500')->assertSee('Giá khuyến mãi cần thấp hơn giá bán (500 ₫). Ví dụ 450 ₫.');

        $editor->set('price', '0')->assertSee('Giá bán đang là 0 ₫ nên không có giá khuyến mãi thấp hơn.');
    });
});

it('leaves a label alone that the products list changed while the editor was open', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();
        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid]);

        // another tab switches «Nổi bật» on; this one then saves a name and «Bán chạy»
        app(FlagProduct::class)->handle($product->fresh() ?? $product, ['featured' => true]);
        $editor->set('name', 'Đồ gá hàn khung')->set('bestseller', true)->call('save')->assertHasNoErrors();

        expect($product->fresh()->is_featured)->toBeTrue()->and($product->fresh()->is_bestseller)->toBeTrue();
        // and the form now shows what is stored, with nothing left to save
        $editor->assertSet('featured', true)->assertSet('bestseller', true);
        expect($editor->get('saved')['featured'])->toBe('1')->and($editor->get('saved')['bestseller'])->toBe('1');
    });
});

it('keeps a stored sale time to the second when the save does not change it', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 1000000, 'sale_price' => 900000, 'sale_starts_at' => '2026-11-20 01:00:30+00']);

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('saleStartsAt', '2026-11-20T08:00')
            ->set('name', 'Đồ gá hàn khung')->call('save')->assertHasNoErrors();

        expect($product->fresh()->sale_starts_at?->utc()->format('H:i:s'))->toBe('01:00:30');
    });
});

it('checks a SKU on leaving the field as the save stores it, spaces trimmed', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        priceProduct(['sku' => 'DP-10'], 'Dao phay D10', 'dao-phay-d10');
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('sku', ' DP-10 ')
            ->assertHasErrors(['sku']);
    });
});

it('answers a sale time the browser sent as null with a message, not a crash', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct();

        Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid])
            ->set('saleStartsAt', null)
            ->assertSet('saleStartsAt', '')
            ->call('save')
            ->assertHasNoErrors();
    });
});

it('writes the sale start and end together, so another tab start cannot end up after this end', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 1000000, 'sale_price' => 900000, 'sale_starts_at' => '2026-11-20 01:00:00+00', 'sale_ends_at' => '2026-11-30 01:00:00+00']);
        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid]);

        // another tab moves the start to the 25th; this one moves the end to the 22nd (after its own start, the 20th)
        Product::query()->whereKey($product->id)->update(['sale_starts_at' => '2026-11-25 01:00:00+00']);
        $editor->set('saleEndsAt', '2026-11-22T08:00')->call('save')->assertHasNoErrors();

        $stored = $product->fresh();
        expect($stored->sale_starts_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-20 01:00')
            ->and($stored->sale_ends_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-22 01:00');
    });
});

it('shows a sale start another tab changed once this tab saves without touching it', function (): void {
    $user = priceManager();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $product = priceProduct(['price' => 1000000, 'sale_price' => 900000, 'sale_starts_at' => '2026-11-20 01:00:00+00']);
        $editor = Livewire::actingAs($user)->test(Editor::class, ['ulid' => $product->ulid]);

        Product::query()->whereKey($product->id)->update(['sale_starts_at' => '2026-11-25 01:00:00+00']);
        $editor->set('name', 'Đồ gá hàn khung')->call('save')->assertHasNoErrors()
            ->assertSet('saleStartsAt', '2026-11-25T08:00');

        expect($editor->get('saved')['saleStartsAt'])->toBe('2026-11-25T08:00')
            ->and($product->fresh()->sale_starts_at?->utc()->format('Y-m-d H:i'))->toBe('2026-11-25 01:00');
    });
});

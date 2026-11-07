<?php

use App\Modules\Admin\Livewire\Catalog\Index;
use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The products list: status groups with bounded counts and the Trash, the search, the sort menu
 * (price and stock only where products are sold) and numbered pages that turn into a cursor past the
 * cap. Every order meets each row exactly once, whichever way it is walked: rows that tie on the sort
 * value, rows with no value (no price, no stock tracking, no name), forward and backward.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A product with an optional name in the default language; its edit time is fixed so ties are real. */
function listProduct(?string $name, ?string $sku, ?int $price, ?int $stock, string $status, string $editedAt): Product
{
    $product = Product::query()->create(['status' => $status, 'sku' => $sku, 'price' => $price, 'stock' => $stock]);
    if ($name !== null) {
        $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'sp-'.$product->id]);
    }
    DB::connection('pgsql_admin')->table('products')->where('id', $product->id)->update(['updated_at' => $editedAt]);

    return $product;
}

/** Eight products on website 1 with ties and gaps in every sort value. */
function listSeed(): void
{
    listProduct('Đồ gá', 'DG-1', 500000, 3, 'published', '2026-11-01 08:00:00+00');
    listProduct('Dao phay', 'DP-1', 250000, 0, 'published', '2026-11-01 08:00:00+00');
    listProduct('Ép nhựa', null, 250000, null, 'draft', '2026-11-01 08:00:00+00');
    listProduct('Ốc vít', 'OV-1', null, 12, 'archived', '2026-10-30 08:00:00+00');
    listProduct('Mũi khoan', 'MK-1', 900000, 3, 'draft', '2026-10-31 08:00:00+00');
    listProduct(null, 'ZZ-1', 100000, 7, 'published', '2026-10-29 08:00:00+00');
    listProduct('Thước cặp', 'TC-1', 250000, null, 'published', '2026-10-31 08:00:00+00');
    listProduct('Búa', 'BU-1', null, null, 'draft', '2026-10-28 08:00:00+00');
}

function listQuery(string $tab = 'all', string $search = '', int $cap = ProductListQuery::CAP): ProductListQuery
{
    return new ProductListQuery(1, $tab, $search, 'vi', $cap);
}

/** @return list<int> */
function listIds(ProductListQuery $list, string $sort, int $offset = 0, int $limit = 1000): array
{
    return $list->rows($sort, $offset, $limit)->modelKeys();
}

it('counts every status group and the Trash in one bounded statement', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        listSeed();
        Product::query()->where('sku', 'ZZ-1')->firstOrFail()->delete();

        $statements = 0;
        DB::listen(function () use (&$statements): void {
            $statements++;
        });
        $counts = listQuery()->tabCounts();

        expect($statements)->toBe(1)
            ->and($counts)->toBe(['all' => 7, 'published' => 3, 'draft' => 3, 'archived' => 1, 'trash' => 1]);
    });
});

it('stops counting at the cap and shows hơn 10.000 instead of a last page', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'cat-viewer', ['catalog.view']);
        listSeed();
        config(['admin.catalog_list_cap' => 3]);

        $counts = listQuery(cap: 3)->tabCounts();
        expect($counts['all'])->toBe(4)->and($counts['archived'])->toBe(1);

        $test = Livewire::actingAs($user->fresh())->test(Index::class);
        expect($test->viewData('pager')['total'])->toBe(__('catalog.count_over'))
            ->and($test->viewData('pager')['last'])->toBeNull();
    });
});

it('orders by last edit newest first, name A to Z in the Vietnamese alphabet, price and stock with gaps last', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        listSeed();
        $list = listQuery();
        $byName = fn (array $ids): array => Product::query()->whereKey($ids)->get()->sortBy(fn (Product $p): int => array_search($p->id, $ids, true))
            ->map(fn (Product $p): ?string => $p->translations()->first()?->name)->values()->all();

        // the same edit time ties by the newest record; the oldest edit is last
        $updated = Product::query()->orderByDesc('updated_at')->orderByDesc('id')->pluck('id')->all();
        expect(listIds($list, 'updated'))->toBe($updated);

        expect($byName(listIds($list, 'name')))->toBe(['Búa', 'Dao phay', 'Đồ gá', 'Ép nhựa', 'Mũi khoan', 'Ốc vít', 'Thước cặp', null]);

        $all = Product::query()->orderBy('id')->get();
        $expect = function (string $column, string $dir) use ($all): array {
            return $all->sort(fn (Product $a, Product $b): int => [$a->{$column} === null ? 1 : 0, ($dir === 'asc' ? 1 : -1) * ($a->{$column} ?? 0), ($dir === 'asc' ? 1 : -1) * $a->id]
                <=> [$b->{$column} === null ? 1 : 0, ($dir === 'asc' ? 1 : -1) * ($b->{$column} ?? 0), ($dir === 'asc' ? 1 : -1) * $b->id])->pluck('id')->values()->all();
        };
        expect(listIds($list, 'price_asc'))->toBe($expect('price', 'asc'))
            ->and(listIds($list, 'price_desc'))->toBe($expect('price', 'desc'))
            ->and(listIds($list, 'stock_asc'))->toBe($expect('stock', 'asc'));
    });
});

it('meets every row once, in one order, by offset and by cursor forward and backward', function (string $sort): void {
    app(Tenancy::class)->enter(1, function () use ($sort): void {
        listSeed();
        $list = listQuery();
        $whole = listIds($list, $sort);
        expect($whole)->toHaveCount(8)->and(array_unique($whole))->toHaveCount(8);

        $byOffset = [];
        foreach (range(0, 3) as $page) {
            $byOffset = [...$byOffset, ...listIds($list, $sort, $page * 3, 3)];
        }
        expect($byOffset)->toBe($whole);

        $anchorOf = fn ($row): array => ['v' => $row->getAttribute('__sv'), 'id' => $row->id];

        $forward = [];
        $anchor = null;
        do {
            $rows = $list->rows($sort, 0, 3, $anchor);
            $forward = [...$forward, ...$rows->modelKeys()];
            $anchor = $rows->isEmpty() ? null : $anchorOf($rows->last());
        } while ($anchor !== null);
        expect($forward)->toBe($whole);

        // from the end, page by page towards the start
        $backward = [];
        $rows = $list->rows($sort, 5, 3);
        $backward = $rows->modelKeys();
        while ($rows->isNotEmpty()) {
            $rows = $list->rows($sort, 0, 3, $anchorOf($rows->first()), before: true);
            $backward = [...$rows->modelKeys(), ...$backward];
        }
        expect($backward)->toBe($whole);
    });
})->with(['updated', 'name', 'price_asc', 'price_desc', 'stock_asc']);

it('keeps paging from a row deleted after its page was shown', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        listSeed();
        $list = listQuery();
        $whole = listIds($list, 'price_desc');
        $page = $list->rows('price_desc', 0, 3);
        $anchor = ['v' => $page->last()->getAttribute('__sv'), 'id' => $page->last()->id];

        Product::query()->whereKey($page->last()->id)->firstOrFail()->delete();

        expect($list->rows('price_desc', 0, 3, $anchor)->modelKeys())->toBe(array_slice($whole, 3, 3));
    });
});

it('turns pages past the cap with a cursor both ways, never losing or repeating a product', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'cat-viewer', ['catalog.view']);
        foreach (range(1, 25) as $i) {
            listProduct("Sản phẩm {$i}", null, null, null, 'draft', '2026-11-01 08:00:00+00');
        }
        config(['admin.catalog_list_cap' => 3]);
        $whole = listIds(listQuery(), 'updated');
        $ids = fn ($test): array => $test->viewData('rows')->modelKeys();

        $one = Livewire::withQueryParams(['per_page' => 10])->actingAs($user->fresh())->test(Index::class);
        expect($ids($one))->toBe(array_slice($whole, 0, 10))
            ->and($one->viewData('pager')['next'])->toHaveKey('token');

        $two = $one->call('turnPage', $one->viewData('pager')['next']['token']);
        expect($ids($two))->toBe(array_slice($whole, 10, 10))
            ->and($two->viewData('pager')['current'])->toBe(2);

        $three = $two->call('turnPage', $two->viewData('pager')['next']['token']);
        expect($ids($three))->toBe(array_slice($whole, 20, 5))
            ->and($three->viewData('pager')['next'])->toBeNull()
            ->and($three->viewData('pager')['prev'])->toHaveKey('token');

        $back = $three->call('turnPage', $three->viewData('pager')['prev']['token']);
        expect($ids($back))->toBe(array_slice($whole, 10, 10))
            ->and($back->viewData('pager')['current'])->toBe(2);

        // a note made for another view of the list starts the list again
        $other = $back->call('setSort', 'name');
        expect($ids($other))->not->toBe(array_slice($whole, 10, 10))->and($other->get('page'))->toBe(1);
    });
});

it('refuses a forged cursor and starts the list again', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'cat-viewer', ['catalog.view']);
        listSeed();

        $test = Livewire::actingAs($user->fresh())->test(Index::class)->set('page', 2)->call('turnPage', 'not-a-cursor');
        expect($test->get('page'))->toBe(1)->and($test->get('cursor'))->toBe('');
    });
});

it('searches by name or SKU, ignoring case, and keeps the Trash apart', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();
        Product::query()->where('sku', 'DP-1')->firstOrFail()->delete();

        $test = Livewire::actingAs($manager->fresh())->test(Index::class)
            ->set('search', 'ĐỒ GÁ')->assertSee('Đồ gá')->assertDontSee('Mũi khoan')
            ->set('search', 'mk-1')->assertSee('Mũi khoan')->assertDontSee('Đồ gá')
            ->set('search', 'dao phay')->assertDontSee('Dao phay')
            ->call('setTab', 'trash')->assertSee('Dao phay')
            ->set('search', '%')->assertDontSee('Dao phay'); // a percent sign is text, not a wildcard
        expect($test->get('tab'))->toBe('trash');
    });
});

it('opens the Trash group from the old trash link', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();
        Product::query()->where('sku', 'DP-1')->firstOrFail()->delete();

        Livewire::withQueryParams(['trash' => '1'])->actingAs($manager->fresh())->test(Index::class)
            ->assertSet('tab', 'trash')->assertSee('Dao phay')->assertDontSee('Mũi khoan');
        Livewire::withQueryParams(['tab' => 'trash'])->actingAs($manager->fresh())->test(Index::class)
            ->assertSet('tab', 'trash')->assertSee('Dao phay');
    });
});

it('hides the price and stock sorts in brochure mode and offers them where products are sold', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();

        $brochure = Livewire::withQueryParams(['sort' => 'price_asc'])->actingAs($manager->fresh())->test(Index::class);
        expect($brochure->viewData('sorts'))->toBe(['updated', 'name'])
            ->and($brochure->viewData('sortOn'))->toBe('updated'); // a link to a sort this mode lacks falls back
        $brochure->call('setSort', 'stock_asc')->assertSet('sortKey', '');

        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'commerce']]);
        $selling = Livewire::actingAs($manager->fresh())->test(Index::class);
        expect($selling->viewData('sorts'))->toBe(['updated', 'name', 'price_asc', 'price_desc', 'stock_asc']);
        $selling->call('setSort', 'price_desc')->assertSet('sortKey', 'price_desc');
        expect($selling->viewData('sortOn'))->toBe('price_desc');
    });
});

it('keeps the columns and cards a member chooses in their own preferences, never another member\'s', function (): void {
    $lan = User::factory()->create(['name' => 'Lan']);
    $an = User::factory()->create(['name' => 'An']);

    app(Tenancy::class)->enter(1, function () use ($lan, $an): void {
        grantRole($lan, 'cat-viewer', ['catalog.view']);
        grantRole($an, 'cat-viewer', ['catalog.view']);
        $lan->forceFill(['preferences' => ['theme' => ['scheme' => 'dark']]])->save();

        Livewire::actingAs($lan->fresh())->test(Index::class)
            ->assertSet('cols', ['cat', 'price', 'stock', 'badge'])
            ->set('cols', ['price', 'bogus', 'cat'])
            ->set('card', ['slug', 'nonsense', 'sku']);

        $saved = $lan->fresh()->preferences;
        expect($saved['lists']['catalog'])->toEqual(['cols' => ['cat', 'price'], 'card' => ['sku', 'slug']])
            ->and($saved['theme'])->toBe(['scheme' => 'dark']) // what else the member saved is kept
            ->and($an->fresh()->preferences['lists'] ?? null)->toBeNull();

        Livewire::actingAs($lan->fresh())->test(Index::class)->assertSet('cols', ['cat', 'price'])->assertSet('card', ['sku', 'slug']);
        Livewire::actingAs($an->fresh())->test(Index::class)->assertSet('cols', ['cat', 'price', 'stock', 'badge'])->assertSet('card', []);
    });
});

it('shows a member who may only view no write control', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);
        listSeed();
        Product::query()->where('sku', 'DP-1')->firstOrFail()->delete();

        Livewire::actingAs($viewer->fresh())->test(Index::class)
            ->assertSee('Mũi khoan')
            ->assertDontSee(__('catalog.create_new'))
            ->assertDontSee(__('catalog.trash_tab'))
            ->assertDontSee(__('catalog.edit_action'))
            ->assertDontSee(__('catalog.trash_action'));

        // the Trash is for who manages: asking for it shows the whole list instead
        Livewire::withQueryParams(['trash' => '1'])->actingAs($viewer->fresh())->test(Index::class)
            ->assertSet('tab', 'all')->assertDontSee('Dao phay');
    });
});

it('lists a manager the add button, the Trash group and the row actions', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();

        Livewire::actingAs($manager->fresh())->test(Index::class)
            ->assertSee(__('catalog.create_new'))
            ->assertSee(__('catalog.trash_tab'))
            ->assertSee(__('catalog.edit_action'))
            ->assertSee(__('catalog.trash_action'));
    });
});

it('never lists or counts another website\'s products', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(2, function (): void {
        listProduct('Hàng của website B', 'B-1', 1, 1, 'published', '2026-11-02 08:00:00+00');
        listProduct('Hàng cũ của website B', 'B-2', 1, 1, 'draft', '2026-11-02 08:00:00+00')->delete();
    });

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();

        $test = Livewire::actingAs($manager->fresh())->test(Index::class)->assertDontSee('Hàng của website B');
        expect($test->viewData('counts'))->toBe(['all' => 8, 'published' => 4, 'draft' => 3, 'archived' => 1, 'trash' => 0])
            ->and($test->viewData('matchCount'))->toBe(8);
        $search = listQuery('all', 'website B');
        expect($search->count($search->filtered()))->toBe(0);
    });

    // the same worker serves website B next, then website A again: each sees only its own
    app(Tenancy::class)->enter(2, function (): void {
        expect(listQuery()->tabCounts())->toBe(['all' => 1, 'published' => 1, 'draft' => 0, 'archived' => 0, 'trash' => 1]);
    });
    app(Tenancy::class)->enter(1, function (): void {
        expect(listQuery()->tabCounts()['all'])->toBe(8);
    });
});

it('draws the empty state with the add button for a manager and a plain note for a viewer', function (): void {
    $manager = User::factory()->create();
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager, $viewer): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        Livewire::actingAs($manager->fresh())->test(Index::class)
            ->assertSee(__('catalog.empty_title'))->assertSee(__('catalog.empty_add_first'));
        Livewire::actingAs($viewer->fresh())->test(Index::class)
            ->assertSee(__('catalog.empty_title'))->assertSee(__('catalog.empty_text_view'))->assertDontSee(__('catalog.empty_add_first'));
    });
});

it('says plainly when nothing matches, and when the Trash is empty', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();

        Livewire::actingAs($manager->fresh())->test(Index::class)
            ->set('search', 'không có gì như thế')->assertSee(__('catalog.empty_filtered'))
            ->call('clearAllFilters')->assertSet('search', '')->assertSet('tab', 'all')
            ->call('setTab', 'trash')->assertSee(__('catalog.trash_empty'));
    });
});

it('draws the error state with a retry when the list cannot be read, and recovers', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        listSeed();

        $fail = true;
        DB::beforeExecuting(function (string $query) use (&$fail): void {
            if ($fail && str_contains($query, 'distinct on (product_id)')) {
                throw new QueryException('pgsql', $query, [], new RuntimeException('server busy'));
            }
        });

        $test = Livewire::actingAs($manager->fresh())->test(Index::class)
            ->assertSee(__('catalog.error_title'))->assertSee(__('catalog.error_retry'))->assertDontSee('Mũi khoan');

        $fail = false;
        $test->call('$refresh')->assertDontSee(__('catalog.error_title'))->assertSee('Mũi khoan');
    });
});

it('reads one page of rows with only the first picture of each product', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $product = listProduct('Đồ gá', 'DG-1', null, null, 'draft', '2026-11-01 08:00:00+00');
        foreach ([2, 0, 1] as $position) {
            $media = Media::query()->create([
                'collection' => 'library', 'slug' => "anh-{$position}", 'hash' => hash('sha256', "img-{$position}"),
                'storage_key' => "s/library/anh-{$position}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
            ]);
            $product->images()->create(['media_id' => $media->id, 'position' => $position]);
        }

        $rows = Livewire::actingAs($manager->fresh())->test(Index::class)->viewData('rows');
        expect($rows->first()->images)->toHaveCount(1)
            ->and($rows->first()->images->first()->media->storage_key)->toBe('s/library/anh-0.png');
    });
});

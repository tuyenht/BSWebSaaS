<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Support\Catalog\ProductListQuery;
use App\Modules\Catalog\Actions\ChangeProductSlug;
use App\Modules\Catalog\Actions\RenameProduct;
use App\Modules\Catalog\Console\RebuildProductSearchWords;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductSearchWords;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

/*
 * The word table of the admin products search: what words a product has, that every save of a name
 * or a SKU keeps them in the same transaction, that a website sees only its own words (RLS), how the
 * list searches them, and the command that checks and corrects them.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A product of the current site with a Vietnamese name. */
function wordsProduct(string $name, ?string $sku = null, string $status = 'published'): Product
{
    $product = Product::query()->create(['status' => $status, 'sku' => $sku]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'sp-'.$product->id]);

    return $product;
}

/** @return list<string> the stored words of one product of the current site */
function storedWords(Product $product): array
{
    return ProductSearchWords::stored($product->site_id, [$product->id])[$product->id] ?? [];
}

/** @return list<string> the names (default language) the list finds for $search on website 1 */
function wordsFound(string $search, string $tab = 'all', int $gather = ProductListQuery::GATHER): array
{
    $list = new ProductListQuery(1, $tab, $search, 'vi', gather: $gather);

    return $list->rows('updated', 0, 50)->map(fn (Product $p): string => (string) $p->translationFor('vi')?->name)->sort()->values()->all();
}

it('makes the words of a name and a SKU, folded, once each, with the SKU endings', function (?string $sku, array $names, array $words): void {
    expect(ProductSearchWords::of($sku, $names))->toBe($words);
})->with([
    'accents, case and signs' => [null, ['Ống thép mạ kẽm — Ø20, Ống'], ['kem', 'ma', 'ong', 'thep', 'ø20']],
    'two languages' => [null, ['Vòng bi', 'Ball bearing'], ['ball', 'bearing', 'bi', 'vong']],
    'a SKU with its endings' => ['AB-270E5', [], ['0e5', '270e5', '70e5', 'ab', 'ab270e5', 'b270e5', 'e5']],
    'a name that is not Latin' => [null, ['Ổ trục 東京 हिन्दी'], ['o', 'truc', 'हिन्दी', '東京']],
    'nothing but signs' => ['--', ['%_%', '  '], []],
]);

it('cuts a very long word and finds it by its start', function (): void {
    $long = str_repeat('ẩ', 80);
    $words = ProductSearchWords::of(null, [$long]);

    expect($words)->toBe([str_repeat('a', ProductSearchWords::MAX_LENGTH)])
        ->and(ProductSearchWords::needles($long))->toBe($words);
});

it('reads the words typed, drops one that starts another and finds nothing in signs alone', function (string $search, array $needles): void {
    expect(ProductSearchWords::needles($search))->toBe($needles);
})->with([
    'a phrase in any case' => ['VÒNG bi  chịu', ['chiu', 'vong', 'bi']],
    'a word that starts another' => ['vo vong', ['vong']],
    'percent and underscore are not wildcards' => ['50%_off', ['off', '50']],
    'signs only' => ['% _ --', []],
    'empty' => ['', []],
]);

it('bounds every prefix range in code point order, past letters of any script', function (string $prefix, ?string $upper): void {
    expect(ProductSearchWords::upperBound($prefix))->toBe($upper);
    if ($upper !== null) {
        foreach ([$prefix, $prefix.'a', $prefix.'ỹ', $prefix.'東', $prefix.mb_chr(0x10FFFF)] as $inside) {
            expect(strcmp($inside, $prefix) >= 0 && strcmp($inside, $upper) < 0)->toBeTrue($inside);
        }
        expect(mb_check_encoding($upper, 'UTF-8'))->toBeTrue();
    }
})->with([
    'Latin' => ['vong', 'vonh'],
    'a Vietnamese letter' => ['ư', mb_chr(0x01B1)],
    'a two-byte letter whose next ends a byte run' => [mb_chr(0x07FF), mb_chr(0x0800)],
    'Chinese' => ['東', mb_chr(mb_ord('東') + 1)],
    'before the surrogates' => [mb_chr(0xD7FF), mb_chr(0xE000)],
    'the last code point' => ['a'.mb_chr(0x10FFFF), 'b'],
    'only the last code point' => [mb_chr(0x10FFFF), null],
]);

it('keeps the words with every save of a name or a SKU, and with a language removed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = wordsProduct('Đồ gá hàn', 'DG-01');
        expect(storedWords($product))->toBe(['01', 'dg', 'dg01', 'do', 'g01', 'ga', 'han']);

        app(RenameProduct::class)->handle($product, 'Bàn kẹp', 'vi');
        $product->update(['sku' => 'BK-9']);
        expect(storedWords($product))->toBe(['9', 'ban', 'bk', 'bk9', 'k9', 'kep']);

        $english = $product->translations()->create(['locale' => 'en', 'name' => 'Clamp table', 'slug' => 'clamp-table']);
        expect(storedWords($product))->toContain('clamp', 'table', 'ban');
        $english->delete();
        $product->update(['sku' => null]);
        expect(storedWords($product))->toBe(['ban', 'kep']);
    });
});

it('keeps the words of a product in the Trash and drops them when it is deleted for good', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = wordsProduct('Ê tô kẹp', 'ET-1');
        $product->delete();
        expect(storedWords($product))->not->toBe([])
            ->and(wordsFound('kẹp', 'trash'))->toBe(['Ê tô kẹp']);

        $product->restore();
        expect(wordsFound('ê tô'))->toBe(['Ê tô kẹp']);

        $product->forceDelete();
        expect(DB::table('product_search_words')->where('product_id', $product->id)->count())->toBe(0);
    });
});

it('writes the words in three statements when a name changes, and none for any other save', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = wordsProduct('Máy khoan bàn', 'MK-2');
        $translation = $product->translations()->firstOrFail();

        $address = countQueries(fn () => $translation->update(['slug' => 'may-khoan-ban-moi']));
        $rename = countQueries(fn () => $translation->update(['name' => 'Máy khoan đứng']));
        $price = countQueries(fn () => $product->update(['price' => 1_200_000]));
        $sku = countQueries(fn () => $product->update(['price' => 1_300_000, 'sku' => 'MK-3']));

        // lock the product, read its names, write the words
        expect($rename - $address)->toBe(3)
            ->and($sku - $price)->toBe(3)
            ->and($address)->toBeLessThanOrEqual(2)
            ->and(storedWords($product))->toContain('dung', 'mk3')->not->toContain('ban')->not->toContain('mk2');
    });
});

it('keeps the words through the product editor, for a new product and an edited one', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-words-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())->test(Editor::class)
            ->set('name', 'Đồ gá phay')->set('slug', 'do-ga-phay')->set('sku', 'DGP-7')
            ->call('save')->assertHasNoErrors();
        $product = Product::query()->firstOrFail();
        expect(storedWords($product))->toBe(['7', 'dgp', 'dgp7', 'do', 'ga', 'gp7', 'p7', 'phay']);

        Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $product->ulid])
            ->set('name', 'Đồ gá tiện')->set('sku', 'DGT-8')
            ->call('save')->assertHasNoErrors();
        expect(storedWords($product))->toBe(['8', 'dgt', 'dgt8', 'do', 'ga', 'gt8', 't8', 'tien']);
    });
});

it('lets a website see and write only its own words, and nothing without a website', function (): void {
    $theirs = app(Tenancy::class)->enter(2, fn (): Product => wordsProduct('Vòng bi bên kia', 'B-2'));

    app(Tenancy::class)->enter(1, function () use ($theirs): void {
        wordsProduct('Vòng bi bên này', 'A-1');

        expect(DB::table('product_search_words')->distinct()->pluck('site_id')->all())->toBe([1])
            ->and(DB::table('product_search_words')->where('product_id', $theirs->id)->count())->toBe(0)
            ->and(wordsFound('vong bi'))->toBe(['Vòng bi bên này']);

        expect(fn () => DB::transaction(fn () => DB::table('product_search_words')->insert(['site_id' => 2, 'product_id' => $theirs->id, 'word' => 'lot'])))
            ->toThrow(QueryException::class, 'row-level security');
    });

    // the app's own connection outside any website: the policy fails closed
    DB::transaction(function (): void {
        expect(DB::table('product_search_words')->count())->toBe(0);
    });
});

it('finds a product by the starts of its words in any order, with or without accents, and a code by a piece of its middle', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        wordsProduct('Vòng bi chịu nhiệt', 'VB-270E5');
        wordsProduct('Vòng đệm cao su', 'VD-100');
        wordsProduct('Bi thép không gỉ', 'BT-3');
        wordsProduct('Giảm giá 50%_cuối năm', 'GG-50');
        wordsProduct('Ống 東京 nhập khẩu', null);

        expect(wordsFound('bi vòng'))->toBe(['Vòng bi chịu nhiệt'])
            ->and(wordsFound('VONG'))->toBe(['Vòng bi chịu nhiệt', 'Vòng đệm cao su'])
            ->and(wordsFound('ch nh'))->toBe(['Vòng bi chịu nhiệt'])
            ->and(wordsFound('270e'))->toBe(['Vòng bi chịu nhiệt'])
            ->and(wordsFound('vb270'))->toBe(['Vòng bi chịu nhiệt'])
            ->and(wordsFound('50%'))->toBe(['Giảm giá 50%_cuối năm'])
            ->and(wordsFound('東'))->toBe(['Ống 東京 nhập khẩu'])
            ->and(wordsFound('nhiet'))->toBe(['Vòng bi chịu nhiệt'])
            ->and(wordsFound('hiet'))->toBe([])           // a piece from the middle of a name word is not a start
            ->and(wordsFound('%'))->toBe([])              // signs alone find nothing, never the whole list
            ->and(wordsFound('_'))->toBe([])
            ->and(wordsFound('vòng xyz'))->toBe([]);      // every word must be there
    });
});

it('finds the same products whether it gathers the rarest word first or walks the list', function (string $search): void {
    app(Tenancy::class)->enter(1, function () use ($search): void {
        foreach (['Vòng bi cầu', 'Vòng bi đũa', 'Vòng đệm', 'Bi cầu thép', 'Bạc lót', 'Vòng bi chặn'] as $i => $name) {
            wordsProduct($name, 'VB-'.$i, $i % 2 === 0 ? 'published' : 'draft');
        }
        $sent = [];
        DB::listen(function ($query) use (&$sent): void {
            $sent[] = $query->sql;
        });

        $gathered = wordsFound($search);
        $walked = wordsFound($search, 'all', 1);
        $walkedDrafts = wordsFound($search, 'draft', 1);

        expect($walked)->toBe($gathered)->not->toBe([])
            ->and($walkedDrafts)->toBe(array_values(array_intersect($gathered, ['Vòng bi đũa', 'Bạc lót', 'Vòng bi chặn'])))
            ->and(collect($sent)->contains(fn (string $sql): bool => str_contains($sql, '= any (array(select w.product_id')))->toBeTrue()
            ->and(collect($sent)->contains(fn (string $sql): bool => str_contains($sql, 'exists (select 1 from "product_search_words" as "w"')))->toBeTrue();

        // no index walks the name order: a page in it gathers even when every word is common
        $sent = [];
        $byName = (new ProductListQuery(1, 'all', $search, 'vi', gather: 1))->rows('name', 0, 50)
            ->map(fn (Product $p): string => (string) $p->translationFor('vi')?->name)->sort()->values()->all();
        expect($byName)->toBe($gathered)
            ->and(collect($sent)->contains(fn (string $sql): bool => str_contains($sql, 'exists (select 1 from "product_search_words" as "w"')))->toBeFalse()
            ->and(collect($sent)->contains(fn (string $sql): bool => str_contains($sql, '= any (array(select w.product_id')))->toBeTrue();
    });
})->with([
    'one word' => ['vòng'],
    'two words' => ['bi vòng'],
    'a word and a code' => ['vòng vb'],
]);

it('corrects missing and stale words site by site, logs it once per site, and finds nothing the second time', function (): void {
    [$a, $b] = app(Tenancy::class)->enter(1, fn (): array => [wordsProduct('Thước cặp điện tử', 'TC-1'), wordsProduct('Thước lá', 'TL-2')]);
    $other = app(Tenancy::class)->enter(2, fn (): Product => wordsProduct('Thước đo góc', 'TD-3'));
    $admin = DB::connection('pgsql_admin');
    // a write path that missed the words: one product has none, the other an old word
    $admin->table('product_search_words')->where('product_id', $a->id)->delete();
    $admin->table('product_search_words')->insert(['site_id' => 1, 'product_id' => $b->id, 'word' => 'cu']);
    $admin->table('product_search_words')->where('product_id', $other->id)->delete();
    Log::spy();

    $this->artisan('catalog:search-words', ['--site' => 1, '--chunk' => 1])
        ->expectsOutputToContain('Checked 2 product(s) on 1 site(s); corrected 2.')->assertSuccessful();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['site_id'] === 1
        && $context['products'] === 2 && $context['sample'] === [$a->ulid, $b->ulid]);
    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        expect(storedWords($a))->toBe(['1', 'c1', 'cap', 'dien', 'tc', 'tc1', 'thuoc', 'tu'])
            ->and(storedWords($b))->not->toContain('cu');
    });
    expect($admin->table('product_search_words')->where('product_id', $other->id)->count())->toBe(0); // --site kept to site 1

    $this->artisan('catalog:search-words')->expectsOutputToContain('corrected 1.')->assertSuccessful();
    $this->artisan('catalog:search-words')->expectsOutputToContain('corrected 0.')->assertSuccessful();
});

it('checks only the products edited last with --recent', function (): void {
    [$old, $recent] = app(Tenancy::class)->enter(1, function (): array {
        $old = wordsProduct('Cờ lê cũ', 'CL-1');
        Product::query()->whereKey($old->id)->toBase()->update(['updated_at' => now()->subDay()]);

        return [$old, wordsProduct('Cờ lê mới', 'CL-2')];
    });
    DB::connection('pgsql_admin')->table('product_search_words')->whereIn('product_id', [$old->id, $recent->id])->delete();

    $this->artisan('catalog:search-words', ['--recent' => 1])->expectsOutputToContain('corrected 1.')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($old, $recent): void {
        expect(storedWords($recent))->toContain('moi')->and(storedWords($old))->toBe([]);
    });
});

it('fills the words of every product of a website whose word table is empty, the Trash too', function (): void {
    $names = ['Vòng bi cầu', 'Ổ trục côn', 'Bạc đạn kim', 'Gối đỡ', 'Puly đai'];
    $products = app(Tenancy::class)->enter(1, function () use ($names): array {
        $made = [];
        foreach ($names as $n => $name) {
            $made[] = wordsProduct($name, 'PB-'.$n);
        }
        $made[4]->delete();

        return $made;
    });
    // the table as the migration leaves it: no words yet for products made on the old code
    DB::connection('pgsql_admin')->table('product_search_words')->where('site_id', 1)->delete();

    $this->artisan('catalog:search-words', ['--site' => 1, '--chunk' => 2])
        ->expectsOutputToContain('Checked 5 product(s) on 1 site(s); corrected 5.')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($products, $names): void {
        foreach ($products as $n => $product) {
            expect(storedWords($product))->toBe(ProductSearchWords::of('PB-'.$n, [$names[$n]]));
        }
        expect(wordsFound('o truc'))->toBe(['Ổ trục côn'])
            ->and(wordsFound('pb-3'))->toBe(['Gối đỡ'])
            ->and(wordsFound('puly', 'trash'))->toBe(['Puly đai']);
    });
    expectProductSearchWordsCurrent(1);
});

it('checks every product in turn with --rolling, from where the last run stopped, and starts again after the last', function (): void {
    $products = app(Tenancy::class)->enter(1, function (): array {
        $made = [];
        foreach (['Ống thép', 'Ống đồng', 'Ống nhựa', 'Ống inox', 'Ống nhôm', 'Ống mới'] as $n => $name) {
            $made[] = wordsProduct($name, 'ON-'.$n);
        }
        // nobody edited the first five for a long time: --recent never reaches them
        Product::query()->whereKey(array_map(fn (Product $p): int => $p->id, array_slice($made, 0, 5)))->toBase()->update(['updated_at' => now()->subYear()]);

        return $made;
    });
    $admin = DB::connection('pgsql_admin');
    $run = fn () => $this->artisan('catalog:search-words', ['--site' => 1, '--recent' => 1, '--rolling' => 2]);

    $run()->expectsOutputToContain('Checked 3 product(s) on 1 site(s); corrected 0.')->assertSuccessful();   // the newest, then the 1st and 2nd
    // a write that skipped the models on two old products: one the cursor passed, one ahead of it
    $admin->table('product_search_words')->whereIn('product_id', [$products[0]->id, $products[3]->id])->delete();

    $run()->expectsOutputToContain('corrected 1.')->assertSuccessful();   // the 3rd and 4th
    $run()->expectsOutputToContain('corrected 0.')->assertSuccessful();   // the 5th and 6th
    Log::spy();
    $run()->expectsOutputToContain('corrected 1.')->assertSuccessful();   // nothing after the 6th: from the 1st again

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['site_id'] === 1
        && $context['products'] === 1 && $context['sample'] === [$products[0]->ulid]);
    app(Tenancy::class)->enter(1, function () use ($products): void {
        expect(storedWords($products[0]))->toContain('ong', 'thep', 'on0')
            ->and(storedWords($products[3]))->toContain('ong', 'inox', 'on3');
    });
});

it('checks each product once per --rolling run when the products fill whole chunks and are fewer than the run', function (): void {
    $products = app(Tenancy::class)->enter(1, function (): array {
        $made = [];
        foreach (['Van bi', 'Van cổng', 'Van cầu', 'Van một chiều'] as $n => $name) {
            $made[] = wordsProduct($name, 'VB-'.$n);
        }

        return $made;
    });
    $admin = DB::connection('pgsql_admin');
    $key = RebuildProductSearchWords::cursorKey(1);
    $run = fn (int $rolling) => $this->artisan('catalog:search-words', ['--site' => 1, '--rolling' => $rolling, '--chunk' => 2]);

    // from the start: two whole chunks, then nothing after the last product, so the run stops there
    $admin->table('product_search_words')->where('product_id', $products[1]->id)->delete();
    $run(10)->expectsOutputToContain('Checked 4 product(s) on 1 site(s); corrected 1.')->assertSuccessful();
    expect(Cache::get($key))->toBe(0);

    // a run that ends exactly on the last product leaves the cursor there
    $run(4)->expectsOutputToContain('Checked 4 product(s) on 1 site(s); corrected 0.')->assertSuccessful();
    expect(Cache::get($key))->toBe($products[3]->id);

    // the next run finds nothing after it, starts again from the first one and still checks each once
    $admin->table('product_search_words')->where('product_id', $products[2]->id)->delete();
    $run(10)->expectsOutputToContain('Checked 4 product(s) on 1 site(s); corrected 1.')->assertSuccessful();
    expect(Cache::get($key))->toBe(0);
});

/**
 * The tables a write first takes a row lock on, in order: P for the product row (its update, or the
 * FOR NO KEY UPDATE lock), T for one of its translations.
 *
 * @return list<string>
 */
function productLockOrder(Closure $write): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $write();
        $order = [];
        foreach (DB::getQueryLog() as $query) {
            $sql = strtolower($query['query']);
            if (preg_match('/^(?:update|insert into|delete from) "product_translations"/', $sql) === 1) {
                $order[] = 'T';
            } elseif (preg_match('/^update "products"|^select .* from products where .* for no key update$/', $sql) === 1) {
                $order[] = 'P';
            }
        }

        return array_values(array_unique($order));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('locks the product row before its translation in every write of a name or an address', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-lock-manager', ['catalog.view', 'catalog.manage']);
        $product = wordsProduct('Bàn ren', 'BR-1', 'draft');

        expect(productLockOrder(fn () => app(RenameProduct::class)->handle($product, 'Bàn ren tay', 'vi')))->toBe(['P', 'T'])
            ->and(productLockOrder(fn () => app(ChangeProductSlug::class)->handle($product, 'ban-ren-tay', 'vi')))->toBe(['P', 'T'])
            // only the name changes: the product's own update writes nothing
            ->and(productLockOrder(fn () => Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $product->ulid])
                ->set('name', 'Bàn ren máy')->call('save')->assertHasNoErrors()))->toBe(['P', 'T'])
            ->and(storedWords($product))->toContain('may')->not->toContain('tay');
    });
});

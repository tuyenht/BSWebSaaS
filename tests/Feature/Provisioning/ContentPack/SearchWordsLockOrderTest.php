<?php

use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\EntryTranslation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A content pack load that changes only a product's summary or a post's excerpt rewrites their
 * storefront search words, and locks the item row (product, entry) before it writes the translation,
 * the order every editor keeps: a load and an editor saving the same item wait for each other instead
 * of deadlocking. The statement order is asserted, as in tests/Concurrency.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/**
 * Every statement the callback issues, lower case, in order.
 *
 * @return list<string>
 */
function cpswStatements(Closure $callback): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower(trim($query->sql));
    });
    $callback();

    return $statements;
}

/**
 * The index of the first statement matching $pattern from $from on, or -1.
 *
 * @param  list<string>  $statements
 */
function cpswFirst(array $statements, string $pattern, int $from = 0): int
{
    foreach ($statements as $i => $sql) {
        if ($i >= $from && preg_match($pattern, $sql) === 1) {
            return $i;
        }
    }

    return -1;
}

it('locks the product and the post before writing a changed summary or excerpt, and rewrites their words', function (): void {
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    ContentPacks::item($this->folder, 'products.json', 'product:may-mau-a', fn (array $item): array => ['summary' => ['vi' => 'Máy mẫu chống gỉ.']] + $item);
    ContentPacks::item($this->folder, 'posts.json', 'post:thiet-ke-mau', fn (array $item): array => ['excerpt' => ['vi' => 'Dịch vụ khuôn đúc.']] + $item);

    $statements = cpswStatements(function (): void {
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output);
    });
    $all = implode("\n", $statements);

    $productWrite = cpswFirst($statements, '~^update "product_translations"~');
    $productLock = cpswFirst($statements, '~^select sku from products where .*for no key update$~');
    expect($productWrite)->toBeGreaterThan(-1, $all)
        ->and($productLock)->toBeGreaterThan(-1)
        ->and($productLock)->toBeLessThan($productWrite);

    $entryWrite = cpswFirst($statements, '~^update "entry_translations"~');
    $entryLock = cpswFirst($statements, '~^select .* from "entries" where .*for (no key )?update$~');
    expect($entryWrite)->toBeGreaterThan(-1, $all)
        ->and($entryLock)->toBeGreaterThan(-1)
        ->and($entryLock)->toBeLessThan($entryWrite);

    ContentPacks::in($this->siteId, function (): void {
        $productId = (int) ProductTranslation::query()->where('slug', 'may-mau-a')->value('product_id');
        $entryId = (int) EntryTranslation::query()->where('slug', 'thiet-ke-mau')->value('entry_id');
        expect(siteWordsOf('product_site_words', $this->siteId, $productId)['vi'] ?? [])->toHaveKey('chong', 3)->toHaveKey('gi', 3)
            ->and(siteWordsOf('entry_search_words', $this->siteId, $entryId)['vi'] ?? [])->toHaveKey('khuon', 3)->toHaveKey('duc', 3);
    });
});

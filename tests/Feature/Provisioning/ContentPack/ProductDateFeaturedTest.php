<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Provisioning\ContentPack\CanonicalHash;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Provisioning\ContentPack\PackReader;
use App\Modules\Provisioning\ContentPack\PackValidator;
use App\Modules\Provisioning\ContentPack\Planner;
use App\Modules\Tenancy\Models\Site;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A product's publication date and featured flag in a content pack: what the validator accepts,
 * what the apply writes, a second identical load planned as unchanged, a value staff changed left
 * alone until forced, and an item that names neither keeping the live values. The same rules a
 * post's date follows (LoadContentPackTest).
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

/** @return array{date: string|null, featured: bool} the product behind a slug: its date (Y-m-d, UTC) and flag */
function cppdProduct(int $siteId, string $slug = 'may-mau-a'): array
{
    return ContentPacks::in($siteId, function () use ($slug): array {
        $product = Product::query()->whereKey(ProductTranslation::query()->where('slug', $slug)->value('product_id'))->firstOrFail();

        return ['date' => $product->published_at?->copy()->utc()->format('Y-m-d'), 'featured' => $product->is_featured];
    });
}

/** @param array<string, mixed> $values */
function cppdSetProduct(int $siteId, array $values, string $slug = 'may-mau-a'): void
{
    ContentPacks::in($siteId, fn () => Product::query()
        ->whereKey(ProductTranslation::query()->where('slug', $slug)->value('product_id'))
        ->update($values));
}

/** @param array<string, mixed> $fields */
function cppdItem(string $folder, array $fields, string $key = 'product:may-mau-a'): void
{
    ContentPacks::item($folder, 'products.json', $key, fn (array $item): array => $fields + $item);
}

/** @return list<string> */
function cppdProblems(): array
{
    return app(PackValidator::class)->validate(app(PackReader::class)->read('demo-co'), 'demo-co');
}

/** Rewrite one item's ledger line as a load made before the date and the flag were managed recorded it. */
function cppdLegacyLedger(int $siteId, string $key = 'product:may-mau-a'): void
{
    ContentPacks::in($siteId, function () use ($key): void {
        $plan = app(Planner::class)->plan(app(PackReader::class)->read('demo-co'), Site::query()->value('ulid'), 'Demo', ContentPacks::HOST);
        $current = $plan->item($key)?->current ?? [];
        unset($current['published_at'], $current['featured']);
        $ledger = Ledger::read('demo-co');
        $items = $ledger['items'];
        $items[$key]['hash'] = CanonicalHash::of($current);
        Ledger::write('demo-co', $ledger['pack_version'], $items, null);
    });
}

it('accepts a product date and featured flag and refuses malformed ones', function (array $fields, ?string $problem): void {
    cppdItem($this->folder, $fields);

    $problems = implode("\n", cppdProblems());
    $problem === null
        ? expect($problems)->toBe('')
        : expect($problems)->toContain("product:may-mau-a: {$problem}");
})->with([
    'a date and the flag' => [['published_at' => '2026-11-10', 'featured' => true], null],
    'the flag off' => [['featured' => false], null],
    'a date with a time' => [['published_at' => '2026-11-10 08:00'], 'published_at must be a date (YYYY-MM-DD).'],
    'a day that does not exist' => [['published_at' => '2026-02-30'], 'published_at must be a date (YYYY-MM-DD).'],
    'a number' => [['published_at' => 20261110], 'published_at must be a date (YYYY-MM-DD).'],
    'the flag as text' => [['featured' => 'yes'], 'featured must be true or false.'],
    'the flag as a number' => [['featured' => 1], 'featured must be true or false.'],
]);

it('creates a product with the date and flag its pack item states, then plans it unchanged', function (): void {
    cppdItem($this->folder, ['published_at' => '2024-03-05', 'featured' => true]);

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(cppdProduct($this->siteId))->toBe(['date' => '2024-03-05', 'featured' => true]);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'product:may-mau-a'))->toBe('unchanged');
});

it('moves a loaded product to the stated date and flag, and the dry run names both before the apply writes them', function (): void {
    ContentPacks::load();
    expect(cppdProduct($this->siteId)['featured'])->toBeFalse();

    cppdItem($this->folder, ['published_at' => '2024-03-05', 'featured' => true]);

    $writes = ContentPacks::writes(function () use (&$output): void {
        [, $output] = ContentPacks::run('demo-co', ['-vv' => true]);
    });
    expect($writes)->toBe([])
        ->and(ContentPacks::verdict($output, 'product:may-mau-a'))->toBe('update')
        ->and($output)->toContain('published_at')->toContain('featured');

    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output)
        ->and(cppdProduct($this->siteId))->toBe(['date' => '2024-03-05', 'featured' => true]);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'product:may-mau-a'))->toBe('unchanged');
});

it('keeps the live date and flag of a product whose pack item states neither', function (): void {
    ContentPacks::load();
    cppdSetProduct($this->siteId, ['published_at' => '2025-07-07 08:00:00', 'is_featured' => true]);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'product:may-mau-a'))->toBe('unchanged');

    ContentPacks::load();
    expect(cppdProduct($this->siteId))->toBe(['date' => '2025-07-07', 'featured' => true]);
});

it('does not overwrite a date or flag staff changed since the last load, until the item is forced', function (array $staff): void {
    cppdItem($this->folder, ['published_at' => '2024-03-05', 'featured' => true]);
    ContentPacks::load();
    cppdSetProduct($this->siteId, $staff);
    $kept = cppdProduct($this->siteId);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'product:may-mau-a'))->toBe('skip:edited');
    ContentPacks::load();
    expect(cppdProduct($this->siteId))->toBe($kept);

    ContentPacks::load('demo-co', ['product:may-mau-a']);
    expect(cppdProduct($this->siteId))->toBe(['date' => '2024-03-05', 'featured' => true]);
})->with([
    'the flag turned off' => [['is_featured' => false]],
    'the date moved' => [['published_at' => '2025-07-07 08:00:00']],
]);

it('plans a product loaded before the date and flag were managed as an update and writes both', function (): void {
    ContentPacks::load();
    cppdLegacyLedger($this->siteId);
    cppdItem($this->folder, ['published_at' => '2024-03-05', 'featured' => true]);

    [, $output] = ContentPacks::run();
    expect(ContentPacks::verdict($output, 'product:may-mau-a'))->toBe('update');

    ContentPacks::load();
    expect(cppdProduct($this->siteId))->toBe(['date' => '2024-03-05', 'featured' => true]);
});

it('leaves the date of a product alone when its pack item is a draft that states one', function (): void {
    ContentPacks::load();
    cppdSetProduct($this->siteId, ['published_at' => '2025-07-07 08:00:00']);
    cppdItem($this->folder, ['published_at' => '2024-03-05', 'status' => 'draft']);

    ContentPacks::load();
    expect(cppdProduct($this->siteId)['date'])->toBe('2025-07-07');
});

<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A forced reload of a content pack never puts a post or a product past its end time
 * («Gỡ khỏi website lúc») back on the website: it keeps the status and the dates it has, so a
 * pack date later than the end time cannot cross it either.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();
});

afterEach(fn () => ContentPacks::cleanup());

it('keeps an item past its end time off the website at a forced reload, with its status and dates', function (string $key, string $file, string $model, string $translation, string $slug, string $field): void {
    ContentPacks::load();
    $published = now()->subDays(5)->startOfSecond();
    $expires = now()->subDays(2)->startOfSecond();
    $find = fn () => $model::query()->whereKey($translation::query()->where('slug', $slug)->value($model === Product::class ? 'product_id' : 'entry_id'))->firstOrFail();
    ContentPacks::in($this->siteId, fn () => $find()->forceFill(['status' => 'archived', 'published_at' => $published, 'expires_at' => $expires])->save());
    // a date after the end time (putting it back up with it would break the end-after-publish rule),
    // and a new title, so the reload writes the item
    ContentPacks::item($this->folder, $file, $key, function (array $item) use ($field): array {
        $item[$field] = array_map(fn (string $text): string => $text.' (mới)', $item[$field]);

        return ['published_at' => now()->subDay()->utc()->format('Y-m-d')] + $item;
    });

    // the plan names the status and the date the item keeps, in plain words, so the read-back passes
    [, $plan] = ContentPacks::run('demo-co', ['--force-item' => [$key]]);
    expect($plan)->toContain('past its end time: keeps its status (archived) and its dates');

    [$exit, $output] = ContentPacks::load('demo-co', [$key]);

    expect($exit)->toBe(0, $output)->and($output)->toContain('1 items written');
    ContentPacks::in($this->siteId, function () use ($find, $published, $expires, $model): void {
        $row = $find();
        expect($row->status)->toBe('archived')
            ->and($row->published_at?->equalTo($published))->toBeTrue()
            ->and($row->expires_at?->equalTo($expires))->toBeTrue()
            ->and($row->translations()->where('locale', 'vi')->value($model === Product::class ? 'name' : 'title'))->toEndWith(' (mới)');
    });
})->with([
    'a post' => ['post:ra-mat-website', 'posts.json', Entry::class, EntryTranslation::class, 'ra-mat-website', 'title'],
    'a product' => ['product:may-mau-a', 'products.json', Product::class, ProductTranslation::class, 'may-mau-a', 'name'],
]);

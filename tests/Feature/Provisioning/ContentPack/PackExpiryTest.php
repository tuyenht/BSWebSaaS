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

it('never puts an item past its end time back on the website at a forced reload, its status and dates untouched', function (string $key, string $file, string $model, string $translation, string $slug): void {
    ContentPacks::load();
    $published = now()->subDays(5)->startOfSecond();
    $expires = now()->subDays(2)->startOfSecond();
    $find = fn () => $model::query()->whereKey($translation::query()->where('slug', $slug)->value($model === Product::class ? 'product_id' : 'entry_id'))->firstOrFail();
    ContentPacks::in($this->siteId, fn () => $find()->forceFill(['status' => 'archived', 'published_at' => $published, 'expires_at' => $expires])->save());
    // a date after the end time: putting it back up with it would break the end-after-publish rule
    ContentPacks::item($this->folder, $file, $key, fn (array $item): array => ['published_at' => now()->subDay()->utc()->format('Y-m-d')] + $item);

    [$exit, $output] = ContentPacks::load('demo-co', [$key]);

    // the plan still names the pack's status and date, so the read-back refuses the load as a whole
    expect($exit)->not->toBe(0)->and($output)->toContain('did not read back as planned');
    ContentPacks::in($this->siteId, function () use ($find, $published, $expires): void {
        $row = $find();
        expect($row->status)->toBe('archived')
            ->and($row->published_at?->equalTo($published))->toBeTrue()
            ->and($row->expires_at?->equalTo($expires))->toBeTrue();
    });
})->with([
    'a post' => ['post:ra-mat-website', 'posts.json', Entry::class, EntryTranslation::class, 'ra-mat-website'],
    'a product' => ['product:may-mau-a', 'products.json', Product::class, ProductTranslation::class, 'may-mau-a'],
]);

<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Catalog\Support\ProductGallery;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Provisioning\Actions\PromoteAnVinhProductPostsToCatalog;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * The hidden content mark of a product language (product_translations.content_changed_at): it moves
 * when the product page's main content changes (its text fields, its main category, the pictures it
 * shows, whoever writes them, and a picture's file going into or out of the media trash), never for
 * its address or another category, and only on the current site's rows.
 */

const PRODUCT_MARK_OLD = '2026-01-02 03:04:05+00';
const PRODUCT_MARK_OLD_ISO = '2026-01-02T03:04:05+00:00';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * A draft product with a vi translation, in the current site; returns [product, translation].
 *
 * @return array{0: Product, 1: ProductTranslation}
 */
function markProduct(string $slug = 'do-ga-kiem-tra'): array
{
    $product = Product::query()->create(['status' => 'draft']);
    $translation = $product->translations()->create([
        'locale' => 'vi', 'name' => 'Đồ gá kiểm tra độ', 'slug' => $slug,
        'summary' => 'Ước tính độ chính xác ở', 'description' => '<p>Đồ gá kiểm tra độ chính xác ở</p>',
    ]);

    return [$product->fresh(), $translation->fresh()];
}

/** A live library image of the current site. */
function markProductMedia(string $suffix): Media
{
    return Media::query()->create([
        'collection' => 'library', 'slug' => "anh-{$suffix}", 'hash' => hash('sha256', $suffix),
        'storage_key' => "s/library/anh-{$suffix}.png", 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
    ]);
}

/** A product category with a vi name, in the current site. */
function markProductCategory(string $name, string $slug): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category;
}

/** Every product language of the current site: its mark (and updated_at) set back to the old time, past the model. */
function pinProductMarks(): void
{
    DB::table('product_translations')->update(['content_changed_at' => PRODUCT_MARK_OLD, 'updated_at' => PRODUCT_MARK_OLD]);
}

/** The stored mark of one product language of the current site, as ISO 8601 UTC (null: none). */
function productMarkOf(int $id): ?string
{
    $value = DB::table('product_translations')->where('id', $id)->value('content_changed_at');

    return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
}

it('moves the mark when a field of the main content changes', function (array $change): void {
    app(Tenancy::class)->enter(1, function () use ($change): void {
        [, $translation] = markProduct();
        expect(productMarkOf($translation->id))->not->toBeNull();   // a new row starts marked
        pinProductMarks();

        $translation->refresh()->update($change);

        expect(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO);
    });
})->with([
    'the name' => [['name' => 'Ấn bản đồ gá mới về phố']],
    'the summary' => [['summary' => 'Ước tính độ chính xác mới ở']],
    'the description' => [['description' => '<p>Đồ gá kiểm tra mới ở</p>']],
    'the title for Google' => [['seo_title' => 'Ấn bản cho Google về phố']],
    'the description for Google' => [['seo_description' => 'Ước tính mô tả cho Google ở']],
]);

it('leaves the mark for a new address and writes nothing for a save that changes nothing', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$product, $translation] = markProduct();
        pinProductMarks();

        $translation->refresh()->update(['slug' => 'do-ga-dia-chi-moi']);
        $product->update(['sku' => 'ĐG-01', 'price' => 1_500_000, 'is_featured' => true]);
        expect(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);

        $translation->refresh()->fill(['name' => $translation->name, 'description' => $translation->description]);
        expect(countQueries(fn () => $translation->save()))->toBe(0);
    });
});

it('leaves the mark through publish, archive, unpublish, republish, the trash and a restore', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$product, $translation] = markProduct('do-ga-trang-thai');
        pinProductMarks();

        $status = app(ChangeProductStatus::class);
        $status->handle($product->fresh(), 'published');
        $status->handle($product->fresh(), 'archived');
        $status->handle($product->fresh(), 'draft');
        $status->handle($product->fresh(), 'published');
        $product->fresh()->delete();
        Product::withTrashed()->findOrFail($product->id)->restore();

        $stored = Product::query()->findOrFail($product->id);
        expect($stored->status)->toBe('published')
            ->and($stored->published_at)->not->toBeNull()
            ->and(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);
    });
});

it('moves the mark when the main category changes, never for another category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $main = markProductCategory('Đồ gá kiểm tra độ', 'do-ga');
        $other = markProductCategory('Ống thép mạ kẽm ở', 'ong-thep');
        [$product, $translation] = markProduct();
        $product->assignCategories([$main->id]);
        pinProductMarks();

        $product->assignCategories([$main->id, $other->id]);
        expect(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);

        $product->assignCategories([$other->id]);
        expect(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO);
    });
});

it('moves the mark of every language when the pictures the page shows are added, moved or removed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$product, $vi] = markProduct();
        $en = $product->translations()->create(['locale' => 'en', 'name' => 'Ấn bản tiếng Anh ở', 'slug' => 'do-ga-en']);
        [, $other] = markProduct('khuon-dap');
        $first = markProductMedia('a');
        $second = markProductMedia('b');
        $gallery = app(ProductGallery::class);

        foreach ([[$first->id], [$first->id, $second->id], [$second->id, $first->id], [$second->id], []] as $pictures) {
            pinProductMarks();
            $gallery->sync($product, $pictures);

            expect(ProductImage::query()->where('product_id', $product->id)->orderBy('position')->pluck('media_id')->all())->toBe($pictures)
                ->and(productMarkOf($vi->id))->not->toBe(PRODUCT_MARK_OLD_ISO)
                ->and(productMarkOf($en->id))->not->toBe(PRODUCT_MARK_OLD_ISO)
                ->and(productMarkOf($other->id))->toBe(PRODUCT_MARK_OLD_ISO);
        }
    });
});

it('leaves the mark when the gallery stays the same or only a picture the page hides goes', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$product, $translation] = markProduct();
        $shown = markProductMedia('a');
        $trashed = markProductMedia('b');
        $gallery = app(ProductGallery::class);
        $gallery->sync($product, [$shown->id, $trashed->id]);
        $trashed->delete();
        pinProductMarks();

        $gallery->sync($product, [$shown->id, $trashed->id]);
        expect(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);

        $gallery->sync($product, [$shown->id]);
        expect(ProductImage::query()->where('product_id', $product->id)->pluck('media_id')->all())->toBe([$shown->id])
            ->and(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);
    });
});

it('moves the mark when a gallery picture goes into the media trash, comes back, or is deleted while live', function (): void {
    Storage::fake('r2');

    app(Tenancy::class)->enter(1, function (): void {
        [$product, $translation] = markProduct();
        [, $other] = markProduct('khuon-dap');
        $picture = markProductMedia('a');
        $unused = markProductMedia('c');
        app(ProductGallery::class)->sync($product, [$picture->id]);

        pinProductMarks();
        $picture->delete();
        expect(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO)
            ->and(productMarkOf($other->id))->toBe(PRODUCT_MARK_OLD_ISO);

        pinProductMarks();
        Media::onlyTrashed()->findOrFail($picture->id)->restore();
        expect(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO);

        // Purged from the trash: the picture already left the page when it was trashed.
        Media::query()->findOrFail($picture->id)->delete();
        pinProductMarks();
        Media::onlyTrashed()->findOrFail($picture->id)->forceDelete();
        expect(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);

        // Deleted for good while still live: the picture leaves the page now.
        $live = markProductMedia('d');
        app(ProductGallery::class)->sync($product, [$live->id]);
        pinProductMarks();
        $live->forceDelete();
        expect(ProductImage::query()->where('product_id', $product->id)->count())->toBe(0)
            ->and(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO);

        // A file no gallery holds marks nothing.
        pinProductMarks();
        $unused->delete();
        expect(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);
    });
});

it('marks only the gallery products of the media file\'s own site', function (): void {
    $picture = app(Tenancy::class)->enter(1, fn (): Media => markProductMedia('a'));
    $otherText = app(Tenancy::class)->enter(2, function () use ($picture): ProductTranslation {
        [$product, $translation] = markProduct('do-ga-site-khac');
        // A row of the other site pointing at this file (no write path makes one; the mark must not follow it).
        ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $picture->id, 'position' => 0]);
        pinProductMarks();

        return $translation;
    });

    app(Tenancy::class)->enter(1, function () use ($picture): void {
        [$product, $translation] = markProduct();
        app(ProductGallery::class)->sync($product, [$picture->id]);
        pinProductMarks();

        Media::query()->findOrFail($picture->id)->delete();

        expect(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO);
    });
    app(Tenancy::class)->enter(2, fn () => expect(productMarkOf($otherText->id))->toBe(PRODUCT_MARK_OLD_ISO));
});

it('moves the mark when the product editor changes the gallery, and not on a save that changes nothing', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);
        $first = markProductMedia('a');
        $second = markProductMedia('b');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá kiểm tra độ')
            ->set('slug', 'do-ga-kiem-tra')
            ->call('pickImage', $first->id)
            ->call('pickImage', $second->id)
            ->call('save')
            ->assertHasNoErrors();
        $product = Product::query()->firstOrFail();
        $translation = ProductTranslation::query()->where('product_id', $product->id)->firstOrFail();

        pinProductMarks();
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->call('save')
            ->assertHasNoErrors();
        expect(productMarkOf($translation->id))->toBe(PRODUCT_MARK_OLD_ISO);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->call('moveImageDown', 0)
            ->call('save')
            ->assertHasNoErrors();
        expect(ProductImage::query()->where('product_id', $product->id)->orderBy('position')->pluck('media_id')->all())->toBe([$second->id, $first->id])
            ->and(productMarkOf($translation->id))->not->toBe(PRODUCT_MARK_OLD_ISO);
    });
});

it('marks the rows a content pack creates, and moves the mark when a pack changes the gallery', function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $folder = ContentPacks::use();
    $siteId = ContentPacks::site();

    try {
        ContentPacks::in($siteId, fn () => StorefrontCards::media('product-a'));
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output);

        $translationId = ContentPacks::in($siteId, function (): int {
            expect(DB::table('entry_translations')->whereNull('content_changed_at')->count())->toBe(0)
                ->and(DB::table('product_translations')->whereNull('content_changed_at')->count())->toBe(0);
            $product = Product::query()->where('status', 'published')->firstOrFail();
            expect($product->images()->count())->toBe(1);
            // The mark alone: the pack's own read-back of the product stays as the first load left it.
            DB::table('product_translations')->update(['content_changed_at' => PRODUCT_MARK_OLD]);

            return (int) ProductTranslation::query()->where('product_id', $product->id)->value('id');
        });

        ContentPacks::item($folder, 'products.json', 'product:may-mau-a', fn (array $item): array => ['images' => []] + $item);
        [$exit, $output] = ContentPacks::load();
        expect($exit)->toBe(0, $output);

        ContentPacks::in($siteId, fn () => expect(productMarkOf($translationId))->not->toBe(PRODUCT_MARK_OLD_ISO));
    } finally {
        ContentPacks::cleanup();
    }
});

it('moves the mark when the An Vinh promotion gives an existing product its cover', function (): void {
    StorefrontCards::reset();
    $siteId = StorefrontCards::site('Promo Mẫu', 'promo-mark.platform.test');

    $enId = app(Tenancy::class)->enter($siteId, function (): int {
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'products'], ['value' => ['pattern' => 'category']]);
        $sanPham = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $sanPham->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm', 'slug' => 'san-pham']);
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'entry_type' => 'post', 'taxonomy_id' => $sanPham->id]);
        $jigs = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'parent_id' => $sanPham->id, 'position' => 0]);
        $jigs->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);

        $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now(), 'meta' => ['image_url' => 'an-vinh/san-pham/sp-jig-1-abc123def456.jpg']]);
        $post->translations()->create([
            'locale' => 'vi', 'title' => 'Ấn bản đồ gá kiểm về phố', 'slug' => 'jig-kiem-1', 'status' => 'published',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Đồ gá kiểm', 'paragraphs' => ['Độ chính xác cao ở']]]]],
        ]);
        $post->assignCategories([$sanPham->id, $jigs->id]);
        app(RouteSync::class)->sync($post, 'vi', 'jig-kiem-1');

        // The product is already there, in its category and with another language the run does not write.
        $product = Product::query()->create(['status' => 'draft']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Tên cũ ở', 'slug' => 'jig-kiem-1']);
        $en = $product->translations()->create(['locale' => 'en', 'name' => 'Ấn bản tiếng Anh ở', 'slug' => 'jig-check-1']);
        $product->assignCategories([$jigs->id]);
        pinProductMarks();

        return $en->id;
    });
    app()->forgetScopedInstances();

    app(Tenancy::class)->enter($siteId, fn (): array => app(PromoteAnVinhProductPostsToCatalog::class)->handle());

    app(Tenancy::class)->enter($siteId, function () use ($enId): void {
        $product = Product::query()->whereKey(ProductTranslation::query()->whereKey($enId)->value('product_id'))->firstOrFail();
        expect($product->images()->count())->toBe(1)
            ->and(productMarkOf($enId))->not->toBe(PRODUCT_MARK_OLD_ISO);
    });
});

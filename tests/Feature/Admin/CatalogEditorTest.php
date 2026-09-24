<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * P1 slice-2a — the catalog product editor: a structured form (no block-engine) persisting the
 * product row + its default-locale translation + the product_cat pivot, every row stamped with
 * the current site_id (§2). Create/edit are gated by catalog.manage at the route AND by
 * CatalogPolicy in mount (the livewire/update endpoint bypasses route middleware). Uniqueness
 * (slug per locale, sku per site) is validated app-side so the partial UNIQUE(site_id, sku)
 * index never surfaces as a raw 500.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Seed a localized product_cat category in the CURRENT site context; returns its id. */
function seedProductCategory(string $slug = 'do-ga', string $name = 'Đồ gá'): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category->id;
}

/** Seed a live library image in the CURRENT site context; returns its id. */
function seedCatalogMedia(string $suffix = 'a'): int
{
    $media = Media::query()->create([
        'collection' => 'library',
        'slug' => "anh-{$suffix}",
        'hash' => str_repeat($suffix, 64),
        'storage_key' => "s/library/anh-{$suffix}.png",
        'disk' => 'r2',
        'mime' => 'image/png',
        'size' => 1024,
    ]);

    return $media->id;
}

it('gates the create and edit routes — 200 with catalog.manage, 403 with view only', function (): void {
    $manager = User::factory()->create();
    $viewer = User::factory()->create();

    $ulid = app(Tenancy::class)->enter(1, function () use ($manager, $viewer): string {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        return Product::query()->create(['status' => 'draft'])->ulid;
    });

    // 200s also prove the component is registered with Livewire (an unregistered
    // admin component renders as a 500 "Unable to find component").
    $this->actingAs($manager->fresh())->get('http://a.test/admin/catalog/create')->assertOk();
    $this->actingAs($manager->fresh())->get("http://a.test/admin/catalog/{$ulid}/edit")->assertOk();
    $this->actingAs($viewer->fresh())->get('http://a.test/admin/catalog/create')->assertForbidden();
    $this->actingAs($viewer->fresh())->get("http://a.test/admin/catalog/{$ulid}/edit")->assertForbidden();
});

it('refuses non-managers at the component itself (livewire/update bypasses route middleware)', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'cat-viewer', ['catalog.view']);

        Livewire::actingAs($viewer->fresh())->test(Editor::class)->assertForbidden();
    });
});

it('creates a product with its translation and category pivot, all stamped with the site id', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);
        $categoryId = seedProductCategory();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá hàn khung')
            ->set('slug', 'do-ga-han-khung')
            ->set('description', '<p>Thép C45, dung sai ±0.01mm</p>')
            ->set('categoryId', $categoryId)
            ->set('price', '25000000')
            ->set('sku', 'DG-001')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->first();
        expect($product)->not->toBeNull()
            ->and($product->site_id)->toBe(1)
            ->and($product->status)->toBe('published')
            ->and($product->sku)->toBe('DG-001')
            ->and($product->price)->toBe(25000000);

        $translation = $product->translationFor('vi');
        expect($translation)->not->toBeNull()
            ->and($translation?->name)->toBe('Đồ gá hàn khung')
            ->and($translation?->slug)->toBe('do-ga-han-khung')
            ->and($translation?->description)->toBe('<p>Thép C45, dung sai ±0.01mm</p>')
            ->and($translation?->site_id)->toBe(1);

        $pivot = DB::table('product_taxonomies')->where('product_id', $product->id)->first();
        expect($pivot)->not->toBeNull()
            ->and((int) $pivot->site_id)->toBe(1)
            ->and((int) $pivot->taxonomy_id)->toBe($categoryId);
    });
});

it('loads an existing product into the form and updates it in place', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        $product = Product::query()->create(['status' => 'draft', 'sku' => 'DG-001', 'price' => 1000000]);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga', 'description' => 'Cũ']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSet('name', 'Đồ gá')
            ->assertSet('slug', 'do-ga')
            ->assertSet('price', '1000000')
            ->assertSet('sku', 'DG-001')
            ->set('name', 'Đồ gá CNC')
            ->call('save')
            ->assertHasNoErrors();

        expect(Product::query()->count())->toBe(1)             // updated in place, no new product
            ->and($product->translations()->count())->toBe(1)  // updateOrCreate, no duplicate row
            ->and($product->translationFor('vi')?->name)->toBe('Đồ gá CNC');
    });
});

it('rejects a duplicate slug and a duplicate sku with friendly errors', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        $existing = Product::query()->create(['status' => 'draft', 'sku' => 'DG-001']);
        $existing->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm khác')
            ->set('slug', 'do-ga')     // taken by $existing
            ->set('sku', 'DG-001')     // taken by $existing
            ->call('save')
            ->assertHasErrors(['slug', 'sku']);

        expect(Product::query()->count())->toBe(1); // nothing new persisted

        // Saving the existing product with its own slug/sku is not a self-collision.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $existing->ulid])
            ->call('save')
            ->assertHasNoErrors();
    });
});

it('cannot attach a category from another site (RLS-scoped exists rule)', function (): void {
    $editor = User::factory()->create();
    $foreignCategory = app(Tenancy::class)->enter(2, fn (): int => seedProductCategory('cat-b', 'Cat B'));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreignCategory): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm')
            ->set('slug', 'san-pham')
            ->set('categoryId', $foreignCategory) // lives in site 2 — invisible under RLS
            ->call('save')
            ->assertHasErrors('categoryId');

        expect(Product::query()->count())->toBe(0);
    });
});

it('sanitizes rich text on write — scripts and junk styles out, allowlisted markup in', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá')
            ->set('slug', 'do-ga')
            ->set('description', '<script>alert(1)</script><p style="font-size:14pt;position:absolute">Chi tiết</p>'
                .'<table><tbody><tr><td style="color:red">±0.01mm</td></tr></tbody></table>'
                .'<p>m<sup>2</sup> CO<sub>2</sub></p><img src="https://files.baoson.net/x.png" alt="jig">'
                .'<iframe src="https://www.youtube.com/embed/abc123"></iframe>'
                .'<iframe src="https://evil.example.com/steal"></iframe>')
            ->set('summary', '<h2>To</h2><strong>đậm</strong><a href="javascript:alert(1)">x</a>')
            ->call('save')
            ->assertHasNoErrors();

        $translation = Product::query()->first()?->translationFor('vi');
        expect($translation)->not->toBeNull()
            // full profile: script gone entirely, table + allowlisted styles kept, others stripped
            ->and($translation?->description)->not->toContain('<script')
            ->and($translation?->description)->toContain('<table')
            ->and($translation?->description)->toContain('font-size')
            ->and($translation?->description)->toContain('color:#FF0000') // purifier canonicalizes `red`
            ->and($translation?->description)->not->toContain('position')
            // technical units + library image survive; only youtube/vimeo iframes do
            ->and($translation?->description)->toContain('<sup>2</sup>')
            ->and($translation?->description)->toContain('<sub>2</sub>')
            ->and($translation?->description)->toContain('<img src="https://files.baoson.net/x.png"')
            ->and($translation?->description)->toContain('youtube.com/embed/abc123')
            ->and($translation?->description)->not->toContain('evil.example.com')
            // basic profile: no headings, inline emphasis kept, javascript: URI stripped
            ->and($translation?->summary)->not->toContain('<h2')
            ->and($translation?->summary)->toContain('<strong>đậm</strong>')
            ->and($translation?->summary)->not->toContain('javascript:');
    });
});

it('editor-mode picking dispatches the insert event and never touches the gallery', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);
        $media = seedCatalogMedia('a');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openImagePicker', 'editor')
            ->assertSet('imagePickerMode', 'editor')
            ->call('pickImage', $media)
            ->assertDispatched('admin-editor-insert-image')
            ->assertSet('imageMediaIds', [])          // gallery untouched
            ->assertSet('showImagePicker', false);    // editor mode closes after inserting
    });
});

it('the video dialog builds the canonical embed server-side and rejects junk', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->call('openVideoDialog')
            ->assertSet('showVideoDialog', true)
            ->set('videoUrl', 'https://evil.example.com/embed/xxx')
            ->call('insertVideo')
            ->assertHasErrors('videoUrl')
            ->assertNotDispatched('admin-editor-insert-html')
            ->set('videoUrl', 'https://www.youtube.com/watch?v=SxwkhMy4Ywo')
            ->set('videoAutoplay', true)
            ->call('insertVideo')
            ->assertDispatched('admin-editor-insert-html')
            ->assertSet('showVideoDialog', false)
            ->assertSet('videoUrl', ''); // form resets for the next embed
    });
});

it('persists and reloads the summary and SEO meta fields', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá')
            ->set('slug', 'do-ga')
            ->set('summary', '<p>Teaser <strong>ngắn</strong></p>')
            ->set('seoTitle', 'Đồ gá hàn | An Vinh')
            ->set('seoDescription', 'Đồ gá hàn chính xác cho khung máy.')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->first();
        $translation = $product?->translationFor('vi');
        expect($translation)->not->toBeNull()
            ->and($translation?->summary)->toBe('<p>Teaser <strong>ngắn</strong></p>')
            ->and($translation?->seo_title)->toBe('Đồ gá hàn | An Vinh')
            ->and($translation?->seo_description)->toBe('Đồ gá hàn chính xác cho khung máy.')
            ->and($translation?->site_id)->toBe(1);

        // The edit form round-trips them back into the component state.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product?->ulid])
            ->assertSet('summary', '<p>Teaser <strong>ngắn</strong></p>')
            ->assertSet('seoTitle', 'Đồ gá hàn | An Vinh')
            ->assertSet('seoDescription', 'Đồ gá hàn chính xác cho khung máy.');
    });
});

it('attaches gallery images in picked order with site-stamped rows (duplicates ignored)', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);
        $first = seedCatalogMedia('a');
        $second = seedCatalogMedia('b');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá')
            ->set('slug', 'do-ga')
            ->call('openImagePicker')
            ->call('pickImage', $first)
            ->call('pickImage', $second)
            ->call('pickImage', $first) // duplicate — ignored (UNIQUE guard, app-side)
            ->assertSet('imageMediaIds', [$first, $second])
            ->call('save')
            ->assertHasNoErrors();

        $rows = DB::table('product_images')->orderBy('position')->get();
        expect($rows)->toHaveCount(2)
            ->and((int) $rows[0]->media_id)->toBe($first)
            ->and((int) $rows[0]->position)->toBe(0)
            ->and((int) $rows[0]->site_id)->toBe(1) // §2: the row must carry the site
            ->and((int) $rows[1]->media_id)->toBe($second)
            ->and((int) $rows[1]->position)->toBe(1);
    });
});

it('reorders and removes gallery images across saves without tripping the unique index', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);
        $first = seedCatalogMedia('a');
        $second = seedCatalogMedia('b');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá')
            ->set('slug', 'do-ga')
            ->call('pickImage', $first)
            ->call('pickImage', $second)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->first();

        // Swap the order and save again — updateOrCreate must ride UNIQUE(product_id, media_id).
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product?->ulid])
            ->assertSet('imageMediaIds', [$first, $second])
            ->call('moveImageDown', 0)
            ->assertSet('imageMediaIds', [$second, $first])
            ->call('save')
            ->assertHasNoErrors();

        expect((int) DB::table('product_images')->where('media_id', $second)->value('position'))->toBe(0)
            ->and((int) DB::table('product_images')->where('media_id', $first)->value('position'))->toBe(1);

        // Remove the now-first image — its row is deleted, the survivor re-packs to position 0.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product?->ulid])
            ->call('removeImage', 0)
            ->call('save')
            ->assertHasNoErrors();

        $rows = DB::table('product_images')->get();
        expect($rows)->toHaveCount(1)
            ->and((int) $rows[0]->media_id)->toBe($first)
            ->and((int) $rows[0]->position)->toBe(0);
    });
});

it('rejects gallery ids from another site (RLS-scoped exists rule)', function (): void {
    $editor = User::factory()->create();
    $foreignMedia = app(Tenancy::class)->enter(2, fn (): int => seedCatalogMedia('c'));

    app(Tenancy::class)->enter(1, function () use ($editor, $foreignMedia): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);

        // Injected straight into the wire payload — the pickImage path never allows it, so the
        // validation rule is the net that must hold.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm')
            ->set('slug', 'san-pham')
            ->set('imageMediaIds', [$foreignMedia])
            ->call('save')
            ->assertHasErrors('imageMediaIds.0');

        expect(Product::query()->count())->toBe(0);
    });
});

it('keeps a trashed-media gallery row saveable, shows it as missing, and refuses re-picking trashed media', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage', 'media.view']);
        $attached = seedCatalogMedia('a');
        $trashedOther = seedCatalogMedia('b');

        $product = Product::query()->create(['status' => 'draft']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);
        $product->images()->create(['media_id' => $attached, 'position' => 0]);

        // Both media go to the Trash (soft delete).
        Media::query()->whereKey([$attached, $trashedOther])->get()->each->delete();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->assertSee(__('catalog.gallery_missing'))   // renders as missing, no broken <img>
            ->call('pickImage', $trashedOther)           // trashed → global scope refuses the ADD path
            ->assertSet('imageMediaIds', [$attached])
            ->call('save')                               // saving with the trashed-but-attached row must not block
            ->assertHasNoErrors();

        expect(DB::table('product_images')->count())->toBe(1); // the attached row survives (restore heals it)
    });
});

it('stores cleared price and sku as NULL — brochure rows share the partial sku index', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        // TWO skuless products: if '' were stored instead of NULL, the second save would
        // violate the partial UNIQUE(site_id, sku) index and blow up as a QueryException.
        foreach ([['SP một', 'sp-mot'], ['SP hai', 'sp-hai']] as [$name, $slug]) {
            Livewire::actingAs($editor->fresh())
                ->test(Editor::class)
                ->set('name', $name)
                ->set('slug', $slug)
                ->set('price', '')  // cleared number input arrives as ''
                ->set('sku', '')
                ->call('save')
                ->assertHasNoErrors();
        }

        expect(Product::query()->count())->toBe(2)
            ->and(Product::query()->whereNull('sku')->count())->toBe(2)
            ->and(Product::query()->whereNull('price')->count())->toBe(2);
    });
});

/** The stored public path of a product in $locale, or null when it has none. */
function productRoutePath(Product $product, string $locale = 'vi'): ?string
{
    $path = SiteRoute::query()->where('product_id', $product->id)->where('locale', $locale)->value('path');

    return is_string($path) ? $path : null;
}

it('gives a published product its public address and its first publication date', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá hàn khung')
            ->set('slug', 'do-ga-han-khung')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->firstOrFail();
        expect(productRoutePath($product))->toBe('/do-ga-han-khung')   // flat: the default preset
            ->and($product->published_at)->not->toBeNull();
    });
});

it('leaves a draft without an address until it is published', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Jig kiểm')
            ->set('slug', 'jig-kiem')
            ->set('status', 'draft')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->firstOrFail();
        expect(productRoutePath($product))->toBeNull()
            ->and($product->published_at)->toBeNull();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();

        expect(productRoutePath($product))->toBe('/jig-kiem');
    });
});

it('moves the address with a 301 when a published product is renamed, keeping its publication date', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Đồ gá')
            ->set('slug', 'do-ga-cu')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->firstOrFail();
        $publishedAt = $product->published_at?->toIso8601String();
        $this->travel(1)->hours();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->set('slug', 'do-ga-moi')
            ->call('save')
            ->assertHasNoErrors();

        expect(productRoutePath($product))->toBe('/do-ga-moi')
            ->and(Redirect::query()->where('from_path', '/do-ga-cu')->where('to_path', '/do-ga-moi')->where('status_code', 301)->exists())->toBeTrue()
            ->and($product->fresh()->published_at?->toIso8601String())->toBe($publishedAt);
    });
});

it('refuses an address a page already owns and keeps nothing of the save', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $editor->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($page->fresh(), 'vi', 'gioi-thieu');

        // A new product: nothing is created, and the form stays a create form.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm')
            ->set('slug', 'gioi-thieu')
            ->set('status', 'published')
            ->call('save')
            ->assertHasErrors(['slug'])
            ->assertSet('ulid', null);

        expect(Product::query()->count())->toBe(0);

        // A live product renamed onto the page's address: its slug and address stay as they were.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('name', 'Sản phẩm')
            ->set('slug', 'san-pham-a')
            ->set('status', 'published')
            ->call('save')
            ->assertHasNoErrors();
        $product = Product::query()->firstOrFail();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $product->ulid])
            ->set('slug', 'gioi-thieu')
            ->call('save')
            ->assertHasErrors(['slug']);

        expect($product->fresh()->translationFor('vi')?->slug)->toBe('san-pham-a')
            ->and(productRoutePath($product))->toBe('/san-pham-a');
    });
});

<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductTranslation;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLanguages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The product editor in every language of the site (board 09): `?locale=` opens one of the site's
 * enabled languages (anything else the default), each language loads and saves its own version of
 * the product's words, the product's own fields (pictures, price, stock, SKU, labels, categories,
 * status) are locked there and never written by a translation's save, and the language bar shows
 * the state of every version.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    enableCatalog();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** @param  list<string>  $permissions */
function plbMember(array $permissions = ['catalog.view', 'catalog.manage'], string $role = 'cat-manager'): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));

    return $user->fresh() ?? $user;
}

/** Turn these languages on for the current site (Vietnamese stays the default). */
function plbLanguages(string ...$codes): void
{
    foreach ($codes as $code) {
        SiteLocale::query()->updateOrCreate(['locale' => $code], ['is_default' => false, 'enabled' => true, 'is_public' => $code !== 'ko']);
    }
    app(SiteLanguages::class)->forget();
}

/** A version with every translatable field filled. */
function plbFull(string $name, string $slug): array
{
    return ['name' => $name, 'slug' => $slug, 'summary' => 'Tóm tắt '.$name, 'description' => '<p>Mô tả '.$name.'</p>',
        'seo_title' => 'SEO '.$name, 'seo_description' => 'Google '.$name];
}

/** A product category in the current site; returns it. */
function plbCategory(string $slug, string $name): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);
    $category->translations()->create(['locale' => 'en', 'name' => $name.' EN', 'slug' => $slug.'-en']);

    return $category;
}

/** A product with its Vietnamese version complete, a price, a SKU, a label, a category and a picture. */
function plbProduct(string $status = 'draft', string $slug = 'mui-khoan'): Product
{
    $product = Product::query()->create(['status' => $status, 'price' => 1250000, 'sale_price' => null, 'stock' => 12, 'sku' => 'AV-'.strtoupper($slug), 'is_featured' => true]);
    $product->translations()->create(['locale' => 'vi'] + plbFull('Mũi khoan', $slug));
    $product->assignCategories([plbCategory('dung-cu-'.$slug, 'Dụng cụ')->id]);
    $media = Media::query()->create(['collection' => 'library', 'slug' => 'anh-'.$slug, 'hash' => hash('sha256', $slug), 'storage_key' => 's/library/anh-'.$slug.'.png',
        'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024]);
    $product->images()->create(['media_id' => $media->id, 'position' => 0]);

    return $product->fresh() ?? $product;
}

/** @return array<string, array<string, mixed>> the bar's items by language */
function plbItems(mixed $component): array
{
    return collect($component->get('langBar'))->keyBy('code')->all();
}

/** @return array<string, mixed> the product's own columns, its categories and its pictures, as stored */
function plbShared(Product $product): array
{
    $row = DB::table('products')->where('id', $product->id)->first();

    return [
        'row' => collect((array) $row)->except([])->all(),
        'categories' => DB::table('product_taxonomies')->where('product_id', $product->id)->orderBy('position')->pluck('taxonomy_id')->all(),
        'images' => DB::table('product_images')->where('product_id', $product->id)->orderBy('position')->pluck('media_id')->all(),
    ];
}

it('opens the version of an enabled language, and the default for anything else', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en');
        $product = plbProduct();

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])->assertSet('locale', 'en')->assertSet('name', '');
        // not a language of the site, a language it has turned off, or no string at all: the default
        SiteLocale::query()->updateOrCreate(['locale' => 'fr'], ['is_default' => false, 'enabled' => false, 'is_public' => false]);
        app(SiteLanguages::class)->forget();
        foreach (['xx', 'fr', 'EN', ''] as $asked) {
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => $asked])->assertSet('locale', 'vi')->assertSet('name', 'Mũi khoan');
        }
        // the browser never changes the language a component writes
        expect(fn () => Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid])->set('locale', 'en'))->toThrow(Exception::class);
        // a new product is written in the default language first
        Livewire::actingAs($editor)->test(Editor::class, ['locale' => 'en'])->assertSet('locale', 'vi');
    });
});

it('loads and saves each language\'s own version, creating a missing one on its first save', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en', 'ja');
        $product = plbProduct();

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('name', 'Drill bit')->set('slug', 'drill-bit')->set('summary', 'A sharp bit')
            ->set('description', '<p>For steel</p><script>x()</script>')->set('seoTitle', 'Drill bit | An Vinh')->set('seoDescription', 'Cuts steel')
            ->call('save')->assertHasNoErrors();

        $en = ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'en')->firstOrFail();
        $vi = ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'vi')->firstOrFail();
        expect($en->only(['name', 'slug', 'summary', 'description', 'seo_title', 'seo_description']))
            ->toBe(['name' => 'Drill bit', 'slug' => 'drill-bit', 'summary' => 'A sharp bit', 'description' => '<p>For steel</p>', 'seo_title' => 'Drill bit | An Vinh', 'seo_description' => 'Cuts steel'])
            ->and($vi->only(['name', 'slug', 'summary']))->toBe(['name' => 'Mũi khoan', 'slug' => 'mui-khoan', 'summary' => 'Tóm tắt Mũi khoan'])
            ->and(ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'ja')->exists())->toBeFalse();

        // opened again: each language shows its own words
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])->assertSet('name', 'Drill bit')->assertSet('slug', 'drill-bit')->assertSet('seoTitle', 'Drill bit | An Vinh');
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('name', 'Mũi khoan')->assertSet('slug', 'mui-khoan');

        // a second save changes that version in place, still nothing else
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])->set('name', 'Drill bit 10 mm')->call('save')->assertHasNoErrors();
        expect(ProductTranslation::query()->where('product_id', $product->id)->pluck('name', 'locale')->sortKeys()->all())->toBe(['en' => 'Drill bit 10 mm', 'vi' => 'Mũi khoan']);
    });
});

it('never writes the product\'s own fields from another language, whatever the request holds', function (): void {
    // one who may add pictures from the library: the refusal below is the language's, not a missing right
    $editor = plbMember(['catalog.view', 'catalog.manage', 'media.view'], 'cat-media');

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en');
        $product = plbProduct();
        $other = plbCategory('khac', 'Khác');
        $before = plbShared($product);
        DB::table('products')->where('id', $product->id)->update(['updated_at' => CarbonImmutable::now()->subHour()]);
        $before['row']['updated_at'] = DB::table('products')->where('id', $product->id)->value('updated_at');

        $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('name', 'Drill bit')->set('slug', 'drill-bit')
            // each shared field sent anyway is put back, with the words that say where it is changed
            ->set('price', '9.000')->assertSet('price', '1.250.000')->assertDispatched('bs-toast')
            ->set('stock', '0')->assertSet('stock', '12')
            ->set('salePrice', '1.000')->assertSet('salePrice', null)
            ->set('sku', 'HACK')->assertSet('sku', 'AV-MUI-KHOAN')
            ->set('featured', false)->assertSet('featured', true)
            ->set('imageMediaIds', [])->assertSet('imageMediaIds', $before['images'])
            ->set('categoryId', $other->id)->assertSet('categoryId', $before['categories'][0])
            ->call('toggleCategory', $other->ulid)->call('removeImage', 0)->call('moveImage', 0, 0)
            ->call('openImagePicker')->assertSet('showImagePicker', false)
            // the status is the product's too: the status moves are refused here
            ->call('publish')->assertSet('status', 'draft');

        $c->call('save')->assertHasNoErrors();
        expect(plbShared($product->fresh()))->toEqual($before)
            ->and(ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'en')->value('name'))->toBe('Drill bit');

        // the description's own «Chèn ảnh» is this language's and still opens
        $c->call('openImagePicker', 'editor')->assertSet('showImagePicker', true)->assertSet('imagePickerMode', 'editor');
    });
});

it('locks the product\'s own cards in another language with «Chung», and saves with one button that keeps the status', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en');
        $product = plbProduct('published');
        $why = e(__('entries.ed.shared_guard', ['lang' => __('entries.language_vi')]));

        $en = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en']);
        $html = $en->html();
        // pictures, price, categories, SKU and labels
        expect(substr_count($html, 'data-shared-chip'))->toBe(4)
            ->and($html)->toContain('title="'.$why.'"')
            ->and($html)->toMatch('/id="catalog-price"[^>]*readonly/')->toMatch('/id="catalog-sku"[^>]*readonly/')
            ->and($html)->toMatch('/id="catalog-cat-0"[^>]*disabled/')
            // the words stay open to write
            ->and($html)->not->toMatch('/id="catalog-name"[^>]*readonly/')
            // no status move: one button saves this version, the product staying published
            ->and($html)->toContain('wire:click="save"')->not->toContain('wire:click="publish"')->not->toContain('wire:click="unpublish"')
            ->and($html)->toContain(e(route('admin.catalog.preview', ['ulid' => $product->ulid, 'locale' => 'en'])));

        $vi = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid])->html();
        expect($vi)->not->toContain('data-shared-chip')->toContain('wire:click="publish"')->toContain('wire:click="unpublish"');
    });
});

it('keeps each language\'s link unique in that language only, with the friendly message', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en');
        $taken = plbProduct('draft', 'mui-khoan');
        $taken->translations()->create(['locale' => 'en'] + plbFull('Drill bit', 'drill-bit'));
        $product = plbProduct('draft', 'mui-taro');

        // another product holds this link in English
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('name', 'Tap')->set('slug', 'DRILL-bit')->call('save')
            ->assertHasErrors(['slug']);
        expect(ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'en')->exists())->toBeFalse();
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('name', 'Tap')->set('slug', 'drill-bit')->call('save')->assertHasErrors(['slug' => __('catalog.slug_taken')]);

        // the same link in another language is free: a Vietnamese one may read like an English one
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('name', 'Tap')->set('slug', 'mui-khoan')->call('save')->assertHasNoErrors();
        expect(ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'en')->value('slug'))->toBe('mui-khoan');
    });
});

it('gives a published product\'s other language its own address, moved with a 301 when its link changes', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en');
        $product = plbProduct('published');
        app(ChangeProductStatus::class)->publishEffects($product);
        $path = fn (string $locale): ?string => SiteRoute::query()->where('product_id', $product->id)->where('locale', $locale)->value('path');
        expect($path('vi'))->toBe('/mui-khoan')->and($path('en'))->toBeNull();

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('name', 'Drill bit')->set('slug', 'drill-bit')->call('save')->assertHasNoErrors();
        expect($path('en'))->toBe('/drill-bit')->and($path('vi'))->toBe('/mui-khoan');

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->set('slug', 'drill-bit-10')->call('save')->assertHasNoErrors();
        expect($path('en'))->toBe('/drill-bit-10')->and($path('vi'))->toBe('/mui-khoan')
            ->and(Redirect::query()->where('locale', 'en')->where('from_path', '/drill-bit')->value('to_path'))->toBe('/drill-bit-10');

        // the storefront serves the English version at its own address, through the language prefix
        $html = $this->get('http://a.test/en/drill-bit-10')->assertOk()->getContent();
        expect($html)->toContain('Drill bit')->not->toContain('Mũi khoan');
    });
});

it('writes every language\'s address when the product goes live from the default language', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en');
        $product = plbProduct();
        $product->translations()->create(['locale' => 'en'] + plbFull('Drill bit', 'drill-bit'));

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid])->call('publish')->assertHasNoErrors();
        expect(SiteRoute::query()->where('product_id', $product->id)->orderBy('locale')->pluck('path', 'locale')->all())
            ->toBe(['en' => '/drill-bit', 'vi' => '/mui-khoan']);
    });
});

it('gives each language the state of its version, and moves them after a save', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en', 'ja', 'zh', 'ko');
        $product = plbProduct();
        $product->translations()->create(['locale' => 'en'] + plbFull('Drill bit', 'drill-bit'));
        // name and link, an empty paragraph: the description does not count
        $product->translations()->create(['locale' => 'ja', 'name' => 'ドリル', 'slug' => 'doriru', 'description' => "<p>&nbsp;</p><p>\u{00A0}</p>"]);
        $product->translations()->create(['locale' => 'ko'] + plbFull('드릴', 'deuril'));
        // Korean was last written before the source changed
        ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'ko')->toBase()->update(['content_changed_at' => CarbonImmutable::now()->subDay()]);

        $c = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'ja']);
        $items = plbItems($c);
        expect(array_map(fn (array $i): string => $i['kind'], $items))->toBe(['vi' => 'source', 'en' => 'saved', 'ja' => 'partial', 'zh' => 'empty', 'ko' => 'stale'])
            ->and(array_map(fn (array $i): int => $i['done'], $items))->toBe(['vi' => 6, 'en' => 6, 'ja' => 2, 'zh' => 0, 'ko' => 6])
            ->and($items['ja']['current'])->toBeTrue()->and($items['vi']['source'])->toBeTrue()->and($items['ko']['public'])->toBeFalse()
            ->and($items['vi']['href'])->toBe(route('admin.catalog.edit', ['ulid' => $product->ulid]))
            ->and($items['zh']['href'])->toBe(route('admin.catalog.edit', ['ulid' => $product->ulid, 'locale' => 'zh']));

        // a picture or a video in the description fills it
        $c->set('description', '<p><img src="https://files.example/a.png" alt=""></p>')->call('save')->assertHasNoErrors();
        expect(plbItems($c)['ja'])->toMatchArray(['kind' => 'partial', 'done' => 3]);

        // the others written after the source: up to date; the source changes again: they are out of date
        ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'vi')->toBase()->update(['content_changed_at' => CarbonImmutable::now()->subMinutes(2)]);
        ProductTranslation::query()->where('product_id', $product->id)->whereIn('locale', ['en', 'ja'])->toBase()->update(['content_changed_at' => CarbonImmutable::now()->subMinute()]);
        $vi = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid]);
        expect(plbItems($vi)['en']['kind'])->toBe('saved');
        $vi->set('name', 'Mũi khoan thép')->call('save')->assertHasNoErrors();
        expect(plbItems($vi)['en']['kind'])->toBe('stale')->and(plbItems($vi)['ja']['kind'])->toBe('stale');
    });
});

it('shows the bar only for a saved product on a site that writes in more than one language', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $product = plbProduct();
        $one = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid])->assertSet('langBar', []);
        expect($one->html())->not->toContain('data-lang-bar');

        plbLanguages('en');
        $new = Livewire::actingAs($editor)->test(Editor::class)->assertSet('langBar', []);
        expect($new->html())->not->toContain('data-lang-bar');
        // once saved, the bar shows
        $new->set('name', 'Bàn ren')->call('save')->assertHasNoErrors();
        expect(plbItems($new)['vi']['kind'])->toBe('source')->and(plbItems($new)['en']['kind'])->toBe('empty')->and($new->html())->toContain('data-lang-bar');
    });
});

it('opens another version through the unsaved-changes question and focuses its name', function (): void {
    $editor = plbMember();
    $admin = plbMember(['catalog.view', 'catalog.manage', 'settings.manage'], 'cat-admin');

    app(Tenancy::class)->enter(1, function () use ($editor, $admin): void {
        plbLanguages('en');
        $product = plbProduct();
        $html = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid])->set('name', 'Đang gõ dở')->html();
        $en = e(route('admin.catalog.edit', ['ulid' => $product->ulid, 'locale' => 'en']));
        $body = e(__('admin.lb.sw_body', ['from' => __('entries.language_vi'), 'to' => __('entries.language_en')]));

        expect($html)->toContain('data-unsaved-guard', 'data-lang-bar')
            ->and(preg_match('/<a href="'.preg_quote($en, '/').'" class="lb-pill"[^>]*wire:navigate[^>]*data-ud-body="'.preg_quote($body, '/').'"/', $html))->toBe(1)
            ->and($html)->toMatch('/data-unsaved-count="[1-9]/')
            ->and($html)->toContain('id="catalog-name"', '\\u0022focus\\u0022:\\u0022catalog-name\\u0022')
            ->and($html)->not->toContain('href="'.e(route('admin.settings.languages')).'"');

        expect(Livewire::actingAs($admin)->test(Editor::class, ['ulid' => $product->ulid])->html())->toContain('href="'.e(route('admin.settings.languages')).'"');
    });
});

it('keeps a member who may only see products to reading, in every language', function (): void {
    $viewer = plbMember(['catalog.view'], 'cat-viewer');

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        plbLanguages('en');
        $product = plbProduct();
        $product->translations()->create(['locale' => 'en'] + plbFull('Drill bit', 'drill-bit'));

        $c = Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => 'en'])
            ->assertSet('canEdit', false)->assertSet('name', 'Drill bit');
        expect(plbItems($c))->toHaveKeys(['vi', 'en'])->and($c->html())->toMatch('/id="catalog-name"[^>]*readonly/');
        $c->set('name', 'Hacked')->call('save')->assertForbidden();
        expect(ProductTranslation::query()->where('product_id', $product->id)->where('locale', 'en')->value('name'))->toBe('Drill bit');
    });
});

it('opens the editor on one query budget, reading every language\'s state in one query', function (): void {
    $editor = plbMember();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        plbLanguages('en', 'ja');
        $few = plbProduct('draft', 'few');
        $few->translations()->create(['locale' => 'en'] + plbFull('Few', 'few-en'));
        $open = function (Product $product, string $locale) use ($editor): array {
            $reads = 0;
            $all = countQueries(function () use ($editor, $product, $locale, &$reads): void {
                DB::listen(function ($query) use (&$reads): void {
                    $reads += str_contains($query->sql, 'coalesce(content_changed_at, updated_at)') ? 1 : 0;
                });
                Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $product->ulid, 'locale' => $locale]);
            });

            return [$all, $reads];
        };
        // the first editor opened after a change of languages fills the request's caches (site, languages, permissions)
        $open($few, 'en');
        [$fewQueries, $fewReads] = $open($few, 'en');

        plbLanguages('zh', 'ko', 'fr');
        $many = plbProduct('draft', 'many');
        foreach (['en', 'ja', 'zh', 'ko'] as $code) {
            $many->translations()->create(['locale' => $code] + plbFull('Many '.$code, 'many-'.$code));
        }
        $open($many, 'en');
        [$manyQueries, $manyReads] = $open($many, 'en');

        // the budget measured on this screen (the site, its languages and modules, the member's rights,
        // the product with its categories, translation and pictures, the category tree, the bar)
        expect($fewReads)->toBe(1)->and($manyReads)->toBe(1)->and($manyQueries)->toBe($fewQueries)->and($manyQueries)->toBeLessThanOrEqual(40);
    });
});

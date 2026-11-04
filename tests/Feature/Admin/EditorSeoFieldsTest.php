<?php

use App\Modules\Admin\Livewire\Catalog\Editor as ProductEditor;
use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Admin\Support\SeoFields;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The short excerpt, the Google title and the Google description of a post or page, per language,
 * in the shared "Hiện thế nào trên Google" part (also the product editor's). Saved through the
 * editor's own path: '' is stored as null, line breaks and control characters never reach the
 * Google fields, and the values travel with revisions, restores and pending changes.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function seoFieldsEditor(array $permissions = ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $permissions): void {
        grantRole($user, 'seo-editor', $permissions);
    });

    return $user->fresh();
}

/** A post category with its vi and en link; returns it. */
function seoFieldsPostCategory(string $vi, string $en): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $vi, 'slug' => $vi]);
    $category->translations()->create(['locale' => 'en', 'name' => $en, 'slug' => $en]);

    return $category->load('translations');
}

/** The address line of the Google preview as the server renders it (before the browser adds the slug). */
function seoFieldsAddress(string $html): string
{
    preg_match('/x-text="previewAddress">([^<]*)</', $html, $match);

    return html_entity_decode($match[1] ?? '', ENT_QUOTES);
}

/** A draft page "gioi-thieu" with its vi text and Google fields; returns it. */
function seoFieldsPage(?User $author = null, string $status = 'draft'): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author?->id, 'meta' => []]);
    $page->translations()->create([
        'locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'draft',
        'body' => ['blocks' => []], 'excerpt' => 'Tóm tắt tiếng Việt',
        'seo_title' => 'Giới thiệu | An Vinh', 'seo_description' => 'Mô tả tiếng Việt cho Google.',
    ]);

    return $page->fresh();
}

it('loads, saves and reloads the three fields per language, leaving the other language untouched', function (): void {
    $user = seoFieldsEditor();
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->assertSet('excerpt', 'Tóm tắt tiếng Việt')
            ->assertSet('seoTitle', 'Giới thiệu | An Vinh')
            ->assertSet('seoDescription', 'Mô tả tiếng Việt cho Google.');

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
            ->assertSet('seoTitle', '')
            ->set('title', 'About us')->set('slug', 'about-us')
            ->set('excerpt', 'English excerpt')
            ->set('seoTitle', 'About us | An Vinh')
            ->set('seoDescription', 'English description for Google.')
            ->call('save')
            ->assertHasNoErrors();

        $en = $page->fresh()->translationFor('en');
        $vi = $page->fresh()->translationFor('vi');
        expect($en->excerpt)->toBe('English excerpt')
            ->and($en->seo_title)->toBe('About us | An Vinh')
            ->and($en->seo_description)->toBe('English description for Google.')
            ->and($vi->excerpt)->toBe('Tóm tắt tiếng Việt')
            ->and($vi->seo_title)->toBe('Giới thiệu | An Vinh')
            ->and($vi->seo_description)->toBe('Mô tả tiếng Việt cho Google.');

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
            ->assertSet('excerpt', 'English excerpt')
            ->assertSet('seoTitle', 'About us | An Vinh')
            ->assertSet('seoDescription', 'English description for Google.');
    });
});

it('stores an emptied field as null', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('excerpt', '')
            ->set('seoTitle', '   ')
            ->set('seoDescription', '')
            ->call('save')
            ->assertHasNoErrors();

        $vi = $page->fresh()->translationFor('vi');
        expect($vi->excerpt)->toBeNull()
            ->and($vi->seo_title)->toBeNull()
            ->and($vi->seo_description)->toBeNull();
    });
});

it('refuses a field longer than its column with a plain message, saving nothing', function (string $field, string $key): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user, $field, $key): void {
        $page = seoFieldsPage($user);

        $component = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set($field, str_repeat('ă', 256))
            ->call('save')
            ->assertHasErrors([$field => 'max']);

        expect($component->errors()->first($field))->toBe(__($key, ['max' => 255]))
            ->and($page->fresh()->translationFor('vi')->seo_title)->toBe('Giới thiệu | An Vinh');
    });
})->with([
    'excerpt' => ['excerpt', 'entries.excerpt_too_long'],
    'Google title' => ['seoTitle', 'entries.seo_title_too_long'],
    'Google description' => ['seoDescription', 'entries.seo_description_too_long'],
]);

it('takes 255 characters counted as letters, not bytes', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('seoDescription', str_repeat('ệ', 255))
            ->call('save')
            ->assertHasNoErrors();

        expect(mb_strlen((string) $page->fresh()->translationFor('vi')->seo_description))->toBe(255);
    });
});

it('strips line breaks and control characters from the Google title and description', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('seoTitle', "Dòng một\r\nDòng hai\t\x07")
            ->set('seoDescription', "Câu một.\n\nCâu hai.\u{0085}")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('seoTitle', 'Dòng một Dòng hai')
            ->assertSet('seoDescription', 'Câu một. Câu hai.');

        $vi = $page->fresh()->translationFor('vi');
        expect($vi->seo_title)->toBe('Dòng một Dòng hai')
            ->and($vi->seo_description)->toBe('Câu một. Câu hai.');
    });
});

it('removes invisible format characters from the Google title and description', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('seoTitle', "Giới\u{200B}thiệu \u{202E}An Vinh\u{202C}")
            ->set('seoDescription', "\u{FEFF}Mô tả\u{200B} ngắn.")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('seoTitle', 'Giớithiệu An Vinh')
            ->assertSet('seoDescription', 'Mô tả ngắn.');

        expect($page->fresh()->translationFor('vi')->seo_title)->toBe('Giớithiệu An Vinh');
    });
});

it('saves text with a NUL or another control character instead of failing', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('title', "Giới\0 thiệu\x07")
            ->set('slug', "gioi-thieu\0")
            ->set('excerpt', "Dòng một\0\r\nDòng\thai\x1B")
            ->call('addBlock', 'richtext')
            ->set('blocks.0.props.html', "<p>Đoạn\0 một</p>")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('title', 'Giới thiệu')
            ->assertSet('excerpt', "Dòng một\nDòng\thai");

        $vi = $page->fresh()->translationFor('vi');
        expect($vi->title)->toBe('Giới thiệu')
            ->and($vi->slug)->toBe('gioi-thieu')
            ->and($vi->excerpt)->toBe("Dòng một\nDòng\thai")
            ->and(json_encode($vi->body))->not->toContain('\u0000');
    });
});

it('saves a product name, link and code with a NUL instead of failing', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'seo-cat-manager', ['catalog.view', 'catalog.manage']);

        Livewire::actingAs($user->fresh())->test(ProductEditor::class)
            ->set('name', "Đồ gá\0 hàn")->set('slug', "do-ga-han\0")->set('sku', "DG\0-01")
            ->set('summary', "<p>Tóm\0 tắt</p>")
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->sole();
        expect($product->sku)->toBe('DG-01')
            ->and($product->translationFor('vi')->name)->toBe('Đồ gá hàn')
            ->and($product->translationFor('vi')->slug)->toBe('do-ga-han');
    });
});

it('shows the public address on the primary domain: category and slug for a post, with the language prefix', function (): void {
    $user = seoFieldsEditor();
    $admin = DB::connection('pgsql_admin');
    $admin->table('domains')->where('hostname', 'a.test')->update(['is_primary' => false]);
    $admin->table('domains')->insert(['site_id' => 1, 'hostname' => 'congty.example', 'kind' => 'custom', 'is_primary' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $admin->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    $post = app(Tenancy::class)->enter(1, function () use ($user): Entry {
        $news = seoFieldsPostCategory('tin-tuc', 'news');
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $news->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $user->id, 'meta' => []]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài mới', 'slug' => 'bai-moi', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $post->assignCategories([$news->id]);

        return $post;
    });

    // Opened on the platform subdomain, the address is the primary domain's.
    $vi = $this->actingAs($user)->get('http://a.test/admin/content/'.$post->ulid.'/edit')->assertOk();
    $en = $this->actingAs($user)->get('http://a.test/admin/content/'.$post->ulid.'/edit?locale=en')->assertOk();

    expect(seoFieldsAddress($vi->getContent()))->toBe('congty.example/tin-tuc/')
        ->and(seoFieldsAddress($en->getContent()))->toBe('congty.example/en/news/');
});

it('follows the category picked on the screen and the home page, from the site address', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $news = seoFieldsPostCategory('tin-tuc', 'news');
        $events = seoFieldsPostCategory('su-kien', 'events');
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $news->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
        ContentSection::query()->create(['key' => 'events', 'label' => 'Sự kiện', 'entry_type' => 'post', 'taxonomy_id' => $events->id, 'permission_prefix' => 'entries', 'sort_order' => 2]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'events', 'value' => ['pattern' => 'base', 'base' => 'lich']]);

        Livewire::actingAs($user)->test(EntryEditor::class, ['section' => 'posts'])
            ->assertSet('address.base', 'localhost/tin-tuc/')
            ->set('categoryId', $events->id)
            ->assertSet('address.base', 'localhost/lich/')
            ->set('categoryId', null)
            ->assertSet('address.base', 'localhost/');

        Livewire::actingAs($user)->test(EntryEditor::class)
            ->assertSet('address', ['base' => 'localhost/', 'home' => 'localhost/', 'homeSlug' => 'home']);
    });
});

it('shows a product address by the catalog preset, on the primary domain', function (): void {
    $user = User::factory()->create();
    $admin = DB::connection('pgsql_admin');
    $admin->table('domains')->where('hostname', 'a.test')->update(['is_primary' => false]);
    $admin->table('domains')->insert(['site_id' => 1, 'hostname' => 'congty.example', 'kind' => 'custom', 'is_primary' => true, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'seo-cat-manager', ['catalog.view', 'catalog.manage']);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'products', 'value' => ['pattern' => 'base', 'base' => 'san-pham']]);
    });

    $html = $this->actingAs($user->fresh())->get('http://a.test/admin/catalog/create')->assertOk()->getContent();
    expect(seoFieldsAddress($html))->toBe('congty.example/san-pham/');
});

it('follows the product category picked on the screen when the catalog address has the category', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'seo-cat-manager', ['catalog.view', 'catalog.manage']);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'products', 'value' => ['pattern' => 'category']]);
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);

        Livewire::actingAs($user->fresh())->test(ProductEditor::class)
            ->assertSet('address.base', 'localhost/')
            ->set('categoryId', $category->id)
            ->assertSet('address.base', 'localhost/do-ga/');
    });
});

it('ties each error message to its field', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);
        $html = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('excerpt', str_repeat('a', 256))->set('seoTitle', str_repeat('a', 256))->set('seoDescription', str_repeat('a', 256))
            ->call('save')
            ->html();

        foreach (['excerpt', 'title', 'description'] as $field) {
            expect($html)->toMatch('/id="entry-seo-'.$field.'"[^>]*aria-describedby="[^"]*entry-seo-'.$field.'-e[^"]*"/s')
                ->toContain('<p id="entry-seo-'.$field.'-e"');
        }
    });

    $this->withViewErrors(['email' => 'Email chưa đúng.']);
    $field = (string) $this->blade('<x-bs-admin::input name="email" label="Email" aria-describedby="email-hint" />');
    $bound = (string) $this->blade('<x-bs-admin::input name="contact-email" wire:model="email" />');

    expect($field)->toContain('aria-describedby="email-hint email-error"')
        ->toContain('<p id="email-error" class="mt-1 text-sm text-danger-text">Email chưa đúng.</p>')
        ->and($bound)->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="contact-email-error"')
        ->toContain('<p id="contact-email-error"')
        ->and((string) $this->blade('<x-bs-admin::input name="phone" />'))->not->toContain('aria-describedby')->not->toContain('<p ');
});

it('lets Enter confirm a word being composed in the Google description', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $html = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => seoFieldsPage($user)->ulid])->html();

        expect($html)->toContain('x-on:keydown.enter="if (! $event.isComposing && $event.keyCode !== 229) $event.preventDefault()"')
            ->not->toContain('keydown.enter.prevent');
    });
});

it('shows the price before the Google part and the product code after it, as design 09 orders them', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'seo-cat-manager', ['catalog.view', 'catalog.manage']);
        $html = Livewire::actingAs($user->fresh())->test(ProductEditor::class)->html();

        $price = strpos($html, 'id="catalog-price"');
        $seo = strpos($html, 'data-seo-fields');
        $sku = strpos($html, 'id="catalog-sku"');
        expect($price)->toBeInt()->and($seo)->toBeInt()->and($sku)->toBeInt()
            ->and($price)->toBeLessThan($seo)
            ->and($seo)->toBeLessThan($sku);
    });
});

it('brings the fields back with a revision restore', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);
        app(PublishEntry::class)->handle($page, 'vi', $user);
        $first = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')->sole();

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->set('excerpt', 'Tóm tắt khác')
            ->set('seoTitle', 'Tiêu đề khác')
            ->set('seoDescription', 'Mô tả khác.')
            ->call('save')
            ->assertHasNoErrors();

        $edit = EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('id')->first();
        expect($edit->id)->not->toBe($first->id)
            ->and($edit->snapshot)->toMatchArray(['excerpt' => 'Tóm tắt khác', 'seo_title' => 'Tiêu đề khác', 'seo_description' => 'Mô tả khác.']);

        app(RestoreEntryRevision::class)->handle($first, $user);

        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])
            ->assertSet('excerpt', 'Tóm tắt tiếng Việt')
            ->assertSet('seoTitle', 'Giới thiệu | An Vinh')
            ->assertSet('seoDescription', 'Mô tả tiếng Việt cho Google.');
    });
});

it('carries the fields through a pending change of live content', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);
        app(PublishEntry::class)->handle($page, 'vi', $user);

        $change = app(SaveEntryDraft::class)->handle($page->fresh(), $user, 'vi', [
            'seo_title' => 'Tiêu đề chờ duyệt', 'seo_description' => 'Mô tả chờ duyệt.',
        ]);
        expect($page->fresh()->translationFor('vi')->seo_title)->toBe('Giới thiệu | An Vinh');

        app(ApplyEntryDraft::class)->handle($page->fresh(), $user, $change->version());

        $vi = $page->fresh()->translationFor('vi');
        expect($vi->seo_title)->toBe('Tiêu đề chờ duyệt')
            ->and($vi->seo_description)->toBe('Mô tả chờ duyệt.')
            ->and($vi->excerpt)->toBe('Tóm tắt tiếng Việt');
    });
});

it('refuses the save of a member who may no longer edit the entry, keeping the stored fields', function (): void {
    $user = seoFieldsEditor(['entries.view', 'entries.edit.own']);
    $other = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user, $other): void {
        $page = seoFieldsPage($user);

        $component = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid]);
        // Handed to someone else while the form was open.
        Entry::query()->whereKey($page->id)->update(['author_id' => $other->id]);

        $component->set('seoTitle', 'Chen ngang')->call('save')->assertForbidden();

        expect($page->fresh()->translationFor('vi')->seo_title)->toBe('Giới thiệu | An Vinh');
    });
});

it('renders the shared part with the limits from the one limits class', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);

        $html = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid])->html();

        expect($html)->toContain('data-seo-fields')
            ->toContain('wire:model="excerpt"')
            ->toContain('wire:model="seoTitle"')
            ->toContain('wire:model="seoDescription"')
            ->toContain('maxlength="'.SeoFields::hardCap('seoTitle').'"')
            ->toContain('maxlength="'.SeoFields::hardCap('seoDesc').'"')
            ->toContain('maxlength="'.SeoFields::hardCap('excerpt').'"')
            ->toContain(e(__('admin.seo.card_title')))
            ->toContain(e(__('admin.seo.cut_mark')));
    });
});

it('mirrors the design limit table', function (): void {
    expect(SeoFields::LIMITS)->toMatchArray([
        'excerpt' => ['min' => 80, 'best' => [120, 200], 'display' => 200, 'max' => 240, 'hard' => 255],
        'seoTitle' => ['min' => 20, 'best' => [35, 60], 'display' => 60, 'max' => 80],
        'seoDesc' => ['min' => 70, 'best' => [150, 160], 'display' => 160, 'max' => 200],
    ])
        ->and(SeoFields::hardCap('excerpt'))->toBe(255)
        ->and(SeoFields::hardCap('seoTitle'))->toBe(100)
        ->and(SeoFields::hardCap('seoDesc'))->toBe(250);
});

it('opens the editor and saves on the same number of queries with the fields set', function (): void {
    $user = seoFieldsEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = seoFieldsPage($user);
        Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid]);   // warm up

        // The permalink preset is remembered for one request; forget it so this open pays for it as a real one does.
        app(PermalinkConfig::class)->flush();
        $open = countQueries(fn () => Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid]));
        $component = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $page->ulid]);
        $save = countQueries(fn () => $component->set('seoTitle', 'Tiêu đề mới')->call('save')->assertHasNoErrors());

        expect($open)->toBeLessThanOrEqual(SEO_FIELDS_OPEN_BUDGET)
            ->and($save)->toBeLessThanOrEqual(SEO_FIELDS_SAVE_BUDGET);
    });
});

it('saves the product Google fields through the shared part into the same columns', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'seo-cat-manager', ['catalog.view', 'catalog.manage']);

        $component = Livewire::actingAs($user->fresh())->test(ProductEditor::class)
            ->set('name', 'Đồ gá hàn')->set('slug', 'do-ga-han')
            ->set('seoTitle', "Đồ gá hàn\n| An Vinh")
            ->set('seoDescription', 'Đồ gá hàn chính xác.')
            ->call('save')
            ->assertHasNoErrors();

        $translation = Product::query()->sole()->translationFor('vi');
        expect($translation->seo_title)->toBe('Đồ gá hàn | An Vinh')
            ->and($translation->seo_description)->toBe('Đồ gá hàn chính xác.')
            ->and($component->html())->toContain('data-seo-fields')
            ->toContain('wire:model="seoTitle"')
            ->not->toContain('wire:model="excerpt"');

        Livewire::actingAs($user->fresh())->test(ProductEditor::class, ['ulid' => Product::query()->sole()->ulid])
            ->set('seoTitle', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['seoTitle' => 'max']);
    });
});

/**
 * Measured on main before the fields were added (the same page, the same calls: open 2, save 5).
 * Opening is 3 since the Google preview shows the real public address: the page's permalink preset
 * (one site_settings read) decides the path before the slug, and nothing else on the screen reads it.
 */
const SEO_FIELDS_OPEN_BUDGET = 3;
const SEO_FIELDS_SAVE_BUDGET = 5;

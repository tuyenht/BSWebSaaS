<?php

use App\Modules\Admin\Livewire\Blocks\Editor as BlockEditor;
use App\Modules\Admin\Livewire\Blocks\Library;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * «Thư viện khối tái dùng» and the block editor it opens. Who reaches them (ContentBlockPolicy:
 * `blocks.manage`, not limited to some categories); the list by status, region and search (marks
 * ignored) in keyset pages; create → the block editor; rename with the version read; duplicate;
 * delete after a question that counts the places; another website's block reads as gone; the list
 * keeps a flat query count however many blocks it shows; the editor saves one language's layout
 * with the version it read and goes back to the library.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'blocks', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'blocks', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** A block of $site, with a layout in each of $locales. */
function libraryBlock(int $site, string $key, string $name, string $region = 'inline', string $status = 'draft', array $locales = []): ContentBlock
{
    return app(Tenancy::class)->enter($site, function () use ($key, $name, $region, $status, $locales): ContentBlock {
        $block = ContentBlock::query()->create(['key' => $key, 'name' => $name, 'region' => $region, 'status' => $status]);
        foreach ($locales as $locale) {
            ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => $locale, 'body' => ['blocks' => []]]);
        }

        return $block;
    });
}

it('opens the library for whoever may change blocks and refuses everyone else', function (string $template, int $status): void {
    $user = blockLibraryUser(1, $template);

    $this->actingAs($user)->get('http://a.test/admin/blocks')->assertStatus($status);
})->with([
    'owner' => ['owner', 200],
    'website admin' => ['admin', 200],
    'editor' => ['editor', 200],
    'author' => ['author', 403],
    'viewer' => ['viewer', 403],
]);

it('refuses the library and the block editor to a member limited to some categories', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $category = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
        MemberCategoryScope::query()->create(['user_id' => $editor->id, 'taxonomy_id' => $category->id]);
    });
    $block = libraryBlock(1, 'footer', 'Chân trang');

    $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertForbidden();
    $this->actingAs($editor)->get('http://a.test/admin/blocks/'.$block->ulid.'/edit')->assertForbidden();
    // nor is the library in the side menu for them; it is for an editor of the whole website
    $keys = fn (User $user): array => app(Tenancy::class)->enter(1, fn (): array => array_map(fn (NavItem $item): string => $item->key, app(AdminNav::class)->items($user)));
    expect($keys($editor))->not->toContain('blocks')
        ->and($keys(blockLibraryUser(1, 'editor')))->toContain('blocks');
});

it('refuses the library on a website whose plan has no block library', function (): void {
    $owner = blockLibraryUser(1, 'owner');
    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->where('module_key', 'blocks')->delete();

    $this->actingAs($owner)->get('http://a.test/admin/blocks')->assertForbidden();
});

it('lists the blocks newest change first, with region, code, status and place counts', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    libraryBlock(1, 'footer', 'Chân trang chung', 'footer', 'published');
    libraryBlock(1, 'cta-sale', 'CTA khuyến mãi', 'inline');
    libraryBlock(2, 'other-site', 'Khối của site B');

    $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertOk()
        ->assertSeeText('Thư viện khối tái dùng')
        ->assertSeeText('Chân trang chung')->assertSeeText('footer')
        ->assertSeeText('CTA khuyến mãi')->assertSeeText('cta-sale')
        ->assertSeeText('Chèn trong trang')->assertSeeText('0 nơi')
        ->assertDontSeeText('Khối của site B');
});

it('filters by status and region and finds a block by its name without marks or by its code', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    libraryBlock(1, 'footer', 'Chân trang chung', 'footer', 'published');
    libraryBlock(1, 'cta-sale', 'CTA khuyến mãi', 'inline');
    libraryBlock(1, 'mega', 'Bảng lớn sản phẩm', 'menu');

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $page = Livewire::actingAs($editor)->test(Library::class);
        $page->assertSee('Chân trang chung')->assertSee('CTA khuyến mãi')->assertSee('Bảng lớn sản phẩm');

        $page->set('tab', 'published')->assertSee('Chân trang chung')->assertDontSee('CTA khuyến mãi');
        $page->set('tab', 'all')->set('region', 'menu')->assertSee('Bảng lớn sản phẩm')->assertDontSee('Chân trang chung')
            ->assertSee(__('blocks.region_note_menu'));
        $page->call('clearFilters')->set('search', 'khuyen mai')->assertSee('CTA khuyến mãi')->assertDontSee('Chân trang chung');
        $page->set('search', 'MEGA')->assertSee('Bảng lớn sản phẩm')->assertDontSee('CTA khuyến mãi');
        $page->set('search', 'không có khối này')->assertSee(__('blocks.nomatch_title'));
    });
});

it('pages the list by keyset and shows more on «Xem thêm»', function (): void {
    config(['cms.blocks.max_per_site' => 200]);
    $editor = blockLibraryUser(1, 'editor');
    app(Tenancy::class)->enter(1, function (): void {
        foreach (range(1, 55) as $i) {
            ContentBlock::query()->create(['key' => sprintf('blk-%03d', $i), 'name' => sprintf('Khối %03d', $i), 'updated_at' => now()->subMinutes(100 - $i)]);
        }
    });

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $page = Livewire::actingAs($editor)->test(Library::class);
        // newest change first: 055 … 006 on the first page
        $page->assertSee('Khối 055')->assertSee('Khối 006')->assertDontSee('Khối 005')->assertSee(__('blocks.load_more', ['n' => 50, 'm' => 55]));
        $page->call('loadMore')->assertSet('pages', 2)->assertSee('Khối 001')->assertDontSee(__('blocks.load_more', ['n' => 55, 'm' => 55]));
        $page->set('search', 'Khối 00')->assertSet('pages', 1);
    });
});

it('keeps the list on a flat query count however many blocks it shows', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
    });
    libraryBlock(1, 'one', 'Một', 'footer', 'published', ['vi', 'en']);
    // the first request fills the shared caches (settings, site resolution); measure the next ones
    $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertOk();
    $few = countQueries(fn () => $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertOk());

    foreach (range(2, 21) as $i) {
        libraryBlock(1, 'b'.$i, 'Khối '.$i, $i % 2 === 0 ? 'inline' : 'header', $i % 3 === 0 ? 'published' : 'draft', ['vi', 'en']);
    }
    $many = countQueries(fn () => $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertOk());

    expect($many)->toBe($few);
});

it('creates a block from the dialog and opens it in the block editor', function (): void {
    $editor = blockLibraryUser(1, 'editor');

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $page = Livewire::actingAs($editor)->test(Library::class)->set('region', 'footer')->call('openAdd')
            ->assertSet('formOpen', true)->assertSet('formRegion', 'footer');

        $page->set('name', '   ')->call('saveBlock')->assertHasErrors('name')->assertSet('toastMsg', __('blocks.err_name_required'));
        expect(ContentBlock::query()->count())->toBe(0);

        $page->set('name', 'Chân trang mới')->call('saveBlock');
        $block = ContentBlock::query()->sole();
        expect($block->name)->toBe('Chân trang mới')->and($block->region)->toBe('footer')->and($block->status)->toBe('draft');
        $page->assertRedirect(route('admin.blocks.edit', ['ulid' => $block->ulid]));
    });
});

it('renames a block with the version it read and refuses a stale dialog', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(Library::class)->call('openEdit', $block->ulid)
            ->assertSet('editing', $block->ulid)->assertSet('name', 'Chân trang');
        $page->set('name', 'Chân trang mới')->set('formRegion', 'header')->call('saveBlock')
            ->assertSet('formOpen', false)->assertSet('toastMsg', __('blocks.toast_saved', ['name' => 'Chân trang mới']));
        expect($block->fresh()->name)->toBe('Chân trang mới')->and($block->fresh()->region)->toBe('header');

        $stale = Livewire::actingAs($editor)->test(Library::class)->call('openEdit', $block->ulid);
        ContentBlock::query()->whereKey($block->id)->update(['name' => 'Đổi ở nơi khác']);
        $stale->set('name', 'Ghi đè')->call('saveBlock')->assertSet('toastMsg', __('blocks.err_stale'))
            ->assertSet('formOpen', true)->assertSet('name', 'Ghi đè');
        expect($block->fresh()->name)->toBe('Đổi ở nơi khác');
        // the dialog now holds the block as it is: a second «Lưu» is the member's choice to write over it
        $stale->call('saveBlock')->assertSet('formOpen', false);
        expect($block->fresh()->name)->toBe('Ghi đè');
    });
});

it('duplicates a block and deletes one after a question that counts its places', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang', 'menu', 'draft', ['vi']);

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(Library::class)->call('duplicate', $block->ulid)
            ->assertSet('toastMsg', __('blocks.toast_duplicated', ['name' => 'Chân trang']));
        expect(ContentBlock::query()->count())->toBe(2);

        $page->call('askDelete', $block->ulid)->assertSet('confirmOpen', true)
            ->assertSet('deleting.places', 0)->assertSet('deleting.menu', true)
            ->assertSee(__('blocks.del_title'));
        $page->call('confirmDelete')->assertSet('toastMsg', __('blocks.toast_deleted', ['name' => 'Chân trang']));
        expect(ContentBlock::query()->whereKey($block->id)->exists())->toBeFalse()
            ->and(ContentBlockTranslation::query()->where('content_block_id', $block->id)->exists())->toBeFalse();
    });
});

it('treats another website\'s block as gone for every action', function (): void {
    $editorB = blockLibraryUser(2, 'editor');
    $mine = libraryBlock(1, 'footer', 'Chân trang của A');

    app(Tenancy::class)->enter(2, function () use ($editorB, $mine): void {
        $page = Livewire::actingAs($editorB)->test(Library::class);
        foreach (['openEdit', 'duplicate', 'askDelete', 'showUsage'] as $method) {
            $page->call($method, $mine->ulid)->assertSet('toastMsg', __('blocks.toast_gone'))
                ->assertSet('formOpen', false)->assertSet('confirmOpen', false)->assertSet('usageOpen', false);
        }
    });
    expect(app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->count()))->toBe(1);

    $this->actingAs($editorB)->get('http://b.test/admin/blocks/'.$mine->ulid.'/edit')->assertNotFound();
});

it('shows where a block is used, none yet', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        Livewire::actingAs($editor)->test(Library::class)->call('showUsage', $block->ulid)
            ->assertSet('usageOpen', true)->assertSet('usage.total', 0)
            ->assertSee(__('blocks.usage_title'))->assertSee(__('blocks.usage_none'));
    });
});

it('saves one language of a block in the block editor with the version it read, then goes back', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    $this->actingAs($editor)->get('http://a.test/admin/blocks/'.$block->ulid.'/edit')->assertOk()
        ->assertSeeText('Đang sửa khối tái dùng · Chân trang')->assertSeeText('Lưu & quay lại');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid])->assertSet('blocks', []);
        $page->call('addBlock', 'richtext');
        expect($page->get('blocks'))->toHaveCount(1);

        $page->call('saveAndReturn')->assertHasNoErrors()->assertRedirect(route('admin.blocks.index'));
        $body = ContentBlockTranslation::query()->where('content_block_id', $block->id)->where('locale', 'vi')->value('body');
        expect($body['blocks'][0]['type'] ?? null)->toBe('richtext');

        // a change made meanwhile elsewhere is never overwritten
        $stale = Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid]);
        ContentBlock::query()->whereKey($block->id)->update(['name' => 'Đổi ở nơi khác']);
        $stale->call('removeBlock', 0)->call('save')->assertSet('toastTone', 'error');
        expect(ContentBlockTranslation::query()->where('content_block_id', $block->id)->value('body')['blocks'])->toHaveCount(1);
        expect(ContentBlockVersion::current($block->fresh()))->not->toBe($stale->get('version'));
    });
});

it('picks a block picture in the block editor through the shared «Chọn ảnh» dialog', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'dau-trang', 'Đầu trang');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $picture = Media::query()->create([
            'collection' => 'library', 'slug' => 'anh', 'hash' => str_repeat('a', 64),
            'storage_key' => 'a/library/anh-aaaaaaaaaaaa.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
        ]);

        Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid])
            ->set('blocks', [['type' => 'hero', 'props' => ['heading' => 'Chào', 'image_url' => null]]])
            ->call('openBlockImagePicker', '0.props.image_url')
            ->assertSet('showImagePicker', true)
            ->assertSet('imagePickerMode', 'single')
            ->assertSeeHtml("togglePickerItem({$picture->id})")
            ->call('togglePickerItem', $picture->id)
            ->call('insertPicked')
            ->assertSet('showImagePicker', false)
            ->assertSet('blocks.0.props.image_url', $picture->id);
    });
});

it('refuses the block editor and its actions to an author', function (): void {
    $author = blockLibraryUser(1, 'author');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    $this->actingAs($author)->get('http://a.test/admin/blocks/'.$block->ulid.'/edit')->assertForbidden();
    app(Tenancy::class)->enter(1, function () use ($author, $block): void {
        Livewire::actingAs($author)->test(BlockEditor::class, ['ulid' => $block->ulid])->assertForbidden();
        Livewire::actingAs($author)->test(Library::class)->assertForbidden();
    });
});

it('asks again when a block was placed somewhere more while the delete question was open', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(Library::class)->call('askDelete', $block->ulid)->assertSet('deleting.places', 0);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $entry->id, 'locale' => 'vi']);

        $page->call('confirmDelete')->assertSet('confirmOpen', true)->assertSet('deleting.places', 1)
            ->assertSet('toastMsg', __('blocks.del_recount'));
        expect(ContentBlock::query()->whereKey($block->id)->exists())->toBeTrue();

        $page->call('confirmDelete');
        expect(ContentBlock::query()->whereKey($block->id)->exists())->toBeFalse();
    });
});

it('asks the gate again on every request, not only when the screen opened', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $library = Livewire::actingAs($editor)->test(Library::class);
        DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->where('module_key', 'blocks')->delete();
        app()->forgetInstance(Entitlement::class);
        $library->call('loadMore')->assertForbidden();
    });

    DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => 1, 'module_key' => 'blocks', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    app()->forgetInstance(Entitlement::class);
    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid]);
        // the member loses the role while the editor is open
        $editor->syncRoles([]);
        $editor->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // today this refusal comes from the library gate; each action also asks update on the block
        $page->call('toggleBlock', 0)->assertForbidden();
    });
});

it('says so when the block was deleted elsewhere while its editor was open, keeping the layout on screen', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid])->call('addBlock', 'richtext');
        ContentBlock::query()->whereKey($block->id)->delete();
        $page->call('save')->assertSet('toastMsg', __('blocks.editor_gone'))->assertSet('toastTone', 'error');
        expect($page->get('blocks'))->toHaveCount(1);
    });
});

it('opens the block editor in the language of the address and saves that language only', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]));
    $block = libraryBlock(1, 'footer', 'Chân trang', 'footer', 'draft', ['vi']);

    $this->actingAs($editor)->get('http://a.test/admin/blocks/'.$block->ulid.'/edit?locale=en')->assertOk();
    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        Livewire::withQueryParams(['locale' => 'en'])->actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid])
            ->assertSet('locale', 'en')->call('addBlock', 'richtext')->call('save')->assertHasNoErrors();
        $bodies = ContentBlockTranslation::query()->where('content_block_id', $block->id)->pluck('body', 'locale');
        expect($bodies['en']['blocks'])->toHaveCount(1)->and($bodies['vi']['blocks'])->toBe([]);
    });
});

it('opens the default language for one the website does not have, and the language cannot be changed by hand', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = libraryBlock(1, 'footer', 'Chân trang', 'footer', 'draft', ['vi']);

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::withQueryParams(['locale' => 'fr'])->actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid])
            ->assertSet('locale', 'vi')->call('addBlock', 'richtext')->call('save')->assertHasNoErrors();
        expect(ContentBlockTranslation::query()->where('content_block_id', $block->id)->where('locale', 'vi')->value('body')['blocks'])->toHaveCount(1);

        expect(fn () => $page->set('locale', 'en'))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('refuses to save a language turned off while its editor was open, instead of writing it into another', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]));
    $block = libraryBlock(1, 'footer', 'Chân trang', 'footer', 'draft', ['vi']);

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::withQueryParams(['locale' => 'en'])->actingAs($editor)->test(BlockEditor::class, ['ulid' => $block->ulid])
            ->call('addBlock', 'richtext');
        SiteLocale::query()->where('locale', 'en')->update(['enabled' => false, 'is_public' => false]);
        app()->forgetInstance(SiteLocaleContext::class);

        $page->call('save')->assertSet('toastMsg', __('blocks.err_lang_off'))->assertSet('locale', 'en');
        $vi = ContentBlockTranslation::query()->where('content_block_id', $block->id)->where('locale', 'vi')->value('body');
        expect($vi['blocks'] ?? [])->toBe([]);
    });
});

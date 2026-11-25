<?php

use App\Modules\Admin\Livewire\Categories\BandEditor;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteModule;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Sửa trong Trình dựng trang» on a band of a category page (design 10): who reaches it (manage
 * categories, update this one, see its section), what it opens on (the category's own blocks,
 * else the website's default), saving one language with the version it read while the other
 * languages keep what they showed, and «Lưu & quay lại» back to the category's drawer.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    $this->editor = beUser(['taxonomies.manage', 'entries.view']);
    [$this->root, $this->jobs] = beIn(1, function (): array {
        $root = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
        ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);

        return [$root, app(CreateTaxonomy::class)->handle('post_cat', $root, new TaxonomyData(names: ['vi' => 'Tuyển dụng']))];
    });
});

function beIn(int $site, Closure $callback): mixed
{
    return app(Tenancy::class)->enter($site, $callback);
}

/** A member of site 1 holding exactly $permissions. Call outside any site context. */
function beUser(array $permissions): User
{
    $user = User::factory()->create();
    beIn(1, fn () => grantRole($user, 'be-'.md5(implode(',', $permissions)), $permissions));
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** A category's stored value of one band, read past RLS. */
function beStored(Taxonomy $category, string $slot): mixed
{
    $meta = DB::connection('pgsql_admin')->table('taxonomies')->where('id', $category->id)->value('meta');
    $meta = is_string($meta) ? json_decode($meta, true) : [];

    return $meta['layout_blocks'][$slot] ?? null;
}

function beUrl(Taxonomy $category, string $slot = 'after_list', string $query = ''): string
{
    return 'http://a.test/admin/taxonomies/'.$category->ulid.'/bands/'.$slot.$query;
}

it('opens the band in the builder for whoever may change the category, and for no one else', function (): void {
    // made before anyone signs in: the actions check the signed-in member
    $foreign = beIn(2, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Của B'])));
    $member = beUser(['taxonomies.manage', 'entries.view']);
    beIn(1, function () use ($member): void {
        $other = app(CreateTaxonomy::class)->handle('post_cat', $this->root, new TaxonomyData(names: ['vi' => 'Khác']));
        MemberCategoryScope::query()->create(['user_id' => $member->id, 'taxonomy_id' => $other->id]);
        app(CategoryScopes::class)->forget();
    });

    $this->actingAs($this->editor)->get(beUrl($this->jobs))->assertOk()
        ->assertSeeText(__('categories.band_editor_band', ['band' => __('categories.ui_band_bot'), 'name' => 'Tuyển dụng']))
        ->assertSeeText(__('blocks.editor_save_back'))
        ->assertSee('href="'.e(route('admin.taxonomies.index', ['section' => 'tin-tuc', 'open' => $this->jobs->ulid])).'"', false);

    // no slot of the drawer, no category of this site, or a page that is not a category
    $this->get(beUrl($this->jobs, 'post_aside'))->assertNotFound();
    $this->get(beUrl($foreign))->assertNotFound();

    $writer = beUser(['entries.view', 'entries.create']);
    $this->actingAs($writer)->get(beUrl($this->jobs))->assertForbidden();

    // a member limited to other categories, or one who cannot see the section
    $this->actingAs($member)->get(beUrl($this->jobs))->assertForbidden();
    $blind = beUser(['taxonomies.manage']);
    $this->actingAs($blind)->get(beUrl($this->jobs))->assertForbidden();
});

it('starts from the website\'s default, saves this language as the category\'s own and keeps what the others showed', function (): void {
    beIn(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocaleContext::class)->forget();
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => [
            'after_list' => [
                'vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Chưa thấy vị trí phù hợp?']]]],
                'en' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'No match yet?']]]],
            ],
        ]]);
    });

    beIn(1, function (): void {
        $page = Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])
            ->assertSet('locale', 'vi')->assertSet('blocks.0.props.heading', 'Chưa thấy vị trí phù hợp?');
        $page->call('addBlock', 'richtext')->call('save')->assertHasNoErrors()->assertSet('toastTone', 'success');
    });

    $stored = beStored($this->jobs, 'after_list');
    expect(array_column($stored['vi']['blocks'], 'type'))->toBe(['cta', 'richtext'])
        ->and($stored['en']['blocks'][0]['props']['heading'])->toBe('No match yet?');

    // the other band of the category is left as it was
    expect(beStored($this->jobs, 'before_list'))->toBeNull();
});

/** Turn English on for site 1 beside Vietnamese. */
function beEnglish(): void
{
    beIn(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocaleContext::class)->forget();
    });
}

it('opens a hidden band empty, and saving it gives the category blocks of its own in that language only', function (): void {
    beEnglish();
    beIn(1, fn () => Taxonomy::query()->whereKey($this->jobs->id)->update(['meta' => ['layout_blocks' => ['before_list' => 'none']]]));

    beIn(1, function (): void {
        Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'before_list'])
            ->assertSet('blocks', [])->call('addBlock', 'cta')->call('saveAndReturn')->assertHasNoErrors()
            ->assertRedirect(route('admin.taxonomies.index', ['section' => 'tin-tuc', 'open' => $this->jobs->ulid]));
        expect(session(BandEditor::FLASH))->toBe(__('categories.band_saved_back', ['band' => __('categories.ui_band_top'), 'name' => 'Tuyển dụng']));
    });

    // English showed nothing while the band was hidden, and still shows nothing
    $stored = beStored($this->jobs, 'before_list');
    expect(array_keys($stored))->toEqualCanonicalizing(['vi', 'en'])
        ->and(array_column($stored['vi']['blocks'], 'type'))->toBe(['cta'])
        ->and($stored['en'])->toBe(['blocks' => []]);
});

it('still saves a band whose own blocks were in a language turned off since, and drops that language', function (): void {
    beEnglish();
    beIn(1, function (): void {
        Taxonomy::query()->whereKey($this->jobs->id)->update(['meta' => ['layout_blocks' => ['after_list' => [
            'vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Gửi CV']]]],
            'en' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Send your CV']]]],
        ]]]]);
        SiteLocale::query()->where('locale', 'en')->update(['enabled' => false, 'is_public' => false]);
        app(SiteLocaleContext::class)->forget();
    });

    beIn(1, function (): void {
        Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])
            ->call('addBlock', 'richtext')->call('save')->assertHasNoErrors()->assertSet('toastTone', 'success');
    });

    $stored = beStored($this->jobs, 'after_list');
    expect(array_keys($stored))->toBe(['vi'])
        ->and(array_column($stored['vi']['blocks'], 'type'))->toBe(['cta', 'richtext']);
});

it('opens a product category\'s band for whoever may see the catalogue, and returns to the catalogue\'s categories', function (): void {
    $shelf = beIn(1, function (): Taxonomy {
        SiteModule::create(['module_key' => 'catalog', 'enabled' => true]);

        return app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => 'Dao cắt']));
    });
    $shopper = beUser(['taxonomies.manage', 'catalog.view']);

    $this->actingAs($shopper)->get(beUrl($shelf, 'before_list'))->assertOk()
        ->assertSee('href="'.e(route('admin.taxonomies.index', ['section' => 'catalog', 'open' => $shelf->ulid])).'"', false);
    // seeing posts is not seeing the catalogue
    $this->actingAs($this->editor)->get(beUrl($shelf, 'before_list'))->assertForbidden();

    beIn(1, function () use ($shopper, $shelf): void {
        Livewire::actingAs($shopper)->test(BandEditor::class, ['ulid' => $shelf->ulid, 'slot' => 'before_list'])
            ->call('addBlock', 'cta')->call('save')->assertHasNoErrors()->assertSet('toastTone', 'success');
    });
    expect(array_column(beStored($shelf, 'before_list')['vi']['blocks'], 'type'))->toBe(['cta']);
});

it('only lets support staff look: saving a band is refused during a support session', function (): void {
    $editor = $this->editor->fresh();
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($editor);
    app(SupportSession::class)->start($editor, $editor, 1, 'Kiểm tra', now()->addMinutes(30));
    // Livewire's test requests skip the middleware: hand each one this session, as StartSession does.
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    beIn(1, function () use ($editor): void {
        Livewire::actingAs($editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])
            ->call('addBlock', 'cta')->call('save')
            ->assertSet('toastTone', 'error')->assertSet('toastMsg', __('categories.reason_support_session'));
    });

    expect(beStored($this->jobs, 'after_list'))->toBeNull();
});

it('refuses every action once the member may no longer change the category or see its section, after the screen opened', function (): void {
    beIn(1, function (): void {
        // one screen per action: a refused request leaves its screen with nothing to answer the next
        $limited = Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list']);
        $saving = Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])->call('addBlock', 'cta');
        $other = app(CreateTaxonomy::class)->handle('post_cat', $this->root, new TaxonomyData(names: ['vi' => 'Khác']));
        MemberCategoryScope::query()->create(['user_id' => $this->editor->id, 'taxonomy_id' => $other->id]);
        app(CategoryScopes::class)->forget();
        $limited->call('addBlock', 'cta')->assertForbidden();
        $saving->call('save')->assertForbidden();
    });
    expect(beStored($this->jobs, 'after_list'))->toBeNull();

    $member = beUser(['taxonomies.manage', 'entries.view']);
    beIn(1, function () use ($member): void {
        $blind = Livewire::actingAs($member)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list']);
        $saving = Livewire::actingAs($member)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])->call('addBlock', 'cta');
        // the section is now gated by an ability the member does not hold
        ContentSection::query()->where('key', 'tin-tuc')->update(['permission_prefix' => 'jobs']);
        $blind->call('addBlock', 'cta')->assertForbidden();
        $saving->call('save')->assertForbidden();
    });
    expect(beStored($this->jobs, 'after_list'))->toBeNull();
});

it('says so in words when the category left every section after the screen opened', function (): void {
    beIn(1, function (): void {
        $page = Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])
            ->call('addBlock', 'cta');
        ContentSection::query()->where('key', 'tin-tuc')->delete();
        $page->call('save')->assertSet('toastTone', 'error')->assertSet('toastMsg', __('categories.band_err_moved'))
            ->assertSet('blocks.0.type', 'cta');
        $page->set('toastMsg', '')->call('removeBlock', 0)->assertSet('toastMsg', __('categories.band_err_moved'));
    });
    expect(beStored($this->jobs, 'after_list'))->toBeNull();

    // opening it now answers as for any category not found
    $this->actingAs($this->editor)->get(beUrl($this->jobs))->assertNotFound();
});

it('opens the builder on a flat query budget, whatever the number of blocks in the band', function (): void {
    $open = function (int $blocks): array {
        beIn(1, fn () => Taxonomy::query()->whereKey($this->jobs->id)->update(['meta' => ['layout_blocks' => ['after_list' => [
            'vi' => ['blocks' => array_map(fn (int $i): array => ['type' => 'cta', 'props' => ['heading' => 'Khối '.$i]], range(1, $blocks))],
        ]]]]));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->editor)->get(beUrl($this->jobs))->assertOk()->assertSee('Khối 1');
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $log;
    };

    $few = $open(1);
    $many = $open(8);
    expect(count($many))->toBe(count($few), implode("\n", $many))->and(count($few))->toBeLessThanOrEqual(1, implode("\n", $few));
});

it('never overwrites a change made meanwhile, and refuses a block the band does not take', function (): void {
    beIn(1, function (): void {
        $stale = Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list']);
        Taxonomy::query()->whereKey($this->jobs->id)->update(['meta' => ['layout_blocks' => ['after_list' => 'none']]]);
        $stale->call('addBlock', 'cta')->call('save')->assertSet('toastTone', 'error')->assertSet('toastMsg', __('categories.band_err_stale'));

        // «Bài viết gần đây» belongs beside a post, never around a category's list
        Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'after_list'])
            ->call('addBlock', 'recent_posts')->assertHasErrors('blocks')->assertSet('blocks', []);
    });

    expect(beStored($this->jobs, 'after_list'))->toBe('none');
});

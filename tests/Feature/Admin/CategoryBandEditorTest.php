<?php

use App\Modules\Admin\Livewire\Categories\BandEditor;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
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

it('opens a hidden band empty, and saving it gives the category blocks of its own in that language only', function (): void {
    beIn(1, fn () => Taxonomy::query()->whereKey($this->jobs->id)->update(['meta' => ['layout_blocks' => ['before_list' => 'none']]]));

    beIn(1, function (): void {
        Livewire::actingAs($this->editor)->test(BandEditor::class, ['ulid' => $this->jobs->ulid, 'slot' => 'before_list'])
            ->assertSet('blocks', [])->call('addBlock', 'cta')->call('saveAndReturn')->assertHasNoErrors()
            ->assertRedirect(route('admin.taxonomies.index', ['section' => 'tin-tuc', 'open' => $this->jobs->ulid]));
        expect(session(BandEditor::FLASH))->toBe(__('categories.band_saved_back', ['band' => __('categories.ui_band_top'), 'name' => 'Tuyển dụng']));
    });

    expect(array_keys(beStored($this->jobs, 'before_list')))->toBe(['vi']);
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

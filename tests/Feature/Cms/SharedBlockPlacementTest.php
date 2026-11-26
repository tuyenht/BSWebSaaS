<?php

use App\Modules\Admin\Livewire\Entries\Editor as EntryEditor;
use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;
use App\Modules\Admin\Support\Settings\SetLayoutBlocks;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Cms\Actions\ContentBlocks\SaveContentBlockBody;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\SetCategoryLayoutBlocks;
use App\Modules\Cms\ContentBlocks\ContentBlockVersion;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\ContentBlockChangeRejectedException;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\BlockUsageIndex;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Validation\EntryContentRules;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\BlockReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

/*
 * Where a website's shared blocks (Khối dùng chung) are placed: a page or post body, a category's
 * own slot, the website's default slot, a menu item's large panel. Each save rewrites the owner's
 * rows of `block_usages` in its own transaction, for this website's blocks only (a ulid of another
 * website, or one never issued, is never recorded, and an owner of another website is refused);
 * `blocks:reconcile-usage` rebuilds the index for what never passed through a save. On save a body
 * may name only this website's blocks, at most BlockReference::LIST_MAX of them, never inside a
 * shared block's own body and never in a slot that takes none.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function sbpBlock(int $siteId, string $key, string $status = 'published'): ContentBlock
{
    return app(Tenancy::class)->enter($siteId, fn (): ContentBlock => ContentBlock::query()->create([
        'key' => $key, 'name' => 'Khối '.$key, 'region' => 'inline', 'status' => $status,
    ]));
}

/** @return array{type: string, props: array{block: string}} */
function sbpRef(string $ulid): array
{
    return ['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => $ulid]];
}

/**
 * The rows of `block_usages`, read past RLS so another website's row would show: one string per row.
 *
 * @return list<string>
 */
function sbpRows(): array
{
    return DB::connection('pgsql_admin')->table('block_usages')
        ->join('content_blocks', 'content_blocks.id', '=', 'block_usages.content_block_id')
        ->orderBy('content_blocks.key')->orderBy('block_usages.locale')->orderBy('block_usages.slot')
        ->get(['block_usages.site_id', 'content_blocks.key', 'block_usages.locale', 'block_usages.slot', 'block_usages.entry_id', 'block_usages.taxonomy_id', 'block_usages.site_setting_id', 'block_usages.menu_item_id'])
        ->map(function (object $row): string {
            $owner = match (true) {
                $row->entry_id !== null => 'entry',
                $row->taxonomy_id !== null => 'category',
                $row->site_setting_id !== null => 'setting',
                default => 'menu',
            };

            return "{$row->site_id}:{$row->key}:{$owner}:".($row->slot ?? '-').':'.($row->locale ?? '-');
        })->all();
}

/** A page of site 1 whose bodies are locale → blocks, saved as the editor saves (the reconciler). */
function sbpPage(array $bodies, string $slug = 'gioi-thieu'): Entry
{
    return app(Tenancy::class)->enter(1, function () use ($bodies, $slug): Entry {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
        foreach ($bodies as $locale => $blocks) {
            $entry->translations()->create(['locale' => $locale, 'title' => 'Giới thiệu', 'slug' => "{$slug}-{$locale}", 'status' => 'draft', 'body' => ['blocks' => $blocks]]);
        }
        app(EntryBlockMediaReconciler::class)->reconcile($entry);

        return $entry;
    });
}

function sbpEnableEnglish(): void
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** The first error of a body checked by $guard in site 1, or null. */
function sbpBodyError(array $blocks, ?ValidPageJson $guard = null): ?string
{
    return app(Tenancy::class)->enter(1, function () use ($blocks, $guard): ?string {
        $v = Validator::make(['blocks' => $blocks], ['blocks' => [$guard ?? app(ValidPageJson::class)]]);

        return $v->fails() ? $v->errors()->first('blocks') : null;
    });
}

describe('recording where a block is placed', function (): void {
    it('records each language of a page body, and only blocks of this website that exist', function (): void {
        sbpEnableEnglish();
        $footer = sbpBlock(1, 'chan-trang');
        $draft = sbpBlock(1, 'nhap', 'draft');
        $foreign = sbpBlock(2, 'chan-trang');

        $entry = sbpPage([
            'vi' => [['type' => 'cta', 'props' => ['heading' => 'Liên hệ']], sbpRef($footer->ulid), sbpRef($draft->ulid), sbpRef($foreign->ulid), sbpRef('01J00000000000000000000000'), sbpRef(strtolower($footer->ulid))],
            'en' => [sbpRef($footer->ulid)],
        ]);

        // a draft counts (it shows once published); the same block twice in one body is one row
        expect(sbpRows())->toBe(['1:chan-trang:entry:-:en', '1:chan-trang:entry:-:vi', '1:nhap:entry:-:vi']);

        app(Tenancy::class)->enter(1, function () use ($entry): void {
            $entry->translations()->where('locale', 'vi')->update(['body' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Liên hệ']]]]]);
            app(EntryBlockMediaReconciler::class)->reconcile($entry);
        });
        expect(sbpRows())->toBe(['1:chan-trang:entry:-:en']);
    });

    it('writes nothing when the save rolls back', function (): void {
        $footer = sbpBlock(1, 'chan-trang');

        expect(fn () => app(Tenancy::class)->enter(1, function () use ($footer): void {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft', 'body' => ['blocks' => [sbpRef($footer->ulid)]]]);
            app(EntryBlockMediaReconciler::class)->reconcile($entry);
            throw new RuntimeException('the save failed later');
        }))->toThrow(RuntimeException::class);

        expect(sbpRows())->toBe([]);
    });

    it('refuses an owner of another website, and one outside any website', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $stranger = (new Entry)->forceFill(['id' => 999_999, 'site_id' => 2]);

        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(BlockUsageIndex::class)->syncEntry($stranger, ['vi' => [sbpRef($footer->ulid)]])))
            ->toThrow(LogicException::class)
            ->and(fn () => app(BlockUsageIndex::class)->syncEntry($stranger, ['vi' => [sbpRef($footer->ulid)]]))
            ->toThrow(LogicException::class)
            ->and(sbpRows())->toBe([]);
    });

    it('keeps the rows of a page in the Trash and drops them when it is deleted for good', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $entry = sbpPage(['vi' => [sbpRef($footer->ulid)]]);

        app(Tenancy::class)->enter(1, fn () => $entry->delete());
        expect(sbpRows())->toBe(['1:chan-trang:entry:-:vi']);

        app(Tenancy::class)->enter(1, fn () => Entry::withTrashed()->findOrFail($entry->id)->forceDelete());
        expect(sbpRows())->toBe([]);
    });

    it('records a category\'s own slot and the website\'s default slot, per slot and language', function (): void {
        $admin = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage', 'taxonomies.manage']));
        app(TeamContext::class)->forget();
        $this->actingAs($admin->fresh() ?? $admin);
        $footer = sbpBlock(1, 'chan-trang');
        $offer = sbpBlock(1, 'uu-dai');
        $foreign = sbpBlock(2, 'ngoai');
        $category = app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức'])));

        app(Tenancy::class)->enter(1, function () use ($category, $footer, $offer): void {
            app(SetCategoryLayoutBlocks::class)->handle($category, [
                'after_list' => ['vi' => ['blocks' => [sbpRef($offer->ulid)]]],
                'post_aside' => ['vi' => ['blocks' => [['type' => 'cta', 'props' => ['heading' => 'Hỏi giá']], sbpRef($footer->ulid)]]],
            ]);
            app(SetLayoutBlocks::class)->handle(['after_post' => ['vi' => ['blocks' => [sbpRef($footer->ulid)]]]]);
        });
        expect(sbpRows())->toBe(['1:chan-trang:setting:after_post:vi', '1:chan-trang:category:post_aside:vi', '1:uu-dai:category:after_list:vi']);

        // taking a slot away takes its rows; the other owner keeps its own
        app(Tenancy::class)->enter(1, fn () => app(SetCategoryLayoutBlocks::class)->handle($category->fresh() ?? $category, ['post_aside' => null]));
        expect(sbpRows())->toBe(['1:chan-trang:setting:after_post:vi', '1:uu-dai:category:after_list:vi']);

        // a block of another website is refused at the save, so it is never recorded
        $refused = null;
        try {
            app(Tenancy::class)->enter(1, fn () => app(SetLayoutBlocks::class)->handle(['after_list' => ['vi' => ['blocks' => [sbpRef($foreign->ulid)]]]]));
        } catch (SettingsChangeRejected $e) {
            $refused = $e->getPrevious()?->getMessage();
        }
        expect($refused)->toContain(__('entries.err_block_ref_missing', ['position' => 1]))
            ->and(sbpRows())->toBe(['1:chan-trang:setting:after_post:vi', '1:uu-dai:category:after_list:vi']);
    });

    it('refuses a shared block in the product information tabs', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $category = app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('product_cat', null, new TaxonomyData(names: ['vi' => 'Máy cắt'])));

        $refused = null;
        try {
            app(Tenancy::class)->enter(1, fn () => app(SetCategoryLayoutBlocks::class)->handle($category, ['product_tabs' => ['vi' => ['blocks' => [sbpRef($footer->ulid)]]]]));
        } catch (TaxonomyChangeRejectedException $e) {
            $refused = $e->getPrevious()?->getMessage();
        }

        expect($refused)->toContain(__('layout_slots.err_ref_slot'))
            ->and(sbpRows())->toBe([]);
    });

    it('records the block a menu item\'s large panel shows, with no language', function (): void {
        $menuBlock = sbpBlock(1, 'bang-lon');
        $foreign = sbpBlock(2, 'ngoai');

        app(Tenancy::class)->enter(1, function () use ($menuBlock, $foreign): void {
            $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
            $shown = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/', 'position' => 0, 'meta' => ['mega' => ['type' => 'block', 'block' => $menuBlock->ulid]]]);
            $other = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/a', 'position' => 1, 'meta' => ['mega' => ['type' => 'block', 'block' => $foreign->ulid]]]);
            app(BlockUsageIndex::class)->syncMenuItems([$shown, $other]);
        });

        expect(sbpRows())->toBe(['1:bang-lon:menu:-:-']);
    });
});

describe('rebuilding the index', function (): void {
    it('rebuilds what never passed through a save, drops what no place holds, and is idempotent', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $offer = sbpBlock(1, 'uu-dai');
        $other = sbpBlock(2, 'chan-trang');

        app(Tenancy::class)->enter(1, function () use ($footer, $offer): void {
            // a seeded page that never went through a save …
            $seeded = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $seeded->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft', 'body' => ['blocks' => [sbpRef($footer->ulid)]]]);
            // … a page in the Trash that still places one …
            $trashed = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $trashed->translations()->create(['locale' => 'vi', 'title' => 'U', 'slug' => 'u', 'status' => 'draft', 'body' => ['blocks' => [sbpRef($offer->ulid)]]]);
            $trashed->delete();
            // … and a stale row for a page that places nothing any more
            $stale = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $stale->translations()->create(['locale' => 'vi', 'title' => 'V', 'slug' => 'v', 'status' => 'draft', 'body' => ['blocks' => []]]);
            DB::table('block_usages')->insert(['site_id' => 1, 'content_block_id' => $offer->id, 'entry_id' => $stale->id, 'locale' => 'vi', 'created_at' => now()]);
        });
        app(Tenancy::class)->enter(2, function () use ($other): void {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft', 'body' => ['blocks' => [sbpRef($other->ulid)]]]);
        });

        $this->artisan('blocks:reconcile-usage', ['--site' => 1, '--chunk' => 1])->assertSuccessful();
        $once = sbpRows();
        expect($once)->toBe(['1:chan-trang:entry:-:vi', '1:uu-dai:entry:-:vi']);

        $this->artisan('blocks:reconcile-usage')->assertSuccessful();
        expect(sbpRows())->toBe(['1:chan-trang:entry:-:vi', '2:chan-trang:entry:-:vi', '1:uu-dai:entry:-:vi']);

        $this->artisan('blocks:reconcile-usage')->assertSuccessful();
        expect(sbpRows())->toBe(['1:chan-trang:entry:-:vi', '2:chan-trang:entry:-:vi', '1:uu-dai:entry:-:vi']);
    });

    it('rebuilds a category slot, the default slot and a menu panel, and drops rows whose owner the site does not have', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $offer = sbpBlock(1, 'uu-dai');
        $panel = sbpBlock(1, 'bang-lon');
        $category = app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức'])));
        $foreignPage = app(Tenancy::class)->enter(2, fn (): Entry => Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]));

        $seo = app(Tenancy::class)->enter(1, function () use ($category, $footer, $offer, $panel): SiteSetting {
            // written by hand: none of them passed through a save
            Taxonomy::query()->whereKey($category->id)->update(['meta->layout_blocks' => ['after_list' => ['vi' => ['blocks' => [sbpRef($offer->ulid)]]]]]);
            SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'layout_blocks', 'value' => ['after_post' => ['vi' => ['blocks' => [sbpRef($footer->ulid)]]]]]);
            $menu = Menu::query()->create(['name' => 'Chính', 'handle' => 'primary']);
            MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/', 'position' => 0, 'meta' => ['mega' => ['type' => 'block', 'block' => $panel->ulid]]]);

            return SiteSetting::query()->firstOrCreate(['group_name' => 'seo', 'key' => 'config'], ['value' => []]);
        });
        // rows of site 1 pointing at a page of site 2 and at a settings row that holds no block slots
        DB::connection('pgsql_admin')->table('block_usages')->insert([
            ['site_id' => 1, 'content_block_id' => $footer->id, 'entry_id' => $foreignPage->id, 'site_setting_id' => null, 'slot' => null, 'locale' => 'vi', 'created_at' => now()],
            ['site_id' => 1, 'content_block_id' => $offer->id, 'entry_id' => null, 'site_setting_id' => $seo->id, 'slot' => 'after_post', 'locale' => 'vi', 'created_at' => now()],
        ]);

        $this->artisan('blocks:reconcile-usage', ['--site' => 1])->assertSuccessful();

        expect(sbpRows())->toBe(['1:bang-lon:menu:-:-', '1:chan-trang:setting:after_post:vi', '1:uu-dai:category:after_list:vi']);
    });

    it('refuses a site that does not exist', function (): void {
        $this->artisan('blocks:reconcile-usage', ['--site' => 77])->assertFailed();
    });
});

describe('checking a body on save', function (): void {
    it('takes a reference to a block of this website, a draft included', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $draft = sbpBlock(1, 'nhap', 'draft');

        expect(sbpBodyError([sbpRef($footer->ulid), sbpRef(strtolower($draft->ulid))]))->toBeNull();
    });

    it('refuses a reference with no block, a bad code or anything else in it', function (): void {
        $footer = sbpBlock(1, 'chan-trang');

        expect(sbpBodyError([['type' => 'cta', 'props' => []], ['type' => BlockReference::TYPE, 'props' => []]]))->toBe(__('entries.err_block_ref_invalid', ['position' => 2]))
            ->and(sbpBodyError([sbpRef('not-a-ulid')]))->toBe(__('entries.err_block_ref_invalid', ['position' => 1]))
            ->and(sbpBodyError([sbpRef('01J0000000000000000000000I')]))->toBe(__('entries.err_block_ref_invalid', ['position' => 1]))
            ->and(sbpBodyError([['type' => BlockReference::TYPE, 'props' => ['block' => $footer->ulid, 'heading' => 'x']]]))->toBe(__('entries.err_block_ref_invalid', ['position' => 1]));
    });

    it('refuses a block of another website and one that is gone', function (): void {
        $foreign = sbpBlock(2, 'chan-trang');

        expect(sbpBodyError([sbpRef($foreign->ulid)]))->toBe(__('entries.err_block_ref_missing', ['position' => 1]))
            ->and(sbpBodyError([['type' => 'cta', 'props' => ['heading' => 'Liên hệ']], sbpRef('01J00000000000000000000000')]))->toBe(__('entries.err_block_ref_missing', ['position' => 2]));
    });

    it('keeps a block the place stores once it is deleted, and still refuses a new one that is not this website\'s', function (): void {
        $gone = sbpBlock(1, 'da-xoa');
        $foreign = sbpBlock(2, 'ngoai');
        $entry = sbpPage(['vi' => [sbpRef($gone->ulid)]]);
        app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->whereKey($gone->id)->delete());
        $guard = app(ValidPageJson::class)->keeping(EntryContentRules::storedRefs($entry->ulid));
        $cta = ['type' => 'cta', 'props' => ['heading' => 'Mới']];

        expect(sbpBodyError([$cta, sbpRef(strtolower($gone->ulid))], $guard))->toBeNull()
            ->and(sbpBodyError([sbpRef($gone->ulid), sbpRef('01J00000000000000000000000')], $guard))->toBe(__('entries.err_block_ref_missing', ['position' => 2]))
            ->and(sbpBodyError([sbpRef($gone->ulid), $cta, sbpRef($foreign->ulid)], $guard))->toBe(__('entries.err_block_ref_missing', ['position' => 3]))
            // with nothing stored, the deleted block is new to the place
            ->and(sbpBodyError([sbpRef($gone->ulid)]))->toBe(__('entries.err_block_ref_missing', ['position' => 1]));
    });

    it('lets the editor save a page whose placed block was deleted, and refuses one it adds', function (): void {
        $user = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($user, 'site-admin', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']));
        app(TeamContext::class)->forget();
        $gone = sbpBlock(1, 'da-xoa');
        $entry = sbpPage(['vi' => [['type' => 'cta', 'props' => ['heading' => 'Liên hệ']], sbpRef($gone->ulid)]]);
        app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->whereKey($gone->id)->delete());

        app(Tenancy::class)->enter(1, function () use ($user, $entry): void {
            $editor = Livewire::actingAs($user)->test(EntryEditor::class, ['ulid' => $entry->ulid])
                ->set('title', 'Giới thiệu mới')
                ->call('save')
                ->assertHasNoErrors();
            expect($entry->translations()->where('locale', 'vi')->value('title'))->toBe('Giới thiệu mới');

            $editor->set('blocks', [...$editor->get('blocks'), sbpRef('01J00000000000000000000000')])
                ->call('save')
                ->assertHasErrors('blocks');
        });
    });

    it('lets a category and the website keep a slot whose placed block was deleted', function (): void {
        $admin = User::factory()->create();
        app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', ['settings.manage', 'taxonomies.manage']));
        app(TeamContext::class)->forget();
        $this->actingAs($admin->fresh() ?? $admin);
        $gone = sbpBlock(1, 'da-xoa');
        $category = app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức'])));
        $slot = fn (array $more = []): array => ['vi' => ['blocks' => [sbpRef($gone->ulid), ...$more]]];
        app(Tenancy::class)->enter(1, function () use ($category, $slot): void {
            app(SetCategoryLayoutBlocks::class)->handle($category, ['after_list' => $slot()]);
            app(SetLayoutBlocks::class)->handle(['after_post' => $slot()]);
        });
        app(Tenancy::class)->enter(1, fn () => ContentBlock::query()->whereKey($gone->id)->delete());

        $cta = ['type' => 'cta', 'props' => ['heading' => 'Hỏi giá']];
        app(Tenancy::class)->enter(1, function () use ($category, $slot, $cta): void {
            app(SetCategoryLayoutBlocks::class)->handle($category->fresh() ?? $category, ['after_list' => $slot([$cta])]);
            app(SetLayoutBlocks::class)->handle(['after_post' => $slot([$cta])]);
        });

        $refused = null;
        try {
            app(Tenancy::class)->enter(1, fn () => app(SetLayoutBlocks::class)->handle(['after_post' => $slot([sbpRef('01J00000000000000000000000')])]));
        } catch (SettingsChangeRejected $e) {
            $refused = $e->getPrevious()?->getMessage();
        }
        expect($refused)->toBe(__('entries.err_block_ref_missing', ['position' => 2]));
    });

    it('refuses more references than one body may hold', function (): void {
        $footer = sbpBlock(1, 'chan-trang');
        $max = array_fill(0, BlockReference::LIST_MAX, sbpRef($footer->ulid));

        expect(sbpBodyError($max))->toBeNull()
            ->and(sbpBodyError([...$max, sbpRef($footer->ulid)]))->toBe(__('entries.err_block_refs_too_many', ['max' => BlockReference::LIST_MAX]));
    });

    it('refuses a reference inside a shared block\'s own body', function (): void {
        $footer = sbpBlock(1, 'chan-trang');

        expect(sbpBodyError([['type' => 'cta', 'props' => []], sbpRef($footer->ulid)], app(ValidPageJson::class)->forBlockBody()))
            ->toBe(__('entries.err_block_ref_nested', ['position' => 2]));
    });
});

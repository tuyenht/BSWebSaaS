<?php

use App\Modules\Admin\Livewire\Blocks\Editor as BlockEditor;
use App\Modules\Admin\Livewire\Blocks\Library;
use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Xuất bản» / «Đưa về nháp» in a block row's «⋯» (Khối dùng chung) and «Lưu & xuất bản» in the block
 * editor of a block still in draft: publishing acts at once, taking a used block back to draft asks
 * first, and only whoever may manage blocks can do either.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'blocks', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

function statusBlock(string $key, string $status, string $region = 'inline'): ContentBlock
{
    return app(Tenancy::class)->enter(1, fn (): ContentBlock => ContentBlock::query()->create([
        'key' => $key, 'name' => 'Khối '.$key, 'region' => $region, 'status' => $status,
    ]));
}

function placeBlock(ContentBlock $block): void
{
    app(Tenancy::class)->enter(1, function () use ($block): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        BlockUsage::query()->create(['content_block_id' => $block->id, 'entry_id' => $entry->id, 'locale' => 'vi']);
    });
}

it('publishes a draft block at once and says where visitors see it', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = statusBlock('chan-trang', 'draft');
    $used = statusBlock('khac', 'draft');
    placeBlock($used);

    app(Tenancy::class)->enter(1, function () use ($editor, $block, $used): void {
        Livewire::actingAs($editor)->test(Library::class)->call('publish', $block->ulid)
            ->assertSet('confirmOpen', false)
            ->assertSet('toastMsg', __('blocks.toast_published_none', ['name' => $block->name]));
        expect($block->fresh()->status)->toBe(ContentBlock::PUBLISHED);

        Livewire::actingAs($editor)->test(Library::class)->call('publish', $used->ulid)
            ->assertSet('toastMsg', __('blocks.toast_published', ['name' => $used->name, 'places' => trans_choice('blocks.n_places', 1, ['n' => 1])]));
    });
});

it('takes a block that is used nowhere back to draft at once', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = statusBlock('chan-trang', 'published');

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        Livewire::actingAs($editor)->test(Library::class)->call('askUnpublish', $block->ulid)
            ->assertSet('confirmOpen', false)
            ->assertSet('toastMsg', __('blocks.toast_unpublished', ['name' => $block->name]));
        expect($block->fresh()->status)->toBe(ContentBlock::DRAFT);
    });
});

it('asks before taking a used block back to draft, in the delete question\'s dialog', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = statusBlock('bang-lon', 'published', 'menu');
    placeBlock($block);

    app(Tenancy::class)->enter(1, function () use ($editor, $block): void {
        $page = Livewire::actingAs($editor)->test(Library::class)->call('askUnpublish', $block->ulid)
            ->assertSet('confirmOpen', true)->assertSet('asking', 'draft')
            ->assertSet('deleting.places', 1)->assertSet('deleting.menu', true)
            ->assertSee(__('blocks.unpub_title'))->assertSee(__('blocks.unpub_menu'));
        // as the board draws it: a warning-tinted icon box, and no bin on the «Đưa về nháp» button
        expect($page->html())->toContain('bg-warning-soft text-warning-text')
            ->and($page->html())->not->toContain('ri-delete-bin-6')
            ->and($block->fresh()->status)->toBe(ContentBlock::PUBLISHED);

        // «Ở lại» leaves it published and the dialog goes back to being the delete question
        $page->call('cancelDelete')->assertSet('confirmOpen', false)->assertSet('asking', 'delete');
        expect($block->fresh()->status)->toBe(ContentBlock::PUBLISHED);

        $page->call('askUnpublish', $block->ulid)->call('confirmUnpublish')
            ->assertSet('confirmOpen', false)
            ->assertSet('toastMsg', __('blocks.toast_unpublished', ['name' => $block->name]));
        expect($block->fresh()->status)->toBe(ContentBlock::DRAFT)
            ->and(BlockUsage::query()->where('content_block_id', $block->id)->count())->toBe(1);
    });
});

it('refuses publishing and taking back to draft to a member who may not manage blocks', function (): void {
    $viewer = blockLibraryUser(1, 'viewer');
    $draft = statusBlock('a', 'draft');
    $live = statusBlock('b', 'published');

    app(Tenancy::class)->enter(1, function () use ($viewer, $draft, $live): void {
        Livewire::actingAs($viewer)->test(Library::class)->assertForbidden();
        Livewire::actingAs($viewer)->test(BlockEditor::class, ['ulid' => $draft->ulid])->assertForbidden();
        expect($draft->fresh()->status)->toBe(ContentBlock::DRAFT)->and($live->fresh()->status)->toBe(ContentBlock::PUBLISHED);
    });
});

it('offers the right item in the row menu for each status', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $block = statusBlock('nhap', 'draft');
    $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertOk()
        ->assertSeeText(__('blocks.publish'))->assertDontSeeText(__('blocks.unpublish'));

    app(Tenancy::class)->enter(1, fn (): int => ContentBlock::query()->whereKey($block->id)->update(['status' => 'published']));
    $this->actingAs($editor)->get('http://a.test/admin/blocks')->assertOk()
        ->assertSeeText(__('blocks.unpublish'))->assertDontSee('wire:click="publish(', false);
});

it('offers «Lưu & xuất bản» in the block editor only while the block is a draft, and publishes with the save', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $draft = statusBlock('nhap', 'draft');
    $live = statusBlock('live', 'published');

    $this->actingAs($editor)->get('http://a.test/admin/blocks/'.$draft->ulid.'/edit')->assertOk()
        ->assertSeeText(__('blocks.editor_save_publish'))->assertSeeText(trim(__('blocks.editor_band_note_draft')));
    $this->actingAs($editor)->get('http://a.test/admin/blocks/'.$live->ulid.'/edit')->assertOk()
        ->assertDontSeeText(__('blocks.editor_save_publish'))->assertSeeText(trim(__('blocks.editor_band_note')));

    app(Tenancy::class)->enter(1, function () use ($editor, $draft): void {
        Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $draft->ulid])->assertSet('isDraft', true)
            ->call('addBlock', 'richtext')->call('saveAndPublish')
            ->assertRedirect(route('admin.blocks.index'));
        expect($draft->fresh()->status)->toBe(ContentBlock::PUBLISHED)
            ->and(session(Library::FLASH))->toBe(__('blocks.toast_published_back', ['name' => $draft->name]));
    });
});

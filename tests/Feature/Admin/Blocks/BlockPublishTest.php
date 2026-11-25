<?php

use App\Modules\Admin\Livewire\Blocks\Editor as BlockEditor;
use App\Modules\Admin\Livewire\Blocks\Library;
use App\Modules\Cms\Models\BlockUsage;
use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\Entry;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

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
        // the bin is drawn on the rows' «Xóa» items too: the delete question's button adds one, this question's does not
        preg_match('/ d="([^"]+)"/', Blade::render('<x-bs-admin::icon name="ri-delete-bin-6" />'), $bin);
        $binsWhenDeleting = substr_count(Livewire::actingAs($editor)->test(Library::class)->call('askDelete', $block->ulid)->html(), $bin[1] ?? 'no-bin-path');
        $page = Livewire::actingAs($editor)->test(Library::class)->call('askUnpublish', $block->ulid)
            ->assertSet('confirmOpen', true)->assertSet('asking', 'draft')
            ->assertSet('deleting.places', 1)->assertSet('deleting.menu', true)
            ->assertSee(__('blocks.unpub_title'))->assertSee(__('blocks.unpub_menu'));
        // as the board draws it: a warning-tinted icon box, and no bin on the «Đưa về nháp» button
        // the icon component prints only the path, so count the bin's path itself
        expect($bin[1] ?? '')->not->toBe('')
            ->and($page->html())->toContain('bg-warning-soft text-warning-text')
            ->and(substr_count($page->html(), $bin[1] ?? 'no-bin-path'))->toBe($binsWhenDeleting - 1)
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

it('refuses every new publish method once the member\'s right is taken away from an open screen', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $draft = statusBlock('a', 'draft');
    $live = statusBlock('b', 'published', 'menu');
    placeBlock($live);

    app(Tenancy::class)->enter(1, function () use ($editor, $draft, $live): void {
        // one screen per method: a refused call leaves its screen unusable for the next one
        $toPublish = Livewire::actingAs($editor)->test(Library::class);
        $asked = Livewire::actingAs($editor)->test(Library::class)->call('askUnpublish', $live->ulid)->assertSet('asking', 'draft');
        $toAsk = Livewire::actingAs($editor)->test(Library::class);
        $editorScreen = Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $draft->ulid]);

        // the same open screens, the member's role taken away meanwhile
        Role::findByName('editor', PermissionRegistry::GUARD)->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $toPublish->call('publish', $draft->ulid);
        $asked->call('confirmUnpublish');
        $toAsk->call('askUnpublish', $live->ulid);
        $editorScreen->call('saveAndPublish');

        expect($draft->fresh()->status)->toBe(ContentBlock::DRAFT)
            ->and($live->fresh()->status)->toBe(ContentBlock::PUBLISHED);
    });
});

it('says how many places show the block when «Lưu & xuất bản» publishes one that is already placed', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $draft = statusBlock('dat-cho', 'draft');
    placeBlock($draft);

    app(Tenancy::class)->enter(1, function () use ($editor, $draft): void {
        Livewire::actingAs($editor)->test(BlockEditor::class, ['ulid' => $draft->ulid])
            ->call('addBlock', 'richtext')->call('saveAndPublish')
            ->assertRedirect(route('admin.blocks.index'));
        expect($draft->fresh()->status)->toBe(ContentBlock::PUBLISHED)
            ->and(session(Library::FLASH))->toBe(__('blocks.toast_published', ['name' => $draft->name, 'places' => trans_choice('blocks.n_places', 1, ['n' => 1])]));
    });
});

it('does not delete when the open question is «Đưa về nháp», nor take to draft when it is the delete question', function (): void {
    $editor = blockLibraryUser(1, 'editor');
    $live = statusBlock('c', 'published', 'menu');
    placeBlock($live);

    app(Tenancy::class)->enter(1, function () use ($editor, $live): void {
        Livewire::actingAs($editor)->test(Library::class)->call('askUnpublish', $live->ulid)->call('confirmDelete');
        expect(ContentBlock::query()->whereKey($live->id)->exists())->toBeTrue()
            ->and($live->fresh()->status)->toBe(ContentBlock::PUBLISHED);

        Livewire::actingAs($editor)->test(Library::class)->call('askDelete', $live->ulid)->call('confirmUnpublish');
        expect($live->fresh()->status)->toBe(ContentBlock::PUBLISHED);
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
            ->and(session(Library::FLASH))->toBe(__('blocks.toast_published_none', ['name' => $draft->name]));
    });
});

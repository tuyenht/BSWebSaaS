<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The library's file detail drawer (board 12): a file opens by its ulid, live or (for someone who
 * sees the Trash) trashed; its description and title are saved per website language with their
 * limits; the ⋮ menu and the footer offer only what this person's rights allow, and the server
 * refuses the rest; unsaved words are counted for the shared unsaved-changes dialog.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    app()->setLocale('vi');
});

/** @param  array<string, mixed>  $attrs */
function drwFile(string $name, array $attrs = [], int $site = 1): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => pathinfo($name, PATHINFO_FILENAME),
        'hash' => hash('sha256', $site.$name),
        'storage_key' => "s{$site}/library/{$name}",
        'original_filename' => $name,
        'mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'image/png',
        'size' => 1_572_864,
        'width' => 1600,
        'height' => 1200,
    ], $attrs)));
}

/** @param  list<string>  $rights */
function drwUser(array $rights): User
{
    $user = User::factory()->create(['name' => 'Chị Lan']);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $rights));

    return $user->fresh() ?? $user;
}

/** A member of site 1 holding exactly what the shipped role template of that name grants. */
function drwRole(string $role, string $name = 'Anh Bình'): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$role]['permissions'];
    $user = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec));

    return $user->fresh() ?? $user;
}

/** Site 1 writes in Vietnamese (the default) and English. Call inside the site. */
function drwEnglish(): void
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(SiteLocale::class)->forget();
}

/** A product with that code shows the file in its gallery. Call inside the site. */
function drwUse(Media $file, string $sku = 'VMC-850'): void
{
    $product = Product::query()->create(['status' => 'published', 'sku' => $sku]);
    ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $file->id, 'position' => 0]);
}

it('opens a picture in the drawer with its words, address, details and where it is used', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $folder = app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->create(['name' => 'Ảnh xưởng', 'slug' => 'anh-xuong', 'position' => 1]));
    $file = drwFile('may-phay.png', ['alt' => ['vi' => 'Máy phay đứng', 'ja' => 'フライス盤'], 'title' => ['vi' => 'Máy phay VMC'],
        'folder_id' => $folder->id, 'created_by' => $user->id, 'created_at' => '2026-10-07 03:00:00']);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        drwUse($file);
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('openDetail', $file->ulid)
            ->assertSet('pickerDetailUlid', $file->ulid)
            ->assertSet('detailAlt', ['vi' => 'Máy phay đứng'])
            ->assertSet('detailTitle', ['vi' => 'Máy phay VMC']);

        $html = $page->html();
        expect($html)->toContain(e(__('media.drw.title_image')))
            ->toContain('may-phay.png')
            ->toContain('wire:model="detailAlt.vi"')
            ->toContain('wire:model="detailTitle.vi"')
            ->toContain('maxlength="188"')
            ->toContain('maxlength="120"')
            ->toContain(e(__('media.drw.alt_hint')))
            ->toContain('s1/library/may-phay.png')
            ->toContain('1.600 × 1.200 điểm ảnh')
            ->toContain('1,5 MB')
            ->toContain('Ảnh xưởng')
            ->toContain('Chị Lan')
            ->toContain(e(__('media.drw.at')))
            ->toContain('07/10/2026')
            ->toContain(e(__('media.drw.used', ['n' => 1])))
            ->toContain(e(__('media.drw.g_product')).' · 1')
            ->toContain('VMC-850')
            ->toContain(e(__('media.drw.inline_note')))
            ->toContain('data-bs-lazy="bsMediaDrawer"')
            ->toContain('id="media-detail-guard"')
            ->not->toContain('lang="ja"'); // a language the website does not write in has no field

        // one language: no language chips
        expect($html)->not->toContain(e(__('media.drw.langs')));
    });
});

it('names a document «Chi tiết tệp» and shows no picture words for it', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $file = drwFile('bang-gia.pdf', ['width' => null, 'height' => null]);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $html = Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)->html();

        expect($html)->toContain(e(__('media.drw.title_file')))
            ->toContain(e(__('media.drw.pdf')))
            ->not->toContain('wire:model="detailAlt.vi"')
            ->not->toContain('$wire.saveDetail()')
            ->not->toContain('data-unsaved-body="'.e(__('media.drw.ud_body')).'"');
    });
});

it('saves the description and title of every website language at once, keeping other languages', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $file = drwFile('dao-phay.png', ['alt' => ['vi' => 'Dao cũ', 'ja' => 'カッター'], 'title' => ['vi' => 'Tiêu đề cũ']]);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        drwEnglish();
        $page = Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)
            ->assertSet('detailAlt', ['vi' => 'Dao cũ', 'en' => ''])
            ->set('detailAlt.vi', '  Dao phay ngón  ')
            ->set('detailAlt.en', 'End mill')
            ->set('detailTitle.vi', '')
            ->set('detailTitle.en', 'Carbide end mill')
            ->call('saveDetail')
            ->assertHasNoErrors()
            ->assertSet('detailSaved', true)
            ->assertSet('detailAlt', ['vi' => 'Dao phay ngón', 'en' => 'End mill'])
            ->assertDispatched('media-detail-saved', ok: true, lang: '');

        $fresh = $file->fresh();
        expect($fresh->alt)->toEqualCanonicalizing(['vi' => 'Dao phay ngón', 'en' => 'End mill', 'ja' => 'カッター'])
            ->and($fresh->alt)->toHaveCount(3)
            ->and($fresh->title)->toBe(['en' => 'Carbide end mill']); // the emptied Vietnamese title is gone

        expect($page->html())->toContain(e(__('media.drw.saved')))
            ->toContain(e(__('media.drw.langs')))
            ->toContain('wire:model="detailAlt.en"')
            ->toContain('data-unsaved-count="0"');
    });
});

it('refuses a description or title over its limit, saves nothing and names the language', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $file = drwFile('mui-khoan.png', ['alt' => ['vi' => 'Mũi khoan']]);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        drwEnglish();
        $page = Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)
            ->set('detailAlt.vi', 'Mũi khoan mới')
            ->set('detailAlt.en', str_repeat('ả', Media::ALT_MAX_LENGTH + 1))
            ->call('saveDetail')
            ->assertHasErrors(['detailAlt.en' => __('media.drw.alt_long', ['max' => 188])])
            ->assertSet('toast', __('media.drw.save_failed'))
            ->assertSet('toastTone', 'error')
            ->assertDispatched('media-detail-saved', ok: false, lang: 'en');
        expect($file->fresh()->alt)->toBe(['vi' => 'Mũi khoan']) // nothing saved, not even the valid language
            ->and($page->html())->toContain('aria-invalid="true"')
            ->and($page->get('detailAlt.en'))->toBe(str_repeat('ả', 189)); // the typed text stays

        $page->set('detailAlt.en', str_repeat('ả', Media::ALT_MAX_LENGTH))
            ->set('detailTitle.vi', str_repeat('t', Media::TITLE_MAX_LENGTH + 1))
            ->call('saveDetail')
            ->assertHasErrors(['detailTitle.vi' => __('media.drw.title_long', ['max' => 120])]);

        $page->set('detailTitle.vi', str_repeat('t', Media::TITLE_MAX_LENGTH))->call('saveDetail')->assertHasNoErrors();
        expect($file->fresh()->alt['en'])->toBe(str_repeat('ả', 188))
            ->and($file->fresh()->title['vi'])->toBe(str_repeat('t', 120));

        // a forged payload that is not text is refused like an over-long one
        $page->set('detailAlt.vi', ['x'])->call('saveDetail')->assertHasErrors(['detailAlt.vi']);
    });
});

it('counts unsaved words for the unsaved-changes dialog and drops them on discard', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $file = drwFile('be-gia.png', ['alt' => ['vi' => 'Bệ gá']]);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid);
        expect($page->html())->toContain('data-unsaved-count="0"')
            ->toContain('data-unsaved-body="'.e(__('media.drw.ud_body')).'"')
            ->toContain('data-unsaved-save="'.e(__('media.drw.ud_save')).'"')
            ->toContain(e(__('media.drw.ud_discard')));

        $page->set('detailAlt.vi', 'Bệ gá mới')->set('detailTitle.vi', 'Bệ');
        expect($page->html())->toContain('data-unsaved-count="2"');

        $page->call('discardDetail')->assertSet('detailAlt', ['vi' => 'Bệ gá'])->assertSet('detailTitle', ['vi' => '']);
        expect($page->html())->toContain('data-unsaved-count="0"')
            ->and($file->fresh()->alt)->toBe(['vi' => 'Bệ gá']);

        $page->call('closeDetail')->assertSet('pickerDetailUlid', null)->assertSet('detailAlt', []);
        expect($page->html())->not->toContain('id="media-detail-guard"');
    });
});

it('makes the words read-only without the upload right and refuses a save from the server', function (): void {
    $user = drwUser(['media.view']);
    $file = drwFile('chi-xem.png', ['alt' => ['vi' => 'Chỉ xem']]);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid);
        $html = $page->html();
        expect($html)->toContain('readonly')
            ->not->toContain('data-unsaved-body="'.e(__('media.drw.ud_body')).'"') // no unsaved dialog: nothing to save
            ->not->toContain('wire:click="moveDetail"')
            ->not->toContain('wire:click="trashDetail"')
            ->toContain('wire:click="downloadDetail"');

        $page->set('detailAlt.vi', 'Đổi trộm')->call('saveDetail')->assertForbidden();
        Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)->call('moveDetail')->assertForbidden();
        Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)->call('trashDetail')->assertForbidden();
        expect($file->fresh()->alt)->toBe(['vi' => 'Chỉ xem'])
            ->and(Media::query()->whereKey($file->id)->exists())->toBeTrue();
    });
});

it('offers «own» members the Trash only for their own unused files, and says why otherwise', function (): void {
    $author = drwRole('author', 'Anh Bình');
    $other = drwRole('editor', 'Chị Mai');
    $theirs = drwFile('cua-chi-mai.png', ['created_by' => $other->id]);
    $usedMine = drwFile('cua-toi-dang-dung.png', ['created_by' => $author->id]);
    $mine = drwFile('cua-toi.png', ['created_by' => $author->id]);

    app(Tenancy::class)->enter(1, function () use ($author, $theirs, $usedMine, $mine): void {
        drwUse($usedMine);

        $page = Livewire::actingAs($author)->test(Library::class)->call('openDetail', $theirs->ulid);
        expect($page->html())->not->toContain('wire:click="trashDetail"')
            ->toContain(e(__('media.drw.why_theirs', ['name' => 'Chị Mai'])));
        $page->call('trashDetail')->assertSet('askTrashOpen', false);
        expect(Media::query()->whereKey($theirs->id)->exists())->toBeTrue(); // the server refuses it too

        $page->call('openDetail', $usedMine->ulid);
        expect($page->html())->not->toContain('wire:click="trashDetail"')
            ->toContain(e(__('media.drw.why_used', ['n' => 1])));
        $page->call('trashDetail');
        expect(Media::query()->whereKey($usedMine->id)->exists())->toBeTrue();

        $page->call('openDetail', $mine->ulid);
        expect($page->html())->toContain('wire:click="trashDetail"');
        $page->call('trashDetail')
            ->assertSet('pickerDetailUlid', null)
            ->assertSet('toast', __('media.lib.trashed', ['n' => 1]))
            ->assertSet('undoTrash', [$mine->ulid]);
        expect(Media::onlyTrashed()->whereKey($mine->id)->exists())->toBeTrue();
    });
});

it('asks before sending a used file to the Trash and sends only the open file, keeping the choice', function (): void {
    $user = drwUser(['media.view', 'media.upload', 'media.delete.any']);
    $open = drwFile('dang-dung.png');
    $picked = drwFile('da-chon.png');

    app(Tenancy::class)->enter(1, function () use ($user, $open, $picked): void {
        drwUse($open);
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('togglePick', $picked->ulid)
            ->call('openDetail', $open->ulid)
            ->call('trashDetail')
            ->assertSet('askTrashOpen', true)
            ->assertSet('single', true);
        expect($page->html())->toContain(e(__('media.lib.ask_trash_title', ['n' => 1])));

        $page->call('confirmTrash')
            ->assertSet('pickerDetailUlid', null)
            ->assertSet('single', false)
            ->assertSet('picked', [$picked->ulid]);
        expect(Media::onlyTrashed()->whereKey($open->id)->exists())->toBeTrue()
            ->and(Media::query()->whereKey($picked->id)->exists())->toBeTrue();
    });
});

it('moves only the open file from its menu', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $folder = app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->create(['name' => 'Bản vẽ', 'slug' => 'ban-ve', 'position' => 1]));
    $open = drwFile('ban-ve-1.png');
    $picked = drwFile('ban-ve-2.png');

    app(Tenancy::class)->enter(1, function () use ($user, $folder, $open, $picked): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('togglePick', $picked->ulid)
            ->call('openDetail', $open->ulid)
            ->call('moveDetail')
            ->assertSet('moveOpen', true)
            ->set('moveTo', 'ban-ve')
            ->call('confirmMove')
            ->assertSet('moveOpen', false)
            ->assertSet('single', false)
            ->assertSet('picked', [$picked->ulid])
            ->assertSet('pickerDetailUlid', $open->ulid); // the drawer stays on the moved file

        expect($open->fresh()->folder_id)->toBe($folder->id)
            ->and($picked->fresh()->folder_id)->toBeNull();
    });
});

it('downloads the open file under its name', function (): void {
    $user = drwUser(['media.view']);
    $file = drwFile('tai-ve.png');
    Storage::disk('r2')->put($file->storage_key, 'png-bytes');

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)
            ->call('downloadDetail')
            ->assertFileDownloaded('tai-ve.png');
    });
});

it('opens a trashed file for someone who sees the Trash, and restores it from the footer', function (): void {
    $user = drwUser(['media.view', 'media.upload', 'media.delete.any']);
    $file = drwFile('trong-thung-rac.png', ['alt' => ['vi' => 'Cũ']]);
    app(Tenancy::class)->enter(1, fn () => $file->delete());

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', '~trash')
            ->call('openDetail', $file->ulid)
            ->assertSet('pickerDetailUlid', $file->ulid);
        $html = $page->html();
        expect($html)->toContain(e(__('media.drw.title_trash')))
            ->toContain(e(__('media.drw.trash_note', ['n' => 30])))
            ->toContain(e(__('media.drw.was_used', ['n' => 0])))
            ->toContain('wire:click="restoreDetail"')
            ->toContain('readonly')
            ->not->toContain('wire:click="downloadDetail"')
            ->not->toContain('wire:click="purgeDetail"'); // no media.purge

        // a trashed file's words are read-only on the server too
        $page->set('detailAlt.vi', 'Mới')->call('saveDetail');
        expect(Media::onlyTrashed()->find($file->id)?->alt)->toBe(['vi' => 'Cũ']);

        $page->call('restoreDetail')
            ->assertSet('pickerDetailUlid', null)
            ->assertSet('toast', __('media.lib.restored', ['n' => 1]));
        expect(Media::query()->whereKey($file->id)->exists())->toBeTrue();
    });
});

it('never opens a trashed file for a member without a delete right, and refuses restore and purge', function (): void {
    $user = drwUser(['media.view', 'media.upload']);
    $file = drwFile('an-trong-thung-rac.png');
    app(Tenancy::class)->enter(1, fn () => $file->delete());

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        Livewire::actingAs($user)->test(Library::class)->call('openDetail', $file->ulid)->assertSet('pickerDetailUlid', null);
        Livewire::actingAs($user)->test(Library::class)->call('restoreDetail')->assertForbidden();
        Livewire::actingAs($user)->test(Library::class)->call('purgeDetail')->assertForbidden();
        expect(Media::onlyTrashed()->whereKey($file->id)->exists())->toBeTrue();
    });
});

it('lets «own» members restore only what they put in the Trash, and says who to ask', function (): void {
    $author = drwRole('author', 'Anh Bình');
    $editor = drwRole('editor', 'Chị Mai');
    $file = drwFile('cua-anh-binh.png', ['created_by' => $author->id]);
    app(Tenancy::class)->enter(1, function () use ($editor, $file): void {
        $this->actingAs($editor);
        $file->delete(); // the editor put it in the Trash
    });

    app(Tenancy::class)->enter(1, function () use ($author, $file): void {
        $page = Livewire::actingAs($author)->test(Library::class)->call('setFolder', '~trash')->call('openDetail', $file->ulid)
            ->assertSet('pickerDetailUlid', $file->ulid);
        expect($page->html())->toContain(e(__('media.drw.why_restore', ['name' => 'Chị Mai'])))
            ->not->toContain('wire:click="restoreDetail"');
        $page->call('restoreDetail')->assertSet('pickerDetailUlid', $file->ulid);
        expect(Media::onlyTrashed()->whereKey($file->id)->exists())->toBeTrue();
    });
});

it('deletes the open trashed file for good from its menu, leaving the chosen ones', function (): void {
    $user = drwRole('admin');
    $open = drwFile('xoa-han.png');
    $picked = drwFile('giu-lai.png');
    app(Tenancy::class)->enter(1, function () use ($open, $picked): void {
        $open->delete();
        $picked->delete();
    });

    app(Tenancy::class)->enter(1, function () use ($user, $open, $picked): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', '~trash')
            ->call('togglePick', $picked->ulid)
            ->call('openDetail', $open->ulid);
        expect($page->html())->toContain('wire:click="purgeDetail"');

        $page->call('purgeDetail')->assertSet('askPurgeOpen', true)->assertSet('single', true);
        expect($page->html())->toContain(e(__('media.lib.ask_purge_ok', ['n' => 1])));
        $page->call('confirmPurge')->assertSet('pickerDetailUlid', null)->assertSet('picked', [$picked->ulid]);

        expect(Media::withTrashed()->whereKey($open->id)->exists())->toBeFalse()
            ->and(Media::onlyTrashed()->whereKey($picked->id)->exists())->toBeTrue();
    });
});

it('gives each file opener the id the drawer returns the focus to', function (): void {
    $user = drwUser(['media.view']);
    $file = drwFile('tro-ve.png');

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class);
        expect($page->html())->toContain('id="mopen-'.$file->ulid.'"');
        expect($page->call('openDetail', $file->ulid)->html())->toContain('back: \'mopen-'.$file->ulid.'\'');
    });
});

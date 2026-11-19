<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Policies\MediaPolicy;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Deleting files by who uploaded them (design board 12): media.delete.own sends to the Trash only
 * the files this person uploaded and nobody uses, sees only those in the Trash and takes back out
 * only what they put in; media.delete.any acts on every file. Each rule is checked on the server,
 * whatever the page sends, and never reaches another website's files.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    app()->setLocale('vi');
});

/** A member of $site holding exactly what the shipped role template of that name grants. */
function delUser(string $role, int $site = 1): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$role]['permissions'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, $role, $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec));

    return $user->fresh() ?? $user;
}

/** @param  array<string, mixed>  $attrs */
function delFile(int $site, string $name, ?User $by, array $attrs = []): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => pathinfo($name, PATHINFO_FILENAME),
        'hash' => hash('sha256', $site.$name),
        'storage_key' => "s{$site}/library/{$name}",
        'original_filename' => $name,
        'mime' => 'image/png',
        'size' => 2048,
        'created_by' => $by?->id,
    ], $attrs)));
}

/** Put the file in a product's picture strip, so it is in use. Call inside the site. */
function delUse(Media $file): void
{
    $product = Product::query()->create(['status' => 'published']);
    ProductImage::query()->create(['product_id' => $product->id, 'media_id' => $file->id, 'position' => 0]);
}

/** @return list<string> the trashed files' names, sorted. Call inside the site. */
function delTrashed(): array
{
    return Media::onlyTrashed()->pluck('original_filename')->map(fn ($n): string => (string) $n)->sort()->values()->all();
}

it('gives the shipped roles the right half: editor any, author own, contributor and viewer none', function (): void {
    $t = PermissionRegistry::tenantRoleTemplates('corporate');
    expect($t['editor']['permissions'])->toContain('media.delete.any')->not->toContain('media.delete.own')
        ->and($t['author']['permissions'])->toContain('media.delete.own')->not->toContain('media.delete.any')
        ->and($t['contributor']['permissions'])->not->toContain('media.delete.own')->not->toContain('media.delete.any')
        ->and($t['viewer']['permissions'])->not->toContain('media.delete.own')->not->toContain('media.delete.any')
        ->and(PermissionRegistry::TENANT_PERMISSIONS)->not->toContain('media.delete')
        ->and(PermissionRegistry::IMPLIES['media.purge'])->toContain('media.delete.any');
});

it('lets «own» send only its own unused files to the Trash, and records who did', function (): void {
    $me = delUser('author');
    $other = delUser('editor');
    $mine = delFile(1, 'cua-toi.png', $me);
    $mineUsed = delFile(1, 'cua-toi-dang-dung.png', $me);
    $theirs = delFile(1, 'cua-nguoi-khac.png', $other);
    app(Tenancy::class)->enter(1, fn () => delUse($mineUsed));

    app(Tenancy::class)->enter(1, function () use ($me, $mine, $mineUsed, $theirs): void {
        $page = Livewire::actingAs($me)->test(Library::class)
            ->call('togglePick', $mine->ulid)->call('togglePick', $mineUsed->ulid)->call('togglePick', $theirs->ulid)
            ->assertSee('Chuyển 1 tệp của bạn vào Thùng rác')
            ->assertSee('2 tệp còn lại là của người khác hoặc đang dùng ở nơi khác — bạn chỉ xóa được tệp mình tải lên, chưa dùng ở đâu.')
            ->call('trashPicked')
            ->assertSet('askTrashOpen', true)
            ->assertSet('trashKept', 2)
            ->assertSee('Chuyển 1 tệp của bạn vào Thùng rác?')
            ->assertSee('2 tệp còn lại là của người khác hoặc đang dùng ở nơi khác — giữ nguyên.');
        $page->call('confirmTrash')->assertSet('toast', 'Đã chuyển 1 tệp vào Thùng rác.');

        expect(delTrashed())->toBe(['cua-toi.png'])
            ->and(Media::onlyTrashed()->value('deleted_by'))->toBe($me->id);
    });
});

it('says plainly when none of the chosen files is «own»-deletable, and sends nothing', function (): void {
    $me = delUser('author');
    $theirs = delFile(1, 'cua-nguoi-khac.png', delUser('editor'));

    app(Tenancy::class)->enter(1, function () use ($me, $theirs): void {
        Livewire::actingAs($me)->test(Library::class)
            ->call('togglePick', $theirs->ulid)
            ->assertDontSee('Chuyển vào Thùng rác (1)')
            ->assertSee('Các tệp đã chọn là của người khác hoặc đang dùng ở nơi khác')
            ->call('trashPicked')
            ->assertSet('toastTone', 'error')
            ->assertSet('askTrashOpen', false)
            // a forged confirm sends nothing either
            ->call('confirmTrash');
        expect(delTrashed())->toBe([]);
    });
});

it('counts the question from the files still there when a chosen one is gone meanwhile', function (): void {
    $me = delUser('author');
    $mine = delFile(1, 'cua-toi.png', $me);
    $gone = delFile(1, 'cua-toi-da-mat.png', $me);
    $theirs = delFile(1, 'cua-nguoi-khac.png', delUser('editor'));

    app(Tenancy::class)->enter(1, function () use ($me, $mine, $gone, $theirs): void {
        $page = Livewire::actingAs($me)->test(Library::class)
            ->call('togglePick', $mine->ulid)->call('togglePick', $gone->ulid)->call('togglePick', $theirs->ulid);
        Media::query()->whereKey($gone->id)->first()?->forceDelete(); // someone deleted it for good meanwhile
        $page->call('cancelTrash') // a re-render: the line under the buttons counts only what is still there
            ->assertSee('1 tệp còn lại là của người khác')
            ->call('trashPicked')
            ->assertSet('askTrashOpen', true)
            ->assertSet('trashKept', 1)
            ->assertSet('trashGo', 1)
            ->assertSee('Chuyển 1 tệp của bạn vào Thùng rác?');
    });

    // «own», when every chosen file of theirs is gone: the choice is cleared, no «someone else's» message
    $alone = delFile(1, 'cua-toi-mat-luon.png', $me);
    app(Tenancy::class)->enter(1, function () use ($me, $alone): void {
        $page = Livewire::actingAs($me)->test(Library::class)->call('togglePick', $alone->ulid);
        Media::query()->whereKey($alone->id)->first()?->forceDelete();
        $page->call('trashPicked')->assertSet('picked', [])->assertSet('toastTone', 'success')->assertSet('askTrashOpen', false);
    });

    // «any», when every chosen file is gone: the choice is cleared, with no «someone else's» message
    $editor = delUser('editor');
    $lost = delFile(1, 'mat-luon.png', $editor);
    app(Tenancy::class)->enter(1, function () use ($editor, $lost): void {
        $page = Livewire::actingAs($editor)->test(Library::class)->call('togglePick', $lost->ulid);
        Media::query()->whereKey($lost->id)->first()?->forceDelete();
        $page->call('trashPicked')->assertSet('picked', [])->assertSet('toastTone', 'success')->assertSet('askTrashOpen', false);
    });
});

it('lets «any» send anyone\'s file, in use or not, and take back out what someone else trashed', function (): void {
    $editor = delUser('editor');
    $author = delUser('author');
    $theirs = delFile(1, 'cua-tac-gia.png', $author);
    $used = delFile(1, 'dang-dung.png', $author);
    app(Tenancy::class)->enter(1, fn () => delUse($used));

    app(Tenancy::class)->enter(1, function () use ($editor, $theirs, $used): void {
        Livewire::actingAs($editor)->test(Library::class)
            ->call('togglePick', $theirs->ulid)->call('togglePick', $used->ulid)
            ->assertSee('Chuyển vào Thùng rác (2)')
            ->call('trashPicked')
            ->assertSet('askTrashOpen', true) // one is in use
            ->assertSet('trashKept', 0)
            ->call('confirmTrash');
        expect(delTrashed())->toBe(['cua-tac-gia.png', 'dang-dung.png']);
    });

    // the author trashed nothing of these: «own» cannot take them back; «any» can
    $auth = User::query()->whereKey($theirs->created_by)->firstOrFail();
    app(Tenancy::class)->enter(1, function () use ($auth, $editor, $theirs): void {
        Livewire::actingAs($auth)->test(Library::class)->call('setFolder', '~trash')
            ->call('togglePick', $theirs->ulid)
            ->assertSee('1 tệp do người khác bỏ vào Thùng rác — nhờ Quản trị khôi phục.')
            ->assertDontSee('Khôi phục (1)')
            ->call('restorePicked');
        expect(delTrashed())->toBe(['cua-tac-gia.png', 'dang-dung.png']);

        Livewire::actingAs($editor)->test(Library::class)->call('setFolder', '~trash')
            ->call('togglePick', $theirs->ulid)->call('restorePicked')
            ->assertSet('toast', 'Đã khôi phục 1 tệp về thư mục cũ.');
        expect(delTrashed())->toBe(['dang-dung.png'])
            ->and(Media::query()->whereKey($theirs->id)->value('deleted_by'))->toBeNull();
    });
});

it('shows «own» only its own files in the Trash, and lets it take back out what it trashed itself', function (): void {
    $me = delUser('author');
    $editor = delUser('editor');
    $mine = delFile(1, 'cua-toi.png', $me);
    $mineByAdmin = delFile(1, 'cua-toi-qtv-xoa.png', $me);
    $old = delFile(1, 'cua-toi-xoa-tu-truoc.png', $me);
    $theirs = delFile(1, 'cua-nguoi-khac.png', $editor);
    app(Tenancy::class)->enter(1, function () use ($me, $editor, $mine, $mineByAdmin, $old, $theirs): void {
        Auth::login($me);
        $mine->delete();
        Auth::login($editor);
        $mineByAdmin->delete();
        $theirs->delete();
        Auth::logout();
        $old->delete(); // before anyone was recorded: no one
    });

    app(Tenancy::class)->enter(1, function () use ($me, $mine, $old, $mineByAdmin): void {
        $page = Livewire::actingAs($me)->test(Library::class)->call('setFolder', '~trash')
            ->assertSee('cua-toi.png')->assertSee('cua-toi-xoa-tu-truoc.png')->assertSee('cua-toi-qtv-xoa.png')
            ->assertDontSee('cua-nguoi-khac.png')
            ->assertViewHas('counts', fn (array $c): bool => $c['trash'] === 3);
        $page->call('togglePick', $mine->ulid)->call('togglePick', $old->ulid)->call('togglePick', $mineByAdmin->ulid)
            ->assertSee('Khôi phục (2)')
            ->call('restorePicked')
            ->assertSet('toast', 'Đã khôi phục 2 tệp về thư mục cũ.');
        expect(delTrashed())->toBe(['cua-nguoi-khac.png', 'cua-toi-qtv-xoa.png']);
    });
});

it('puts back with Hoàn tác only what that Trash move sent', function (): void {
    $me = delUser('author');
    $mine = delFile(1, 'cua-toi.png', $me);

    app(Tenancy::class)->enter(1, function () use ($me, $mine): void {
        Livewire::actingAs($me)->test(Library::class)
            ->call('togglePick', $mine->ulid)->call('trashPicked')
            ->assertSet('undoTrash', [$mine->ulid])
            ->call('undo')
            ->assertSet('toast', 'Đã khôi phục 1 tệp về thư mục cũ.');
        expect(delTrashed())->toBe([]);
    });
});

it('refuses every Trash action to a member with neither half', function (string $method): void {
    $user = delUser('contributor');
    $file = delFile(1, 'tep.png', $user);

    app(Tenancy::class)->enter(1, function () use ($user, $file, $method): void {
        Livewire::actingAs($user)->test(Library::class)->set('picked', [$file->ulid])->call($method)->assertForbidden();
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', '~trash')->assertSet('status', 'live');
    });
    expect(app(Tenancy::class)->enter(1, fn () => delTrashed()))->toBe([]);
})->with(['trashPicked', 'confirmTrash', 'restorePicked', 'undo']);

it('never touches another website\'s file, whatever the delete right', function (string $role): void {
    $theirsOwner = delUser($role); // site 1
    $me = delUser($role, 2);
    $file = delFile(1, 'cua-site-a.png', $me); // even «uploaded by me» on the other site
    $gone = delFile(1, 'site-a-thung-rac.png', $me);
    app(Tenancy::class)->enter(1, fn () => $gone->delete());

    app(Tenancy::class)->enter(2, function () use ($me, $file, $gone): void {
        Livewire::actingAs($me)->test(Library::class)
            ->set('picked', [$file->ulid])->call('confirmTrash')
            ->call('setFolder', '~trash')->assertDontSee('site-a-thung-rac.png')
            ->set('picked', [$gone->ulid])->call('restorePicked');
    });
    app(Tenancy::class)->enter(1, function () use ($file): void {
        expect(Media::query()->whereKey($file->id)->exists())->toBeTrue()
            ->and(delTrashed())->toBe(['site-a-thung-rac.png']);
    });
    expect($theirsOwner->id)->not->toBe($me->id);
})->with(['author', 'editor']);

it('applies the same rules in the editor\'s picker', function (): void {
    $me = delUser('author');
    app(Tenancy::class)->enter(1, fn () => grantRole($me, 'author', array_merge(PermissionRegistry::tenantRoleTemplates('corporate')['author']['permissions'], ['catalog.view', 'catalog.manage'])));
    $me = $me->fresh() ?? $me;
    $editor = delUser('editor');
    $mine = delFile(1, 'cua-toi.png', $me);
    $theirs = delFile(1, 'cua-nguoi-khac.png', $editor);
    $mineByAdmin = delFile(1, 'cua-toi-qtv-xoa.png', $me);
    $theirsGone = delFile(1, 'cua-nguoi-khac-da-xoa.png', $editor);
    app(Tenancy::class)->enter(1, function () use ($editor, $mineByAdmin, $theirsGone): void {
        Auth::login($editor);
        $mineByAdmin->delete();
        $theirsGone->delete();
        Auth::logout();
    });

    app(Tenancy::class)->enter(1, function () use ($me, $mine, $theirs, $mineByAdmin, $theirsGone): void {
        $picker = Livewire::actingAs($me)->test(Editor::class)->call('openImagePicker', 'gallery')
            ->set('pickerSelected', [$mine->id, $theirs->id])->call('pickerBulkDelete');
        expect(delTrashed())->toBe(['cua-nguoi-khac-da-xoa.png', 'cua-toi-qtv-xoa.png', 'cua-toi.png']);

        $picker->call('openPickerTrash');
        $state = $picker->instance()->mediaPickerState();
        expect($state['page']->pluck('original_filename')->sort()->values()->all())->toBe(['cua-toi-qtv-xoa.png', 'cua-toi.png'])
            ->and($state['trashTotal'])->toBe(2);

        // selecting another person's trashed file is refused; restoring takes back only what I trashed
        $picker->call('togglePickerItem', $theirsGone->id)->assertSet('pickerSelected', []);
        $picker->set('pickerSelected', [$mine->id, $mineByAdmin->id, $theirsGone->id])->call('restorePickerSelection');
        expect(delTrashed())->toBe(['cua-nguoi-khac-da-xoa.png', 'cua-toi-qtv-xoa.png']);
    });
});

it('records who trashed a file, clears it on restore, and records no one without a signed-in user', function (): void {
    $me = delUser('author');
    $file = delFile(1, 'tep.png', $me);

    app(Tenancy::class)->enter(1, function () use ($me, $file): void {
        Auth::login($me);
        $file->delete();
        expect($file->fresh()?->deleted_by ?? Media::onlyTrashed()->whereKey($file->id)->value('deleted_by'))->toBe($me->id);
        $file->restore();
        expect(Media::query()->whereKey($file->id)->value('deleted_by'))->toBeNull();
        Auth::logout();
        $file->delete();
        expect(Media::onlyTrashed()->whereKey($file->id)->value('deleted_by'))->toBeNull();
    });
});

it('lets the policy send and restore a file with «any», or with «own» only a file of one\'s own', function (): void {
    $me = delUser('author');
    $editor = delUser('editor');
    $viewer = delUser('viewer');
    $mine = delFile(1, 'cua-toi.png', $me);
    $theirs = delFile(1, 'cua-nguoi-khac.png', $editor);

    app(Tenancy::class)->enter(1, function () use ($me, $editor, $viewer, $mine, $theirs): void {
        $p = new MediaPolicy;
        expect($p->delete($me, $mine))->toBeTrue()
            ->and($p->delete($me, $theirs))->toBeFalse()
            ->and($p->delete($me))->toBeTrue()
            ->and($p->delete($editor, $mine))->toBeTrue()
            ->and($p->delete($viewer, $mine))->toBeFalse()
            ->and($p->delete($viewer))->toBeFalse();

        // out of the Trash: «own» only what they uploaded and trashed themselves (or deleter unknown)
        $mine->forceFill(['deleted_by' => $me->id]);
        expect($p->restore($me, $mine))->toBeTrue();
        $mine->forceFill(['deleted_by' => null]);
        expect($p->restore($me, $mine))->toBeTrue();
        $mine->forceFill(['deleted_by' => $editor->id]);
        expect($p->restore($me, $mine))->toBeFalse()
            ->and($p->restore($me, $theirs))->toBeFalse()
            ->and($p->restore($editor, $mine))->toBeTrue()
            ->and($p->restore($viewer, $mine))->toBeFalse();
    });
});

it('splits each holder of the old right: system owner/admin/editor and purgers get any, others own, direct grants own', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();
    // the catalog before the split: media.delete, without the halves
    Permission::query()->whereIn('name', ['media.delete.own', 'media.delete.any'])->delete();
    $old = Permission::findOrCreate('media.delete', PermissionRegistry::GUARD);
    $registrar->forgetCachedPermissions();

    [$editor, $custom, $purger, $direct] = app(Tenancy::class)->enter(1, function () use ($old): array {
        $editor = Role::findOrCreate('editor', PermissionRegistry::GUARD);
        $editor->forceFill(['is_system' => true])->save();
        $editor->givePermissionTo($old);
        $custom = Role::findOrCreate('ho-tro-anh', PermissionRegistry::GUARD);
        $custom->givePermissionTo($old);
        $purger = Role::findOrCreate('don-thung-rac', PermissionRegistry::GUARD);
        $purger->givePermissionTo([$old, 'media.purge']);
        $direct = User::factory()->create();
        $direct->givePermissionTo($old);

        return [$editor, $custom, $purger, $direct];
    });

    $migration = require base_path('database/migrations/2080_01_01_000200_split_media_delete_permission.php');
    $migration->up();
    $migration->up(); // re-runnable

    $held = fn (Role $r): array => DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
        ->where('role_id', $r->id)->where('permissions.name', 'like', 'media.delete%')->orderBy('permissions.name')->pluck('permissions.name')->all();
    $direct_ = fn (): array => DB::table('model_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
        ->where('model_id', $direct->id)->where('permissions.name', 'like', 'media.delete%')->orderBy('permissions.name')->pluck('permissions.name')->all();
    // media.delete stays (expand-only: code rolled back still finds it)
    expect($held($editor))->toBe(['media.delete', 'media.delete.any'])
        ->and($held($custom))->toBe(['media.delete', 'media.delete.own'])
        ->and($held($purger))->toBe(['media.delete', 'media.delete.any'])
        ->and($direct_())->toBe(['media.delete', 'media.delete.own']);

    // roles made after the split hold only a half: down() gives the old right («every file» to
    // the code before the split) back to the «any» one only, never widening an own-only role
    [$late, $lateAny] = app(Tenancy::class)->enter(1, function (): array {
        $late = Role::findOrCreate('moi', PermissionRegistry::GUARD);
        $late->givePermissionTo('media.delete.own');
        $lateAny = Role::findOrCreate('moi-moi-tep', PermissionRegistry::GUARD);
        $lateAny->givePermissionTo('media.delete.any');

        return [$late, $lateAny];
    });
    $migration->down();
    expect($held($editor))->toBe(['media.delete'])
        ->and($held($custom))->toBe(['media.delete'])
        ->and($held($late))->toBe([])
        ->and($held($lateAny))->toBe(['media.delete'])
        ->and($direct_())->toBe(['media.delete'])
        ->and(Permission::query()->where('name', 'like', 'media.delete.%')->count())->toBe(0);
});

it('keeps a custom role narrowed by the split at its own files after rbac:sync, as every deploy runs it', function (): void {
    $registrar = app(PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();
    // the catalog before the split: media.delete, without the halves
    Permission::query()->whereIn('name', ['media.delete.own', 'media.delete.any'])->delete();
    $old = Permission::findOrCreate('media.delete', PermissionRegistry::GUARD);
    $registrar->forgetCachedPermissions();

    [$custom, $member] = app(Tenancy::class)->enter(1, function () use ($old): array {
        $custom = Role::findOrCreate('ho-tro-anh', PermissionRegistry::GUARD);
        $custom->givePermissionTo([$old, 'media.view', 'media.upload']);
        $member = User::factory()->create();
        $member->assignRole($custom);

        return [$custom, $member];
    });

    $migration = require base_path('database/migrations/2080_01_01_000200_split_media_delete_permission.php');
    $migration->up();
    $this->artisan('rbac:sync')->assertSuccessful();
    $this->artisan('rbac:sync')->assertSuccessful(); // the next deploy too

    $held = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'permission_id')
        ->where('role_id', $custom->id)->where('permissions.name', 'like', 'media.%')->orderBy('permissions.name')->pluck('permissions.name')->all();
    expect($held)->toBe(['media.delete', 'media.delete.own', 'media.upload', 'media.view']);

    $registrar->forgetCachedPermissions();
    app(Tenancy::class)->enter(1, function () use ($member): void {
        $fresh = $member->fresh();
        expect($fresh?->can('media.delete.own'))->toBeTrue()
            ->and($fresh?->can('media.delete.any'))->toBeFalse()
            ->and($fresh?->can('media.purge'))->toBeFalse();
    });
});

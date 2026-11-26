<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Jobs\DeleteLargeMediaFolder;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * The folder actions of the media library: rename (the slug stays), move up / down and delete (the
 * files go back to no folder, with Hoàn tác). Each is the website admin's right (media.folders),
 * and a folder of another site never resolves.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    app()->setLocale('vi');
});

function fdFile(int $site, string $name, ?MediaFolder $folder = null): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create([
        'collection' => 'library',
        'slug' => pathinfo($name, PATHINFO_FILENAME),
        'hash' => hash('sha256', $site.$name),
        'storage_key' => "s{$site}/library/{$name}",
        'original_filename' => $name,
        'mime' => 'image/png',
        'size' => 2048,
        'folder_id' => $folder?->id,
    ]));
}

function fdFolder(int $site, string $name, string $slug, int $position): MediaFolder
{
    return app(Tenancy::class)->enter($site, fn () => MediaFolder::query()->create(['name' => $name, 'slug' => $slug, 'position' => $position]));
}

/** A member of $site holding exactly what the shipped role template of that name grants. */
function fdUser(string $role, int $site = 1): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$role]['permissions'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, $role, $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec));

    return $user->fresh();
}

/** @return list<string> the site's folder slugs in the order the folder column shows them (outside any site context) */
function fdOrder(int $site): array
{
    return app(Tenancy::class)->enter($site, fn () => MediaFolder::query()->orderBy('position')->orderBy('name')->pluck('slug')->map(fn ($s): string => (string) $s)->all());
}

it('renames a folder and keeps its slug; an empty or taken name is refused', function (): void {
    $user = fdUser('admin');
    $folder = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    fdFolder(1, 'Sản phẩm', 'san-pham', 2);

    app(Tenancy::class)->enter(1, function () use ($user, $folder): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'anh-xuong')
            ->call('openRename', 'anh-xuong')
            ->assertSet('renaming', 'anh-xuong')
            ->assertSet('renameName', 'Ảnh xưởng')
            ->set('renameName', '  ')
            ->call('saveRename')
            ->assertHasErrors('renameName')
            ->set('renameName', 'SẢN PHẨM')
            ->call('saveRename')
            ->assertHasErrors('renameName')
            ->set('renameName', 'Ảnh nhà xưởng')
            ->call('saveRename')
            ->assertHasNoErrors()
            ->assertSet('renaming', '')
            ->assertSet('toast', 'Đã đổi tên "Ảnh xưởng" thành "Ảnh nhà xưởng".');

        expect(MediaFolder::query()->find($folder->id))
            ->name->toBe('Ảnh nhà xưởng')
            ->slug->toBe('anh-xuong');

        // the folder whose name changes is the server's, not the page's to say
        expect(fn () => $page->set('renaming', 'san-pham'))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('moves a folder up and down, and the ends say why they cannot move further', function (): void {
    $user = fdUser('admin');
    fdFolder(1, 'A', 'a', 0);
    fdFolder(1, 'B', 'b', 0);
    fdFolder(1, 'C', 'c', 0);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        // read in this site's context (a nested Tenancy::enter would end it on return)
        $order = fn (): array => MediaFolder::query()->orderBy('position')->orderBy('name')->pluck('slug')->map(fn ($s): string => (string) $s)->all();
        $page = Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'a');
        $page->assertSee('Đang ở đầu danh sách.')->assertDontSee('Đang ở cuối danh sách.');

        $page->call('moveFolder', 'c', -1)->assertSet('toast', 'Đã chuyển thư mục lên.');
        expect($order())->toBe(['a', 'c', 'b']);

        $page->call('moveFolder', 'a', 1)->assertSet('toast', 'Đã chuyển thư mục xuống.');
        expect($order())->toBe(['c', 'a', 'b']);

        $page->call('moveFolder', 'c', -1)->call('moveFolder', 'b', 1);
        expect($order())->toBe(['c', 'a', 'b']);

        $page->call('setFolder', 'b')->assertSee('Đang ở cuối danh sách.');
    });
});

it('gives a folder rename its own toast: the Hoàn tác of a move to the Trash before it does not carry over', function (): void {
    $user = fdUser('admin');
    fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $file = fdFile(1, 'a.png');

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('togglePick', $file->ulid)
            ->call('confirmTrash')
            ->assertSet('undoTrash', [$file->ulid])
            ->call('openRename', 'anh-xuong')
            ->set('renameName', 'Ảnh nhà xưởng')
            ->call('saveRename')
            ->assertSet('toast', 'Đã đổi tên "Ảnh xưởng" thành "Ảnh nhà xưởng".')
            ->assertSet('undoTrash', []);
    });
});

it('keeps the folder actions to the website admin: every other role sees no ⋮ and is refused', function (string $role): void {
    $user = fdUser($role);
    $folder = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    fdFolder(1, 'Sản phẩm', 'san-pham', 2);
    fdFile(1, 'a.png', $folder);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'anh-xuong')
            ->assertDontSee('wire:click="openRename', false)
            ->assertDontSee('wire:click="moveFolder', false)
            ->assertDontSee('wire:click="askDeleteFolder', false);

        foreach ([['openRename', ['anh-xuong']], ['saveRename', []], ['moveFolder', ['anh-xuong', 1]], ['askDeleteFolder', ['anh-xuong']],
            ['confirmDeleteFolder', []], ['undoFolderDelete', []]] as [$method, $args]) {
            Livewire::actingAs($user)->test(Library::class)->call($method, ...$args)->assertForbidden();
        }
    });
    expect(fdOrder(1))->toBe(['anh-xuong', 'san-pham'])
        ->and(app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->where('slug', 'anh-xuong')->value('name')))->toBe('Ảnh xưởng');
})->with(['author', 'editor', 'contributor', 'viewer']);

it('shows the website admin and the owner the ⋮ of the open folder only', function (string $role): void {
    $user = fdUser($role);
    fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    fdFolder(1, 'Sản phẩm', 'san-pham', 2);
    fdFile(1, 'a.png');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->assertDontSee("openRename('anh-xuong')", false)
            ->call('setFolder', 'anh-xuong')
            ->assertSee("openRename('anh-xuong')", false)
            ->assertSee("moveFolder('anh-xuong', 1)", false)
            ->assertSee("askDeleteFolder('anh-xuong')", false)
            ->assertDontSee("openRename('san-pham')", false);
    });
})->with(['admin', 'owner']);

it('never renames or moves a folder of another site', function (): void {
    $user = fdUser('admin', 2);
    $theirs = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $other = fdFolder(1, 'Site A khác', 'site-a-khac', 2);
    $mine = fdFolder(2, 'Của B', 'cua-b', 1);
    $same = fdFolder(2, 'Cùng tên', 'site-a-khac', 2);

    app(Tenancy::class)->enter(2, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('openRename', 'anh-xuong')
            ->assertSet('renaming', '')
            ->call('saveRename')
            ->call('moveFolder', 'anh-xuong', 1);

        // a slug both sites use resolves to site B's own folder only
        Livewire::actingAs($user)->test(Library::class)
            ->call('openRename', 'site-a-khac')
            ->set('renameName', 'Đổi ở B')
            ->call('saveRename')
            ->assertHasNoErrors()
            ->call('moveFolder', 'site-a-khac', -1);

        // the folder being renamed is server-owned: the page cannot point it at another folder
        $page = Livewire::actingAs($user)->test(Library::class);
        expect(fn () => $page->set('renaming', 'anh-xuong'))->toThrow(CannotUpdateLockedPropertyException::class);
    });

    app(Tenancy::class)->enter(1, function () use ($theirs, $other): void {
        expect(MediaFolder::query()->find($theirs->id))->name->toBe('Ảnh xưởng')->position->toBe(1)
            ->and(MediaFolder::query()->find($other->id))->name->toBe('Site A khác')->position->toBe(2);
    });
    app(Tenancy::class)->enter(2, function () use ($mine, $same): void {
        expect(MediaFolder::query()->find($same->id)?->name)->toBe('Đổi ở B')
            ->and(MediaFolder::query()->orderBy('position')->pluck('slug')->map(fn ($s): string => (string) $s)->all())->toBe(['site-a-khac', 'cua-b'])
            ->and(MediaFolder::query()->find($mine->id)?->name)->toBe('Của B');
    });
});

it('deletes an empty folder at once, with Hoàn tác', function (string $role): void {
    $user = fdUser($role);
    fdFolder(1, 'Trống', 'trong', 3);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'trong')
            ->call('askDeleteFolder', 'trong')
            ->assertSet('askFolderOpen', false)
            ->assertSet('folder', '')
            ->assertSet('toast', 'Đã xóa thư mục "Trống".')
            ->assertSee('undoFolderDelete', false);
        expect(MediaFolder::query()->count())->toBe(0);

        $page->call('undoFolderDelete')->assertSet('folder', 'trong')->assertSet('undoFolder', '')
            ->assertSet('toast', 'Đã khôi phục thư mục "Trống".');
        // its old place was the third, but it is the only folder now: it comes back first
        expect(MediaFolder::query()->where('slug', 'trong')->value('position'))->toBe(1);
    });
})->with(['admin', 'owner']);

it('asks before deleting a folder with files; the files go to Chưa phân loại and Hoàn tác puts them back', function (): void {
    $user = fdUser('admin');
    $folder = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $other = fdFolder(1, 'Khác', 'khac', 2);
    $a = fdFile(1, 'a.png', $folder);
    $b = fdFile(1, 'b.png', $folder);
    $gone = fdFile(1, 'gone.png', $folder);
    $elsewhere = fdFile(1, 'elsewhere.png', $other);
    app(Tenancy::class)->enter(1, fn () => $gone->delete());

    app(Tenancy::class)->enter(1, function () use ($user, $a, $b, $gone, $elsewhere, $other): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'anh-xuong')
            ->call('askDeleteFolder', 'anh-xuong')
            ->assertSet('askFolderOpen', true)
            ->assertSee('Xóa thư mục "Ảnh xưởng"?')
            ->assertSee('Thư mục có 2 tệp. Tệp không bị xóa — chúng về Tất cả ảnh, nằm ở mục Chưa phân loại.')
            ->assertSee('Nơi đang dùng ảnh vẫn giữ nguyên.')
            ->assertSee('Xóa thư mục và đưa 2 tệp về Tất cả ảnh');

        $page->call('cancelDeleteFolder')->assertSet('askFolderOpen', false)->assertSet('folderToDelete', '');
        expect(MediaFolder::query()->where('slug', 'anh-xuong')->exists())->toBeTrue();

        $page->call('askDeleteFolder', 'anh-xuong')
            ->call('confirmDeleteFolder')
            ->assertSet('askFolderOpen', false)
            ->assertSet('folder', '')
            ->assertSet('toast', 'Đã xóa thư mục "Ảnh xưởng", 2 tệp về Tất cả ảnh (Chưa phân loại).');

        expect(MediaFolder::query()->where('slug', 'anh-xuong')->exists())->toBeFalse()
            ->and(Media::query()->whereKey([$a->id, $b->id])->whereNull('folder_id')->count())->toBe(2)
            ->and(Media::onlyTrashed()->find($gone->id)?->folder_id)->toBeNull();

        // one file is moved somewhere else before Hoàn tác: it stays where it was put
        Media::query()->whereKey($b->id)->update(['folder_id' => $other->id]);

        $page->call('undoFolderDelete')
            ->assertSet('folder', 'anh-xuong')
            ->assertSet('toast', 'Đã khôi phục thư mục "Ảnh xưởng".');
        $back = MediaFolder::query()->where('slug', 'anh-xuong')->firstOrFail();
        expect(Media::query()->find($a->id)?->folder_id)->toBe($back->id)
            ->and(Media::query()->find($b->id)?->folder_id)->toBe($other->id)
            ->and(Media::onlyTrashed()->find($gone->id)?->folder_id)->toBe($back->id)
            ->and(Media::query()->find($elsewhere->id)?->folder_id)->toBe($other->id);
    });
});

it('gives a folder delete its own Hoàn tác: a later toast takes it away', function (): void {
    $user = fdUser('admin');
    fdFolder(1, 'Trống', 'trong', 1);
    fdFolder(1, 'Khác', 'khac', 2);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'trong');
        expect($page->get('undoFolder'))->not->toBe('');
        $page->call('openRename', 'khac')->set('renameName', 'Khác nữa')->call('saveRename')
            ->assertSet('undoFolder', '')->assertDontSee('undoFolderDelete', false);
        $page->call('undoFolderDelete')->assertSet('toastTone', 'error');
        expect(MediaFolder::query()->where('slug', 'trong')->exists())->toBeFalse();
    });
});

it('brings a folder back at its old place and under a free name when the name was taken meanwhile', function (): void {
    $user = fdUser('admin');
    fdFolder(1, 'Một', 'mot', 1);
    $folder = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 2);
    fdFolder(1, 'Ba', 'ba', 3);
    $file = fdFile(1, 'a.png', $folder);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('askDeleteFolder', 'anh-xuong')->call('confirmDeleteFolder');
        expect($page->get('undoFolder'))->not->toBe('');

        // meanwhile someone makes a folder of the same name; it lands last
        Livewire::actingAs($user)->test(Library::class)->call('openNewFolder')->set('newFolderName', 'ảnh xưởng')->call('createFolder')
            ->assertSet('folder', 'anh-xuong');

        $page->call('undoFolderDelete')->assertSet('toast', 'Đã khôi phục thư mục "Ảnh xưởng (2)".');
        $back = MediaFolder::query()->where('name', 'Ảnh xưởng (2)')->firstOrFail();
        expect(Media::query()->find($file->id)?->folder_id)->toBe($back->id)
            ->and((string) $back->slug)->toBe('anh-xuong-2');
    });

    // back at its old place, every place used once
    expect(fdOrder(1))->toBe(['mot', 'anh-xuong-2', 'ba', 'anh-xuong'])
        ->and(app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->orderBy('position')->pluck('position')->all()))->toBe([1, 2, 3, 4]);
});

it('deletes a folder holding only files in the Trash at once; Hoàn tác puts them back', function (): void {
    $user = fdUser('admin');
    $folder = fdFolder(1, 'Cũ', 'cu', 1);
    $file = fdFile(1, 'old.png', $folder);
    app(Tenancy::class)->enter(1, fn () => $file->delete());

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'cu')
            ->assertSet('askFolderOpen', false)->assertSet('toast', 'Đã xóa thư mục "Cũ".');
        expect(Media::onlyTrashed()->find($file->id)?->folder_id)->toBeNull();
        $page->call('undoFolderDelete');
        expect(Media::onlyTrashed()->find($file->id)?->folder_id)->toBe(MediaFolder::query()->where('slug', 'cu')->value('id'));
    });
});

it('says before and after that a folder with too many files has no Hoàn tác', function (): void {
    config(['media.folder_undo_max_files' => 2]);
    $user = fdUser('admin');
    $folder = fdFolder(1, 'Lớn', 'lon', 1);
    $small = fdFolder(1, 'Nhỏ', 'nho', 2);
    foreach (['a', 'b'] as $n) {
        fdFile(1, "{$n}.png", $folder);
        fdFile(1, "s{$n}.png", $small);
    }
    $trashed = fdFile(1, 'c.png', $folder); // the Trash counts too: 3 files > 2
    app(Tenancy::class)->enter(1, fn () => $trashed->delete());

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('askDeleteFolder', 'lon')
            ->assertSet('folderNoUndo', true)
            ->assertSee('Thư mục có quá nhiều tệp nên sau khi xóa sẽ không hoàn tác được.')
            ->call('confirmDeleteFolder')
            ->assertSet('undoFolder', '')
            ->assertSet('toast', 'Đã xóa thư mục "Lớn", 2 tệp về Tất cả ảnh (Chưa phân loại). Thư mục quá nhiều tệp nên không có Hoàn tác.')
            ->assertDontSee('undoFolderDelete', false);

        // exactly at the limit: Hoàn tác is kept
        Livewire::actingAs($user)->test(Library::class)
            ->call('askDeleteFolder', 'nho')
            ->assertSet('folderNoUndo', false)
            ->assertDontSee('Thư mục có quá nhiều tệp nên sau khi xóa sẽ không hoàn tác được.')
            ->call('confirmDeleteFolder')
            ->assertNotSet('undoFolder', '');
    });
});

it('keeps a folder Hoàn tác under this site and this member only', function (): void {
    $user = fdUser('admin');
    $other = fdUser('admin');
    fdFolder(1, 'Trống', 'trong', 1);
    fdFolder(1, 'Hai', 'hai', 2);

    app(Tenancy::class)->enter(1, function () use ($user, $other): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'trong');
        expect(Cache::has('media:folder-undo:1:'.$user->id.':'.$page->get('undoFolder')))->toBeTrue();

        // another member of the site pressing the same Hoàn tác finds nothing kept for them
        test()->actingAs($other);
        $page->call('undoFolderDelete')->assertSet('toast', 'Đã quá lâu nên không hoàn tác được nữa. Tạo lại thư mục rồi chuyển tệp vào nếu cần.');
        expect(MediaFolder::query()->where('slug', 'trong')->exists())->toBeFalse();

        // and the member's own kept delete stays theirs
        test()->actingAs($user);
        $mine = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'hai');
        test()->actingAs($other);
        $mine->call('undoFolderDelete')->assertSet('toastTone', 'error');
        test()->actingAs($user);
        expect(MediaFolder::query()->where('slug', 'hai')->exists())->toBeFalse();
    });
});

it('keeps the Hoàn tác of a folder delete when bringing it back fails, for another try', function (): void {
    $user = fdUser('admin');
    $folder = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $file = fdFile(1, 'a.png', $folder);

    app(Tenancy::class)->enter(1, function () use ($user, $file): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'anh-xuong')->call('confirmDeleteFolder');
        $key = 'media:folder-undo:1:'.$user->id.':'.$page->get('undoFolder');

        $fail = true;
        MediaFolder::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('the database refused');
            }
        });
        expect(fn () => $page->call('undoFolderDelete'))->toThrow(RuntimeException::class);
        expect(Cache::has($key))->toBeTrue()
            ->and(MediaFolder::query()->count())->toBe(0)
            ->and(Media::query()->find($file->id)?->folder_id)->toBeNull();
    });
});

it('asks before deleting a folder whose Trash alone is too big for Hoàn tác', function (): void {
    config(['media.folder_undo_max_files' => 1]);
    $user = fdUser('admin');
    $folder = fdFolder(1, 'Cũ', 'cu', 1);
    foreach (['a', 'b'] as $n) {
        $f = fdFile(1, "{$n}.png", $folder);
        app(Tenancy::class)->enter(1, fn () => $f->delete());
    }

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'cu')
            ->assertSet('askFolderOpen', true)->assertSet('folderNoUndo', true)
            ->assertSee('Thư mục có quá nhiều tệp nên sau khi xóa sẽ không hoàn tác được.');
        expect(MediaFolder::query()->where('slug', 'cu')->exists())->toBeTrue();
    });
});

it('says plainly when Hoàn tác for a folder comes too late', function (): void {
    $user = fdUser('admin');
    fdFolder(1, 'Trống', 'trong', 1);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'trong');
        Cache::flush();
        $page->call('undoFolderDelete')->assertSet('toastTone', 'error')
            ->assertSet('toast', 'Đã quá lâu nên không hoàn tác được nữa. Tạo lại thư mục rồi chuyển tệp vào nếu cần.');
        expect(MediaFolder::query()->count())->toBe(0);
    });
});

it('never deletes a folder of another site, nor brings one back there', function (): void {
    $theirsUser = fdUser('admin', 1);
    $user = fdUser('admin', 2);
    $theirs = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    fdFolder(1, 'Site A khác', 'site-a-khac', 2);
    $theirFile = fdFile(1, 'a.png', $theirs);
    $mine = fdFolder(2, 'Của B', 'cua-b', 1);
    $myFile = fdFile(2, 'b.png', $mine);

    // site A deletes a folder; its Hoàn tác key is of no use on site B
    $tokenA = app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($theirsUser)->test(Library::class)
        ->call('askDeleteFolder', 'site-a-khac')->get('undoFolder'));
    expect($tokenA)->not->toBe('');

    app(Tenancy::class)->enter(2, function () use ($user, $tokenA): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('askDeleteFolder', 'anh-xuong')
            ->assertSet('askFolderOpen', false)
            ->assertSet('folderToDelete', '')
            ->call('confirmDeleteFolder');

        // the folder asked about and the Hoàn tác key are server-owned: the page cannot point them elsewhere
        $page = Livewire::actingAs($user)->test(Library::class);
        expect(fn () => $page->set('folderToDelete', 'anh-xuong'))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $page->set('undoFolder', $tokenA))->toThrow(CannotUpdateLockedPropertyException::class);
        $page->call('undoFolderDelete')->assertSet('toastTone', 'error');
    });

    // site B deleting its own folder never touches site A's files
    app(Tenancy::class)->enter(2, fn () => Livewire::actingAs($user)->test(Library::class)
        ->call('askDeleteFolder', 'cua-b')->call('confirmDeleteFolder'));

    app(Tenancy::class)->enter(1, function () use ($theirs, $theirFile): void {
        expect(MediaFolder::query()->find($theirs->id)?->name)->toBe('Ảnh xưởng')
            ->and(Media::query()->find($theirFile->id)?->folder_id)->toBe($theirs->id)
            ->and(MediaFolder::query()->where('slug', 'site-a-khac')->exists())->toBeFalse();
    });
    app(Tenancy::class)->enter(2, function () use ($mine, $myFile): void {
        expect(MediaFolder::query()->find($mine->id))->toBeNull()
            ->and(Media::query()->find($myFile->id)?->folder_id)->toBeNull();
    });
});

/** @return array{folder: MediaFolder, files: list<Media>} a folder of $n files, the last one in the Trash */
function fdBigFolder(int $site, string $slug, int $n): array
{
    $folder = fdFolder($site, 'Lớn '.$slug, $slug, 1);
    $files = [];
    for ($i = 1; $i <= $n; $i++) {
        $files[] = fdFile($site, "{$slug}-{$i}.png", $folder);
    }
    app(Tenancy::class)->enter($site, fn () => $files[$n - 1]->delete());

    return ['folder' => $folder, 'files' => $files];
}

/** @return array{filed: int, folder: bool} the files of $site still in the folder, and whether the folder is still there */
function fdLeft(int $site, int $folderId): array
{
    return app(Tenancy::class)->enter($site, fn (): array => [
        'filed' => Media::withTrashed()->where('folder_id', $folderId)->count(),
        'folder' => MediaFolder::query()->whereKey($folderId)->exists(),
    ]);
}

it('leaves a folder with more files than one request moves to a queued job, and says so before and after', function (): void {
    config(['media.folder_delete_inline_max_files' => 2]);
    Queue::fake();
    $user = fdUser('admin');
    ['folder' => $folder] = fdBigFolder(1, 'lon', 3); // the Trash counts too: 3 files > 2

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('askDeleteFolder', 'lon')
            ->assertSet('folderInBatches', true)
            ->assertSet('folderNoUndo', true)
            ->assertSee('Tệp được chuyển dần về Tất cả ảnh trong vài phút; thư mục biến mất khi chuyển xong.')
            ->call('confirmDeleteFolder')
            ->assertSet('folderInBatches', false)
            ->assertSet('undoFolder', '')
            ->assertSet('toast', 'Đang chuyển các tệp của thư mục "Lớn lon" về Tất cả ảnh (Chưa phân loại)… Thư mục biến mất khi chuyển xong, không có Hoàn tác. Nếu vài phút sau thư mục vẫn còn, hãy xóa lại.')
            ->assertDontSee('undoFolderDelete', false);
    });

    // nothing was rewritten inside the request: the job does it
    Queue::assertPushed(DeleteLargeMediaFolder::class, fn (DeleteLargeMediaFolder $job): bool => $job->siteId === 1 && $job->folderId === $folder->id && $job->after === 0);
    expect(fdLeft(1, $folder->id))->toBe(['filed' => 3, 'folder' => true]);

    // exactly at the limit the folder goes inside the request, with Hoàn tác
    $small = fdFolder(1, 'Nhỏ', 'nho', 2);
    fdFile(1, 'n1.png', $small);
    fdFile(1, 'n2.png', $small);
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Library::class)
        ->call('askDeleteFolder', 'nho')->assertSet('folderInBatches', false)->assertSet('folderNoUndo', false)
        ->call('confirmDeleteFolder')->assertNotSet('undoFolder', ''));
    expect(fdLeft(1, $small->id))->toBe(['filed' => 0, 'folder' => false]);
    Queue::assertPushed(DeleteLargeMediaFolder::class, 1);
});

it('empties a large folder in batches, each in its own site transaction, then deletes it', function (): void {
    config(['media.folder_delete_batch_files' => 2]);
    ['folder' => $folder, 'files' => $files] = fdBigFolder(1, 'lon', 5);
    $other = fdFolder(1, 'Khác', 'khac', 2);
    $kept = fdFile(1, 'khac.png', $other);
    $theirs = fdFolder(2, 'Của B', 'cua-b', 1);
    $theirFile = fdFile(2, 'b.png', $theirs);

    $contexts = 0;
    $updates = [];
    DB::listen(function ($query) use (&$contexts, &$updates): void {
        if (str_contains($query->sql, 'set_config')) {
            $contexts++;
        }
        if (str_starts_with($query->sql, 'update "media"')) {
            // every batch runs with this site's context set for its own transaction
            $updates[] = DB::scalar("select current_setting('app.current_site_id', true)");
        }
    });
    $done = (new DeleteLargeMediaFolder(1, $folder->id))->handle(app(Tenancy::class));

    expect($done)->toBeTrue()
        ->and($updates)->toBe(['1', '1', '1']) // 5 files, 2 at a time
        ->and($contexts)->toBe(6) // the folder's family, three batches, the empty read that ends them, the delete
        ->and(fdLeft(1, $folder->id))->toBe(['filed' => 0, 'folder' => false])
        ->and(app(Tenancy::class)->enter(1, fn () => Media::withTrashed()->whereIn('id', array_map(fn (Media $f): int => $f->id, $files))->count()))->toBe(5)
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->find($kept->id)?->folder_id))->toBe($other->id)
        ->and(app(Tenancy::class)->enter(2, fn () => Media::query()->find($theirFile->id)?->folder_id))->toBe($theirs->id);
});

it('never touches another site with a queued folder delete', function (): void {
    ['folder' => $folder] = fdBigFolder(1, 'lon', 3);

    // a job naming site B with site A's folder finds nothing under site B's context
    expect((new DeleteLargeMediaFolder(2, $folder->id))->handle(app(Tenancy::class)))->toBeTrue()
        ->and(fdLeft(1, $folder->id))->toBe(['filed' => 3, 'folder' => true]);
});

it('picks up a large folder delete where it stopped after a failure, and a second run finds nothing to do', function (): void {
    config(['media.folder_delete_batch_files' => 2]);
    ['folder' => $folder] = fdBigFolder(1, 'lon', 5);

    $seen = 0;
    DB::listen(function ($query) use (&$seen): void {
        if (str_starts_with($query->sql, 'update "media"') && ++$seen === 2) {
            throw new RuntimeException('the worker died');
        }
    });
    expect(fn () => (new DeleteLargeMediaFolder(1, $folder->id))->handle(app(Tenancy::class)))->toThrow(RuntimeException::class);
    // the first batch stayed done; the folder is still there
    expect(fdLeft(1, $folder->id))->toBe(['filed' => 3, 'folder' => true]);

    expect((new DeleteLargeMediaFolder(1, $folder->id))->handle(app(Tenancy::class)))->toBeTrue()
        ->and(fdLeft(1, $folder->id))->toBe(['filed' => 0, 'folder' => false])
        ->and((new DeleteLargeMediaFolder(1, $folder->id))->handle(app(Tenancy::class)))->toBeTrue();
});

it('sweeps again when more files than one batch came into a large folder below where it had got to', function (): void {
    config(['media.folder_delete_batch_files' => 1]);
    $early = [fdFile(1, 'early-1.png'), fdFile(1, 'early-2.png')]; // unfiled, lower ids than the folder's files
    ['folder' => $folder] = fdBigFolder(1, 'lon', 3);

    // once the job has passed the folder's first file, someone moves the two older files into it
    $moved = false;
    DB::listen(function ($query) use (&$moved, $early, $folder): void {
        if (! $moved && str_starts_with($query->sql, 'update "media"')) {
            $moved = true;
            DB::table('media')->whereIn('id', [$early[0]->id, $early[1]->id])->update(['folder_id' => $folder->id]);
        }
    });
    $unfiled = 0; // the job's own batches (folder_id set to null), one file each here
    DB::listen(function ($query) use (&$unfiled): void {
        if (str_starts_with($query->sql, 'update "media" set "folder_id" = ?') && array_key_exists(0, $query->bindings) && $query->bindings[0] === null) {
            $unfiled++;
        }
    });
    expect((new DeleteLargeMediaFolder(1, $folder->id))->handle(app(Tenancy::class)))->toBeTrue()
        ->and($moved)->toBeTrue()
        // 3 in the first sweep, the 2 that came in below the cursor in a second one: never left to the row delete
        ->and($unfiled)->toBe(5)
        ->and(fdLeft(1, $folder->id))->toBe(['filed' => 0, 'folder' => false]);
});

it('offers «Tải ảnh lên vào đây» in the open folder’s menu and opens the upload panel on that folder', function (): void {
    $user = fdUser('admin');
    $top = fdFolder(1, 'Công trình', 'cong-trinh', 1);
    $sub = app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->create(['name' => 'Nhà xưởng', 'slug' => 'nha-xuong', 'parent_id' => $top->id, 'position' => 1]));
    fdFile(1, 'a.png', $sub);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'nha-xuong')
            ->assertSee("uploadInto('nha-xuong')", false)
            ->assertSee(__('media.lib.upload_here'));
        $page->call('setFolder', '')->call('uploadInto', 'nha-xuong')
            ->assertSet('folder', 'nha-xuong')
            ->assertSet('uploadOpen', true)
            ->assertSet('pickerSource', null)
            ->assertDispatched('media-upload-opened')
            ->assertDispatched('media-folder-open', slug: 'cong-trinh')
            ->assertSeeHtml('data-upload-folder="nha-xuong"')
            ->assertSeeHtml(e(__('media.lib.up_to_folder', ['name' => 'Nhà xưởng'])));
    });
});

it('says new files go to «Tất cả ảnh (Chưa phân loại)» outside a folder, in the Trash too', function (string $place): void {
    $user = fdUser('admin');
    fdFolder(1, 'Công trình', 'cong-trinh', 1);
    fdFile(1, 'a.png');

    app(Tenancy::class)->enter(1, function () use ($user, $place): void {
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', $place)->call('openUpload')
            ->assertSeeHtml('data-upload-folder=""')
            ->assertSeeHtml(e(__('media.lib.up_to_all')))
            ->assertDontSeeHtml(e(__('media.lib.up_to_folder', ['name' => 'Công trình'])));
    });
})->with(['', '-', '~trash']);

it('lets an uploader without the folder rights upload into a folder, and refuses one who cannot upload', function (): void {
    $editor = fdUser('editor');
    $viewer = fdUser('viewer');
    fdFolder(1, 'Công trình', 'cong-trinh', 1);
    fdFile(1, 'a.png');

    app(Tenancy::class)->enter(1, function () use ($editor, $viewer): void {
        // no ⋮ for an editor (the folder menu is the website admin's), but the upload panel still files into the folder in view
        Livewire::actingAs($editor)->test(Library::class)->call('setFolder', 'cong-trinh')
            ->assertDontSee("uploadInto('cong-trinh')", false)
            ->call('openUpload')->assertSeeHtml('data-upload-folder="cong-trinh"');
        Livewire::actingAs($editor)->test(Library::class)->call('uploadInto', 'cong-trinh')->assertSet('uploadOpen', true);

        Livewire::actingAs($viewer)->test(Library::class)->call('uploadInto', 'cong-trinh')->assertForbidden();
        Livewire::actingAs($viewer)->test(Library::class)->call('openUpload', 'device')->assertForbidden();
    });
});

it('never opens another site’s folder for an upload', function (): void {
    $user = fdUser('admin');
    fdFolder(2, 'Của site B', 'cua-site-b', 1);
    fdFile(1, 'a.png');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('uploadInto', 'cua-site-b')
            ->assertSet('folder', '')
            ->assertSet('uploadOpen', false)
            ->assertNotDispatched('media-upload-opened');
    });
});

it('takes dropped files to the device pane, and offers the drop cover only while an upload is possible', function (): void {
    $admin = fdUser('admin');
    $viewer = fdUser('viewer');
    fdFolder(1, 'Công trình', 'cong-trinh', 1);
    fdFile(1, 'a.png');

    app(Tenancy::class)->enter(1, function () use ($admin, $viewer): void {
        $page = Livewire::actingAs($admin)->test(Library::class)->call('setFolder', 'cong-trinh')
            ->assertSeeHtml('data-drop-ok="true"')
            ->assertSeeHtml('data-drop-cover')
            ->assertSeeHtml(e(__('media.lib.drop_to_folder', ['name' => 'Công trình'])));
        $page->call('openUpload', 'device')
            ->assertSet('uploadOpen', true)
            ->assertSet('pickerSource', 'device')
            ->assertSeeHtml('data-drop-ok="false"'); // the panel's own drop area takes over
        Livewire::actingAs($admin)->test(Library::class)->call('openUpload', 'url-or-anything')->assertSet('pickerSource', null)
            ->call('closeUpload')->assertSeeHtml(e(__('media.lib.drop_to_all')));

        Livewire::actingAs($viewer)->test(Library::class)
            ->assertSeeHtml('data-drop-ok="false"')
            ->assertDontSeeHtml('data-drop-cover');
    });
});

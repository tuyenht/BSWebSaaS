<?php

use App\Modules\Admin\Livewire\Catalog\Editor;
use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Jobs\DeleteLargeMediaFolder;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Sub-folders in the media library, two levels at most: «Thư mục con mới» on a top-level folder,
 * «Nằm trong» in the new-folder form, «Chuyển thư mục…» (into a top-level folder or up to the top
 * level, with Hoàn tác), and deleting a folder with its sub-folders. Each is the website admin's
 * right (media.folders); a folder of another site is never a parent, a target or a subject.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
    app()->setLocale('vi');
    // a library with no file at all shows its empty start instead of the folder column
    sfFile(1, 'loose-a.png');
    sfFile(2, 'loose-b.png');
});

function sfFolder(int $site, string $name, string $slug, int $position, ?MediaFolder $parent = null): MediaFolder
{
    return app(Tenancy::class)->enter($site, fn () => MediaFolder::query()->create(['name' => $name, 'slug' => $slug, 'position' => $position, 'parent_id' => $parent?->id]));
}

function sfFile(int $site, string $name, ?MediaFolder $folder = null): Media
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

function sfUser(string $role, int $site = 1): User
{
    $spec = PermissionRegistry::tenantRoleTemplates('corporate')[$role]['permissions'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => grantRole($user, $role, $spec === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $spec));

    return $user->fresh();
}

/** @return array<string, array{parent: string|null, position: int}> each folder of the site by slug (outside any site context) */
function sfTree(int $site): array
{
    return app(Tenancy::class)->enter($site, fn (): array => sfTreeHere());
}

/** @return array<string, array{parent: string|null, position: int}> the same, inside the site's context */
function sfTreeHere(): array
{
    return (function (): array {
        $all = MediaFolder::query()->get(['id', 'slug', 'parent_id', 'position']);
        $slugOf = $all->pluck('slug', 'id');
        $out = [];
        foreach ($all as $f) {
            $out[(string) $f->slug] = ['parent' => $f->parent_id === null ? null : (string) $slugOf[$f->parent_id], 'position' => $f->position];
        }
        ksort($out);

        return $out;
    })();
}

it('keeps sub-folders and «Chuyển thư mục…» to the website admin: every other role is refused', function (string $role): void {
    $user = sfUser($role);
    $top = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $top);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'anh-xuong')
            ->assertDontSee('Thư mục con mới')
            ->assertDontSee('wire:click="openReparent', false);

        foreach ([['openNewFolder', ['anh-xuong']], ['createFolder', []], ['openReparent', ['may-cnc']], ['confirmReparent', []], ['undoReparent', []]] as [$method, $args]) {
            Livewire::actingAs($user)->test(Library::class)->call($method, ...$args)->assertForbidden();
        }
    });
    expect(sfTree(1))->toBe([
        'anh-xuong' => ['parent' => null, 'position' => 1],
        'may-cnc' => ['parent' => 'anh-xuong', 'position' => 1],
        'san-pham' => ['parent' => null, 'position' => 2],
    ]);
})->with(['author', 'editor', 'contributor', 'viewer']);

it('makes a sub-folder from «Thư mục con mới» or «Nằm trong»; names are unique per parent only', function (string $role): void {
    $user = sfUser($role);
    sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'anh-xuong')
            ->assertSee("openNewFolder('anh-xuong')", false)
            ->assertSee('Thư mục con mới')
            ->call('openNewFolder', 'anh-xuong')
            ->assertSet('newFolderParent', 'anh-xuong')
            ->assertSee('Nằm trong')
            ->set('newFolderName', 'Máy CNC')
            ->call('createFolder')
            ->assertHasNoErrors()
            ->assertSet('toast', 'Đã tạo thư mục con "Máy CNC" trong "Ảnh xưởng".')
            ->assertSet('newFolderParent', '')
            ->assertDispatched('media-folder-open', slug: 'anh-xuong');

        // the same name under another parent, or at the top level, is fine; twice in one parent is not
        $page->call('openNewFolder')->set('newFolderParent', 'san-pham')->set('newFolderName', 'Máy CNC')->call('createFolder')->assertHasNoErrors();
        $page->call('openNewFolder')->set('newFolderName', 'máy cnc')->call('createFolder')->assertHasNoErrors();
        $page->call('openNewFolder', 'anh-xuong')->set('newFolderName', 'MÁY CNC')->call('createFolder')
            ->assertHasErrors(['newFolderName'])->assertSee('Ở đây đã có thư mục tên này — đặt tên khác.');
    });

    $tree = sfTree(1);
    expect(collect($tree)->where('parent', 'anh-xuong')->count())->toBe(1)
        ->and(collect($tree)->where('parent', 'san-pham')->count())->toBe(1)
        ->and(collect($tree)->whereNull('parent')->count())->toBe(3);
})->with(['admin', 'owner']);

it('never puts a folder three levels deep: a sub-folder offers no «Thư mục con mới» and is refused as a parent', function (): void {
    $user = sfUser('admin');
    $top = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $top);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'may-cnc')
            ->assertDontSee("openNewFolder('may-cnc')", false)
            // asked for a sub-folder's sub-folder, the form starts a top-level one
            ->call('openNewFolder', 'may-cnc')
            ->assertSet('newFolderParent', '')
            // a page that sends a sub-folder as the parent anyway gets a plain message, nothing made
            ->set('newFolderParent', 'may-cnc')
            ->set('newFolderName', 'Cấp ba')
            ->call('createFolder')
            ->assertHasErrors(['newFolderParent'])
            ->assertSee('Thư mục chứa nó vừa bị xóa hoặc chuyển đi — chọn lại mục Nằm trong.');
    });
    expect(array_keys(sfTree(1)))->toBe(['anh-xuong', 'may-cnc']);
});

it('shows sub-folders under their parent; a parent counts and lists its sub-folders\' files', function (): void {
    $user = sfUser('admin');
    $top = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $kid = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $top);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFile(1, 'own.png', $top);
    sfFile(1, 'kid.png', $kid);
    sfFile(1, 'loose.png');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->assertSeeInOrder(['Ảnh xưởng', 'Máy CNC', 'Sản phẩm'])
            ->assertSee('Gập thư mục con của “Ảnh xưởng”')
            ->call('setFolder', 'anh-xuong')
            ->assertSee('own.png')
            ->assertSee('kid.png')
            ->assertDontSee('loose.png');
        expect($page->viewData('treeN'))->toMatchArray([MediaFolder::query()->where('slug', 'anh-xuong')->value('id') => 2]);

        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'may-cnc')
            ->assertSee('kid.png')
            ->assertDontSee('own.png');
    });
});

it('moves a folder into a top-level folder and up again, each with Hoàn tác back to its old place', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFolder(1, 'Sự kiện', 'su-kien', 3);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'san-pham')
            ->call('openReparent', 'san-pham')
            ->assertSet('reparentOpen', true)
            ->assertSee('Chuyển thư mục "Sản phẩm" vào…')
            ->assertSee('Chọn nơi đến')
            ->assertSee('0 tệp · 1 thư mục con')
            ->assertDontSee('Không — làm thư mục chính');

        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'san-pham')
            ->set('reparentTo', 'anh-xuong')
            ->assertSee('Chuyển vào Ảnh xưởng')
            ->call('confirmReparent')
            ->assertSet('reparentOpen', false)
            ->assertSet('toast', 'Đã chuyển "Sản phẩm" vào "Ảnh xưởng".')
            ->assertDispatched('media-folder-open', slug: 'anh-xuong');
        expect(sfTreeHere())->toBe([
            'anh-xuong' => ['parent' => null, 'position' => 1],
            'may-cnc' => ['parent' => 'anh-xuong', 'position' => 1],
            'san-pham' => ['parent' => 'anh-xuong', 'position' => 2],
            'su-kien' => ['parent' => null, 'position' => 2],
        ]);

        $page->call('undoReparent')->assertSet('toast', 'Đã đưa "Sản phẩm" về chỗ cũ.');
        expect(sfTreeHere())->toBe([
            'anh-xuong' => ['parent' => null, 'position' => 1],
            'may-cnc' => ['parent' => 'anh-xuong', 'position' => 1],
            'san-pham' => ['parent' => null, 'position' => 2],
            'su-kien' => ['parent' => null, 'position' => 3],
        ]);

        // a sub-folder goes up to the top level (at the end), and back
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'may-cnc')
            ->assertSee('Không — làm thư mục chính')
            ->assertSee('Hiện ngay dưới Tất cả ảnh')
            ->set('reparentTo', '-')
            ->assertSee('Đưa lên làm thư mục chính')
            ->call('confirmReparent')
            ->assertSet('toast', 'Đã đưa "Máy CNC" lên làm thư mục chính.');
        expect(sfTreeHere()['may-cnc'])->toBe(['parent' => null, 'position' => 4]);
        $page->call('undoReparent');
        expect(sfTreeHere()['may-cnc'])->toBe(['parent' => 'anh-xuong', 'position' => 1])
            ->and(sfTreeHere()['su-kien'])->toBe(['parent' => null, 'position' => 3]);
    });
});

it('says in the menu why «Chuyển thư mục…» cannot be used, and refuses it on the server too', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        // the only top-level folder, holding a sub-folder: the sub-folder reason wins
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'anh-xuong')
            ->assertSee('Thư mục đang chứa thư mục con — không đặt vào thư mục khác được.')
            ->call('openReparent', 'anh-xuong')
            ->assertSet('reparentOpen', false);
    });

    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'san-pham')
            ->assertDontSee('Thư mục đang chứa thư mục con')
            ->assertDontSee('Chưa có thư mục chính nào khác.')
            ->assertSee("openReparent('san-pham')", false);
        // a top-level folder with sub-folders never goes inside another one, whatever the page sends
        $page = Livewire::actingAs($user)->test(Library::class)->call('openReparent', 'san-pham')->set('reparentTo', 'san-pham')->call('confirmReparent')
            ->assertSet('toastTone', 'error');
        expect(sfTreeHere()['san-pham'])->toBe(['parent' => null, 'position' => 2]);
        expect(fn () => $page->set('reparentFolder', 'anh-xuong'))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('says «Chưa có thư mục chính nào khác.» when there is nowhere to move a lone top-level folder', function (): void {
    $user = sfUser('admin');
    sfFolder(1, 'Một mình', 'mot-minh', 1);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'mot-minh')
            ->assertSee('Chưa có thư mục chính nào khác.')
            ->assertDontSee("openReparent('mot-minh')", false)
            ->call('openReparent', 'mot-minh')
            ->assertSet('reparentOpen', false);
    });
});

it('refuses a move into a folder that already has one of that name, and keeps the folder where it was', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFolder(1, 'Sản phẩm', 'san-pham-2', 1, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'san-pham')->set('reparentTo', 'anh-xuong')->call('confirmReparent')
            ->assertSet('toastTone', 'error')
            ->assertSet('toast', 'Ở nơi đến đã có thư mục tên này — đổi tên thư mục rồi chuyển lại.')
            ->assertSet('undoReparent', '');
    });
    expect(sfTree(1)['san-pham'])->toBe(['parent' => null, 'position' => 2]);
});

it('asks before deleting a folder with sub-folders, then Hoàn tác brings back all of them with their files', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $k1 = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    $k2 = sfFolder(1, 'Máy tiện', 'may-tien', 2, $a);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    $own = sfFile(1, 'own.png', $a);
    $kidFile = sfFile(1, 'kid.png', $k2);

    app(Tenancy::class)->enter(1, function () use ($user, $own, $kidFile): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'may-tien')
            ->call('askDeleteFolder', 'anh-xuong')
            ->assertSet('askFolderOpen', true)
            ->assertSee('Xóa thư mục "Ảnh xưởng" và 2 thư mục con?')
            ->assertSee('Thư mục và các thư mục con có 2 tệp.')
            ->assertSee('Xóa 3 thư mục và đưa 2 tệp về Tất cả ảnh')
            ->call('confirmDeleteFolder')
            ->assertSet('folder', '')
            ->assertSet('toast', 'Đã xóa thư mục "Ảnh xưởng", 2 tệp về Tất cả ảnh (Chưa phân loại).');
        expect(MediaFolder::query()->pluck('slug')->all())->toBe(['san-pham'])
            ->and(Media::query()->whereKey([$own->id, $kidFile->id])->whereNull('folder_id')->count())->toBe(2);

        $page->call('undoFolderDelete')->assertSet('folder', 'anh-xuong');
        $tree = MediaFolder::query()->get(['id', 'slug', 'parent_id', 'position'])->keyBy('slug');
        expect($tree['may-cnc']->parent_id)->toBe($tree['anh-xuong']->id)
            ->and($tree['may-tien']->parent_id)->toBe($tree['anh-xuong']->id)
            ->and([$tree['anh-xuong']->position, $tree['may-cnc']->position, $tree['may-tien']->position])->toBe([1, 1, 2])
            ->and(Media::query()->find($own->id)?->folder_id)->toBe($tree['anh-xuong']->id)
            ->and(Media::query()->find($kidFile->id)?->folder_id)->toBe($tree['may-tien']->id);
    });
});

it('counts the sub-folders\' files toward a folder delete left to the queued job, which empties and deletes them all', function (): void {
    config(['media.folder_delete_inline_max_files' => 2, 'media.folder_delete_batch_files' => 1]);
    Queue::fake();
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $k1 = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    $k2 = sfFolder(1, 'Máy tiện', 'may-tien', 2, $a);
    $other = sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    $files = [sfFile(1, 'own.png', $a), sfFile(1, 'cnc.png', $k1), sfFile(1, 'tien-1.png', $k2), sfFile(1, 'tien-2.png', $k2)];
    $kept = sfFile(1, 'kept.png', $other);

    // two files in the folder itself would go inside the request; with its sub-folders' it is four
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user)->test(Library::class)
        ->call('askDeleteFolder', 'anh-xuong')
        ->assertSet('folderInBatches', true)
        ->call('confirmDeleteFolder')
        ->assertSet('undoFolder', ''));
    Queue::assertPushed(DeleteLargeMediaFolder::class, fn (DeleteLargeMediaFolder $job): bool => $job->folderId === $a->id);
    expect(sfTree(1))->toHaveCount(4);

    $unfiled = 0; // the job's own batches (folder_id set to null), one file each here
    DB::listen(function ($query) use (&$unfiled): void {
        if (str_starts_with($query->sql, 'update "media" set "folder_id" = ?') && array_key_exists(0, $query->bindings) && $query->bindings[0] === null) {
            $unfiled++;
        }
    });
    expect((new DeleteLargeMediaFolder(1, $a->id))->handle(app(Tenancy::class)))->toBeTrue()
        ->and($unfiled)->toBe(4) // every file of the family by the job, none left to the row delete
        ->and(DeleteLargeMediaFolder::pending(1, $a->id))->toBeFalse();
    app(Tenancy::class)->enter(1, function () use ($files, $kept, $other): void {
        expect(MediaFolder::query()->pluck('slug')->all())->toBe(['san-pham'])
            ->and(Media::query()->whereKey(array_map(fn (Media $f): int => $f->id, $files))->whereNull('folder_id')->count())->toBe(4)
            ->and(Media::query()->find($kept->id)?->folder_id)->toBe($other->id);
    });
});

it('keeps the files of a sub-folder moved out while the queued job is emptying its old parent', function (): void {
    config(['media.folder_delete_batch_files' => 1]);
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $k1 = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    $k2 = sfFolder(1, 'Máy tiện', 'may-tien', 2, $a);
    sfFile(1, 'cnc.png', $k1);
    $stays = [sfFile(1, 'tien-1.png', $k2), sfFile(1, 'tien-2.png', $k2)];

    // after the job's first batch, someone moves «Máy tiện» up to the top level
    $moved = false;
    DB::listen(function ($query) use (&$moved, $k2): void {
        if (! $moved && str_starts_with($query->sql, 'update "media" set "folder_id" = ?')) {
            $moved = true;
            DB::table('media_folders')->where('id', $k2->id)->update(['parent_id' => null]);
        }
    });
    expect((new DeleteLargeMediaFolder(1, $a->id))->handle(app(Tenancy::class)))->toBeTrue()->and($moved)->toBeTrue();

    expect(sfTree(1))->toBe(['may-tien' => ['parent' => null, 'position' => 2]])
        ->and(app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey([$stays[0]->id, $stays[1]->id])->where('folder_id', $k2->id)->count()))->toBe(2);
});

it('starts a handed-over run at the first sub-folder from the start when the one it was in is gone', function (): void {
    config(['media.folder_delete_batch_files' => 1]);
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $k1 = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    sfFile(1, 'cnc-1.png', $k1);
    sfFile(1, 'cnc-2.png', $k1);
    sfFile(1, 'own.png', $a);

    $contexts = 0;
    DB::listen(function ($query) use (&$contexts): void {
        if (str_contains($query->sql, 'set_config')) {
            $contexts++;
        }
    });
    // the last run was in a sub-folder deleted since, far along its ids
    expect((new DeleteLargeMediaFolder(1, $a->id, after: PHP_INT_MAX - 1, sweeping: 999999))->handle(app(Tenancy::class)))->toBeTrue();

    // one pass: the family, 2 + 1 empty batches in «Máy CNC», 1 + 1 in the folder, the delete — no second sweep
    expect($contexts)->toBe(7)->and(sfTree(1))->toBe([]);
});

it('puts nothing new under a folder while the queued job deletes it', function (): void {
    config(['media.folder_delete_inline_max_files' => 1]);
    Queue::fake();
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $kid = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFile(1, 'a.png', $a);
    sfFile(1, 'b.png', $a);

    $undo = app(Tenancy::class)->enter(1, function () use ($user, $a) {
        // a sub-folder deleted first, with Hoàn tác
        $undo = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'may-cnc');
        expect($undo->get('undoFolder'))->not->toBe('');
        Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'anh-xuong')->call('confirmDeleteFolder');
        expect(DeleteLargeMediaFolder::pending(1, $a->id))->toBeFalse(); // marked only once the request commits

        return $undo;
    });
    expect(DeleteLargeMediaFolder::pending(1, $a->id))->toBeTrue();

    app(Tenancy::class)->enter(1, function () use ($user, $undo): void {
        // no new sub-folder, no move into it
        Livewire::actingAs($user)->test(Library::class)
            ->call('openNewFolder', 'anh-xuong')->set('newFolderName', 'Mới')->call('createFolder')
            ->assertHasErrors('newFolderParent');
        Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'san-pham')->set('reparentTo', 'anh-xuong')->call('confirmReparent');
        // Hoàn tác of the sub-folder brings it back at the top level, not into the folder being deleted
        $undo->call('undoFolderDelete');
    });
    expect(sfTree(1))->toBe([
        'anh-xuong' => ['parent' => null, 'position' => 2],
        'may-cnc' => ['parent' => null, 'position' => 1], // its old place, first, but at the top level
        'san-pham' => ['parent' => null, 'position' => 3],
    ]);

    // a job out of tries keeps the mark: another run of the folder may still be working
    (new DeleteLargeMediaFolder(1, $a->id))->failed(null);
    expect(DeleteLargeMediaFolder::pending(1, $a->id))->toBeTrue();
});

it('asks before deleting empty sub-folders too, saying no file is affected', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('askDeleteFolder', 'anh-xuong')
            ->assertSet('askFolderOpen', true)
            ->assertSee('Các thư mục này đều trống, không có tệp nào bị ảnh hưởng.')
            ->assertSee('Xóa 2 thư mục');
    });
});

it('brings a deleted sub-folder back inside its parent, or at the top level when the parent went meanwhile', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    sfFolder(1, 'Máy tiện', 'may-tien', 2, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'may-cnc');
        expect(MediaFolder::query()->where('slug', 'may-tien')->value('position'))->toBe(2);
        $page->call('undoFolderDelete')->assertDispatched('media-folder-open', slug: 'anh-xuong');
    });
    expect(sfTree(1)['may-cnc'])->toBe(['parent' => 'anh-xuong', 'position' => 1])
        ->and(sfTree(1)['may-tien'])->toBe(['parent' => 'anh-xuong', 'position' => 2]);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'may-cnc');
        MediaFolder::query()->where('slug', 'anh-xuong')->delete();
        $page->call('undoFolderDelete')->assertSet('folder', 'may-cnc');
    });
    expect(sfTree(1))->toBe(['may-cnc' => ['parent' => null, 'position' => 1]]);

    // a new folder that took the old parent's slug meanwhile is not the old parent
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $p = MediaFolder::query()->create(['name' => 'Ảnh xưởng', 'slug' => 'anh-xuong', 'position' => 2]);
        MediaFolder::query()->where('slug', 'may-cnc')->update(['parent_id' => $p->id, 'position' => 1]);
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'may-cnc');
        MediaFolder::query()->where('slug', 'anh-xuong')->delete();
        MediaFolder::query()->create(['name' => 'Ảnh xưởng', 'slug' => 'anh-xuong', 'position' => 1]);
        $page->call('undoFolderDelete');
    });
    expect(sfTree(1)['may-cnc']['parent'])->toBeNull();
});

it('never uses a folder of another site as a parent, a target or a subject', function (): void {
    $user = sfUser('admin', 2);
    $theirs = sfFolder(1, 'Của A', 'cua-a', 1);
    sfFolder(1, 'Con của A', 'con-cua-a', 1, $theirs);
    sfFolder(2, 'Của B', 'cua-b', 1);
    sfFolder(2, 'Khác B', 'khac-b', 2);

    app(Tenancy::class)->enter(2, function () use ($user): void {
        // site A's folder as the parent of a new one: refused, nothing made on either site
        Livewire::actingAs($user)->test(Library::class)
            ->call('openNewFolder', 'cua-a')
            ->assertSet('newFolderParent', '')
            ->set('newFolderParent', 'cua-a')
            ->set('newFolderName', 'Lạc site')
            ->call('createFolder')
            ->assertHasErrors(['newFolderParent']);

        // site A's folder as the thing moved, or as where it goes
        Livewire::actingAs($user)->test(Library::class)->call('openReparent', 'con-cua-a')->assertSet('reparentOpen', false);
        Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'khac-b')
            ->assertDontSee('Của A')
            ->set('reparentTo', 'cua-a')
            ->call('confirmReparent')
            ->assertSet('toastTone', 'error');
    });

    expect(sfTree(1))->toBe([
        'con-cua-a' => ['parent' => 'cua-a', 'position' => 1],
        'cua-a' => ['parent' => null, 'position' => 1],
    ])->and(sfTree(2))->toBe([
        'cua-b' => ['parent' => null, 'position' => 1],
        'khac-b' => ['parent' => null, 'position' => 2],
    ]);
});

it('moves a sub-folder up and down among the sub-folders of its parent only', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    sfFolder(1, 'Máy tiện', 'may-tien', 2, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('setFolder', 'may-cnc')
            ->assertSee('Đang ở đầu danh sách.')
            ->call('moveFolder', 'may-cnc', -1)
            ->call('moveFolder', 'may-cnc', 1)
            ->assertSet('toast', 'Đã chuyển thư mục xuống.');
    });
    expect(sfTree(1))->toBe([
        'anh-xuong' => ['parent' => null, 'position' => 1],
        'may-cnc' => ['parent' => 'anh-xuong', 'position' => 2],
        'may-tien' => ['parent' => 'anh-xuong', 'position' => 1],
        'san-pham' => ['parent' => null, 'position' => 2],
    ]);
});

it('checks a new name against the folders of the same parent when renaming', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    $b = sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    sfFolder(1, 'Máy tiện', 'may-tien', 2, $a);
    sfFolder(1, 'Bao bì', 'bao-bi', 1, $b);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->call('openRename', 'may-tien')->set('renameName', 'máy cnc')->call('saveRename')
            ->assertHasErrors(['renameName'])
            ->set('renameName', 'Bao bì')->call('saveRename')
            ->assertHasNoErrors()
            ->assertSet('renaming', '');
        expect(MediaFolder::query()->where('slug', 'may-tien')->value('name'))->toBe('Bao bì');
    });
});

it('puts a folder back at the same place in the list even when the place numbers have gaps', function (): void {
    $user = sfUser('admin');
    $gaps = function (): void {
        foreach (['a' => 1, 'b' => 3, 'c' => 5, 'd' => 7] as $slug => $at) {
            MediaFolder::query()->where('slug', $slug)->update(['position' => $at, 'parent_id' => null]);
        }
    };
    foreach (['A', 'B', 'C', 'D'] as $i => $n) {
        sfFolder(1, $n, strtolower($n), $i + 1);
    }
    $order = fn (): array => MediaFolder::query()->whereNull('parent_id')->orderBy('position')->orderBy('name')->pluck('slug')->map(fn ($s): string => (string) $s)->all();

    app(Tenancy::class)->enter(1, function () use ($user, $order, $gaps): void {
        // C is third in the list but has place number 5: Hoàn tác puts it third again
        $gaps();
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'c')->set('reparentTo', 'a')->call('confirmReparent');
        expect($order())->toBe(['a', 'b', 'd']);
        $page->call('undoReparent');
        expect($order())->toBe(['a', 'b', 'c', 'd']);

        // the same for a folder delete
        $gaps();
        $page = Livewire::actingAs($user)->test(Library::class)->call('askDeleteFolder', 'c');
        expect($order())->toBe(['a', 'b', 'd']);
        $page->call('undoFolderDelete');
        expect($order())->toBe(['a', 'b', 'c', 'd']);
    });
});

it('says plainly when Hoàn tác of a move cannot put the folder back, and leaves it where it is', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    sfFolder(1, 'Sản phẩm', 'san-pham', 2);
    sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('openReparent', 'may-cnc')->set('reparentTo', 'san-pham')->call('confirmReparent');
        // its old parent goes, and a new folder takes the freed slug: it is not the old place
        MediaFolder::query()->where('slug', 'anh-xuong')->delete();
        MediaFolder::query()->create(['name' => 'Ảnh xưởng', 'slug' => 'anh-xuong', 'position' => 9]);
        $page->call('undoReparent')
            ->assertSet('toastTone', 'error')
            ->assertSet('toast', 'Không đưa về chỗ cũ được: thư mục hoặc chỗ cũ vừa thay đổi.');
    });
    expect(sfTree(1)['may-cnc'])->toBe(['parent' => 'san-pham', 'position' => 1]);
});

it('shows sub-folders in the picker under their parent, and a parent there lists its sub-folders\' files', function (): void {
    $user = sfUser('admin');
    $a = sfFolder(1, 'Ảnh xưởng', 'anh-xuong', 2);
    $kid = sfFolder(1, 'Máy CNC', 'may-cnc', 1, $a);
    sfFolder(1, 'Sản phẩm', 'san-pham', 1);
    sfFile(1, 'own.png', $a);
    sfFile(1, 'kid.png', $kid);

    app(Tenancy::class)->enter(1, function () use ($user, $a): void {
        $picker = Livewire::actingAs($user)->test(Editor::class)->call('openImagePicker', 'gallery');
        $state = $picker->instance()->mediaPickerState();
        expect($state['folders']->pluck('slug')->map(fn ($s): string => (string) $s)->all())->toBe(['san-pham', 'anh-xuong', 'may-cnc'])
            ->and($state['folders']->firstWhere('slug', 'anh-xuong')->media_count)->toBe(2);

        $picker->call('setPickerFolder', $a->id);
        expect($picker->instance()->mediaPickerState()['page']->pluck('original_filename')->sort()->values()->all())->toBe(['kid.png', 'own.png']);
    });
});

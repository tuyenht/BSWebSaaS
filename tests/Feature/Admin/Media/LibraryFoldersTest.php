<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * The folder actions of the media library: rename (the slug stays) and move up / down. Each is the
 * website admin's right (media.folders), and a folder of another site never resolves.
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

it('keeps the folder actions to the website admin: author and editor see no ⋮ and are refused', function (string $role): void {
    $user = fdUser($role);
    $folder = fdFolder(1, 'Ảnh xưởng', 'anh-xuong', 1);
    fdFolder(1, 'Sản phẩm', 'san-pham', 2);
    fdFile(1, 'a.png', $folder);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)->call('setFolder', 'anh-xuong')
            ->assertDontSee('wire:click="openRename', false)
            ->assertDontSee('wire:click="moveFolder', false);

        foreach ([['openRename', ['anh-xuong']], ['saveRename', []], ['moveFolder', ['anh-xuong', 1]]] as [$method, $args]) {
            Livewire::actingAs($user)->test(Library::class)->call($method, ...$args)->assertForbidden();
        }
    });
    expect(fdOrder(1))->toBe(['anh-xuong', 'san-pham'])
        ->and(app(Tenancy::class)->enter(1, fn () => MediaFolder::query()->where('slug', 'anh-xuong')->value('name')))->toBe('Ảnh xưởng');
})->with(['author', 'editor']);

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

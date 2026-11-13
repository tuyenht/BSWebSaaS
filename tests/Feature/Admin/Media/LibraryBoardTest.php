<?php

use App\Modules\Admin\Livewire\Media\Library;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The media library page: folders, filters, cursor pages, selection and the Trash. Files are
 * addressed by ulid and folders by slug; every action checks its own right; a ulid or slug of
 * another site never resolves.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Storage::fake('r2');
});

/** @param  array<string, mixed>  $attrs */
function libFile(int $site, string $name, array $attrs = []): Media
{
    return app(Tenancy::class)->enter($site, fn () => Media::query()->create(array_merge([
        'collection' => 'library',
        'slug' => pathinfo($name, PATHINFO_FILENAME),
        'hash' => hash('sha256', $site.$name),
        'storage_key' => "s{$site}/library/{$name}",
        'original_filename' => $name,
        'mime' => str_ends_with($name, '.pdf') ? 'application/pdf' : 'image/png',
        'size' => 2048,
        'width' => 800,
        'height' => 600,
    ], $attrs)));
}

function libFolder(int $site, string $name, string $slug): MediaFolder
{
    return app(Tenancy::class)->enter($site, fn () => MediaFolder::query()->create(['name' => $name, 'slug' => $slug, 'position' => 1]));
}

/** @param  list<string>  $rights */
function libUser(array $rights): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', $rights));

    return $user->fresh();
}

it('shows the board: title, folders, files, and no bigint id in the page', function (): void {
    $user = libUser(['media.view', 'media.upload', 'media.delete']);
    libFolder(1, 'Ảnh xưởng', 'anh-xuong');
    $file = libFile(1, 'may-phay.png');

    $html = $this->actingAs($user)->get('http://a.test/admin/media')->assertOk()
        ->assertSee('Thư viện ảnh &amp; tệp', false)
        ->assertSee('Ảnh xưởng')
        ->assertSee('Chưa phân loại')
        ->assertSee('may-phay')
        ->assertSee($file->ulid)
        ->getContent();

    expect($html)->not->toMatch('/(openDetail|togglePick|setFolder|openPickerDetail|togglePickerItem)\(\s*\d+\s*\)/')
        ->and($html)->not->toContain('"pickerDetailId":'.$file->id);
});

it('never lists, picks, opens or moves into another site', function (): void {
    $user = libUser(['media.view', 'media.upload']);
    $mine = libFile(1, 'cua-toi.png');
    $theirs = libFile(2, 'cua-site-b.png');
    libFolder(2, 'Site B', 'site-b');

    app(Tenancy::class)->enter(1, function () use ($user, $mine, $theirs): void {
        Livewire::actingAs($user)->test(Library::class)
            ->assertSee('cua-toi')
            ->assertDontSee('cua-site-b')
            ->call('togglePick', $theirs->ulid)
            ->assertSet('picked', [])
            ->call('openDetail', $theirs->ulid)
            ->assertSet('pickerDetailId', null)
            ->call('togglePick', $mine->ulid)
            ->call('openMove')
            ->set('moveTo', 'site-b')
            ->call('confirmMove');

        expect(Media::query()->find($mine->id)->folder_id)->toBeNull();
    });
    expect(app(Tenancy::class)->enter(2, fn () => Media::query()->find($theirs->id)?->folder_id))->toBeNull();
});

it('lets media.view only look: every change is refused', function (string $method, array $args): void {
    $user = libUser(['media.view']);
    libFile(1, 'xem.png');

    app(Tenancy::class)->enter(1, function () use ($user, $method, $args): void {
        Livewire::actingAs($user)->test(Library::class)->call($method, ...$args)->assertForbidden();
    });
})->with([
    'pick' => ['togglePick', ['01jabcdefghjkmnpqrstvwxyz0']],
    'pick page' => ['togglePage', [[]]],
    'upload' => ['openUpload', []],
    'new folder' => ['createFolder', []],
    'move' => ['confirmMove', []],
    'trash' => ['trashPicked', []],
    'restore' => ['restorePicked', []],
    'purge' => ['confirmPurge', []],
    'undo' => ['undo', []],
]);

it('keeps the Trash closed to someone without media.delete', function (): void {
    $user = libUser(['media.view', 'media.upload']);
    $gone = libFile(1, 'da-xoa.png');
    app(Tenancy::class)->enter(1, fn () => $gone->delete());

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->set('status', 'trash')
            ->assertSet('status', 'live')
            ->call('setFolder', '~trash')
            ->assertSet('status', 'live')
            ->assertDontSee('da-xoa');
    });
});

it('creates a folder, moves files into it and lists folder and unfiled files apart', function (): void {
    $user = libUser(['media.view', 'media.upload']);
    $a = libFile(1, 'a.png');
    libFile(1, 'b.png');

    app(Tenancy::class)->enter(1, function () use ($user, $a): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('createFolder')
            ->assertHasErrors('newFolderName')
            ->set('newFolderName', 'Ảnh công trình')
            ->call('createFolder')
            ->assertHasNoErrors()
            ->assertSet('folder', 'anh-cong-trinh');

        $page->set('newFolderName', 'ảnh công trình')->call('createFolder')->assertHasErrors('newFolderName');

        $page->call('setFolder', '')
            ->call('togglePick', $a->ulid)
            ->call('openMove')
            ->set('moveTo', 'anh-cong-trinh')
            ->call('confirmMove')
            ->assertSet('picked', [])
            ->call('setFolder', 'anh-cong-trinh')
            ->assertSee('a.png')
            ->assertDontSee('b.png')
            ->call('setFolder', '-')
            ->assertSee('b.png')
            ->assertDontSee('a.png');
    });
});

it('sends to the Trash with undo, restores and purges for good', function (): void {
    $user = libUser(['media.view', 'media.delete']);
    $a = libFile(1, 'a.png');
    $b = libFile(1, 'b.png');

    app(Tenancy::class)->enter(1, function () use ($user, $a, $b): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->call('togglePick', $a->ulid)
            ->call('togglePick', $b->ulid)
            ->call('trashPicked')
            ->assertSet('askTrashOpen', false);
        expect(Media::query()->count())->toBe(0);

        $page->call('undo');
        expect(Media::query()->count())->toBe(2);

        $page->call('togglePick', $a->ulid)->call('trashPicked')
            ->call('setFolder', '~trash')
            ->assertSet('status', 'trash')
            ->assertSee('a.png')
            ->assertSee(__('media.lib.days_left', ['n' => 30]))
            ->call('togglePick', $a->ulid)
            ->call('restorePicked');
        expect(Media::query()->count())->toBe(2);

        $page->call('setFolder', '')->call('togglePick', $b->ulid)->call('trashPicked')
            ->call('setFolder', '~trash')
            ->call('askPurge', true)
            ->assertSet('askPurgeOpen', true)
            ->call('confirmPurge');
        expect(Media::withTrashed()->count())->toBe(1);
    });
});

it('filters by kind, format and alt text, and says how many match', function (): void {
    $user = libUser(['media.view']);
    libFile(1, 'bang-gia.pdf', ['width' => null, 'height' => null]);
    libFile(1, 'anh-xuong.png', ['alt' => ['vi' => 'Máy phay CNC trong xưởng']]);
    libFile(1, 'logo.webp', ['mime' => 'image/webp']);

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Livewire::actingAs($user)->test(Library::class)
            ->set('kinds', ['pdf'])
            ->assertSee('bang-gia')->assertDontSee('anh-xuong')
            ->assertSee(__('media.lib.match_of', ['n' => 1, 'm' => 3]))
            ->call('clearFilters')
            ->set('formats', ['webp'])
            ->assertSee('logo')->assertDontSee('bang-gia')
            ->call('clearFilters')
            ->set('search', 'phay cnc')
            ->assertSee('anh-xuong')->assertDontSee('logo')
            ->set('search', 'khong-co')
            ->assertSee(__('media.lib.nomatch_title'));
    });
});

it('reads page by page from a ulid cursor, forward and back', function (): void {
    $user = libUser(['media.view']);
    foreach (range(1, 30) as $i) {
        libFile(1, sprintf('tep-%02d.png', $i), ['created_at' => now()->subMinutes(100 - $i)]);
    }

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Livewire::actingAs($user)->test(Library::class)
            ->assertSee('tep-30')->assertSee('tep-07')->assertDontSee('tep-06')
            ->assertSee(__('media.lib.foot_range', ['from' => 1, 'to' => 24, 'n' => 30]));

        $older = Media::query()->where('original_filename', 'tep-07.png')->value('ulid');
        $page->call('older', $older)
            ->assertSee('tep-06')->assertSee('tep-01')->assertDontSee('tep-07')
            ->assertSee(__('media.lib.foot_range', ['from' => 25, 'to' => 30, 'n' => 30]));

        $newer = Media::query()->where('original_filename', 'tep-06.png')->value('ulid');
        $page->call('newer', $newer)->assertSee('tep-07')->assertSee('tep-30')->assertDontSee('tep-06');

        $page->call('setSort', 'az')->assertSee('tep-01')->assertDontSee('tep-25');
    });
});

it('reads the library on a flat number of queries', function (): void {
    $user = libUser(['media.view', 'media.upload', 'media.delete']);
    libFolder(1, 'Một', 'mot');
    libFile(1, 'dau.png');
    $this->actingAs($user)->get('http://a.test/admin/media')->assertOk(); // warm the per-process caches first
    $one = countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/media')->assertOk());

    foreach (range(1, 20) as $i) {
        libFile(1, "them-{$i}.png");
    }
    $many = countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/media')->assertOk());

    expect($many)->toBe($one);
});

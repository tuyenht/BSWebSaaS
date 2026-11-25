<?php

use App\Modules\Admin\Livewire\Settings\Redirects;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Chuyển hướng» (Cài đặt › Tiếp thị & Kết nối): the site's redirects in three groups (in use, with
 * a warning, Trash), filtered by language, source and kind, searched by address and paged with
 * Trước/Sau. A row is added or edited in a dialog that accepts only addresses of this website, moved
 * to the Trash with an undo, restored, or deleted for good from the Trash. settings.manage-gated:
 * without it the screen shows a card and every action answers 403. Another site's rows are never
 * reached, a row in the Trash no longer redirects and no longer holds its address.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

afterEach(function (): void {
    Livewire::flushState();
});

/** A redirect of $site written as the screen or RouteSync would. */
function rdRow(int $site, string $from, string $to, array $more = []): Redirect
{
    return app(Tenancy::class)->enter($site, fn (): Redirect => Redirect::query()->create([
        'locale' => 'vi', 'from_path' => $from, 'to_path' => $to, 'status_code' => 301, 'source' => Redirect::SOURCE_MANUAL, ...$more,
    ]));
}

/** @return array{from: string, to: string, code: int, source: string, trashed: bool}|null a row as stored, read past RLS */
function rdStored(int $site, string $from): ?array
{
    $row = DB::connection('pgsql_admin')->table('redirects')->where('site_id', $site)->where('from_path', $from)->orderByDesc('id')->first();

    return $row === null ? null : [
        'from' => (string) $row->from_path, 'to' => (string) $row->to_path, 'code' => (int) $row->status_code,
        'source' => (string) $row->source, 'trashed' => $row->deleted_at !== null,
    ];
}

/** Run $step inside the site, as a request of its admin plane would (a nested enter would end the outer one). */
function rdIn(Closure $step, int $site = 1): mixed
{
    return app(Tenancy::class)->enter($site, $step);
}

/** The screen as $user sees it; call it and every action on it inside rdIn(). */
function rdScreen(User $user): mixed
{
    return Livewire::actingAs($user)->test(Redirects::class);
}

/** A row of the current site (inside rdIn()). */
function rdMake(string $from, string $to, array $more = []): Redirect
{
    return Redirect::query()->create(['locale' => 'vi', 'from_path' => $from, 'to_path' => $to, 'status_code' => 301, 'source' => Redirect::SOURCE_MANUAL, ...$more]);
}

it('lets the roles that manage settings in, and shows the others a card while refusing every action', function (string $role, bool $allowed): void {
    $user = blockLibraryUser(1, $role);
    rdRow(1, '/cu', '/moi');

    app(Tenancy::class)->enter(1, function () use ($user, $allowed): void {
        $page = Livewire::actingAs($user)->test(Redirects::class)->assertOk();
        if ($allowed) {
            $page->assertSee('/cu')->assertSee('/moi')->assertDontSee('Bạn chưa có quyền sửa cài đặt');

            return;
        }
        $page->assertSee('Bạn chưa có quyền sửa cài đặt')->assertDontSee('/moi');
        $ulid = (string) Redirect::query()->value('ulid');
        foreach ([['openAdd'], ['openEdit', $ulid], ['save'], ['trashOne', $ulid], ['trashPicked'], ['undoTrash'],
            ['restoreOne', $ulid], ['restorePicked'], ['switchCode', $ulid], ['makePickedPermanent'], ['askPurge', $ulid],
            ['purgeNow'], ['setSort', 'az'], ['clearFilters'], ['togglePick', [$ulid]], ['older', 'x'], ['newer', 'x']] as $call) {
            Livewire::actingAs($user)->test(Redirects::class)->call(...$call)->assertForbidden();
        }
    });

    expect(rdStored(1, '/cu'))->toMatchArray(['to' => '/moi', 'trashed' => false]);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'editor' => ['editor', false],
    'author' => ['author', false],
    'contributor' => ['contributor', false],
    'viewer' => ['viewer', false],
]);

it('opens over HTTP for an admin', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    rdRow(1, '/cu', '/moi');

    $this->actingAs($admin)->get('http://a.test/admin/settings/redirects')->assertOk()->assertSee('Chuyển hướng')->assertSee('/cu');
});

it('opens inside the settings frame, its add button ending the frame\'s title row', function (): void {
    $admin = blockLibraryUser(1, 'admin');

    $html = (string) $this->actingAs($admin)->get('http://a.test/admin/settings/redirects')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $shell = $dom->querySelector('[data-set-shell]');

    expect($shell)->not->toBeNull()
        ->and($dom->querySelectorAll('h1')->length)->toBe(1)
        ->and(trim((string) $shell->querySelector('nav[data-set-nav] a[aria-current="page"]')?->textContent))->toBe('Chuyển hướng')
        // one add button, on the title row and not in the section below it
        ->and($dom->querySelectorAll('#rd-add')->length)->toBe(1)
        ->and($shell->querySelector('[data-set-main] #rd-add'))->toBeNull()
        // the frame's short description replaces the page's own heading block; no save bar (nothing to save)
        ->and($html)->toContain('Đưa khách từ địa chỉ cũ sang địa chỉ mới.')
        ->and($shell->querySelector('[data-set-savebar]'))->toBeNull()
        // the list's own frame sits 12px inside the section card, as the board draws it
        ->and(explode(' ', (string) $shell->querySelector('[data-set-main] > [data-set-card]')?->getAttribute('class')))->toContain('p-3')->not->toContain('p-[1.375rem]');
});

it('never shows nor touches another website’s redirects', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    rdRow(1, '/cua-a', '/moi-a');
    $theirs = rdRow(2, '/cua-b', '/moi-b');

    rdIn(fn () => rdScreen($admin)->assertSee('/cua-a')->assertDontSee('/cua-b')
        ->call('openEdit', $theirs->ulid)->assertSet('edOpen', false)->assertSet('toast', __('admin.rd.gone'))
        ->call('trashOne', $theirs->ulid)->assertSet('toast', __('admin.rd.gone'))
        ->call('switchCode', $theirs->ulid)
        ->call('togglePick', [$theirs->ulid])->call('trashPicked'));

    expect(rdStored(2, '/cua-b'))->toBe(['from' => '/cua-b', 'to' => '/moi-b', 'code' => 301, 'source' => 'manual', 'trashed' => false]);
});

it('adds a redirect from what was typed or pasted, kept as the storefront looks it up', function (): void {
    $admin = blockLibraryUser(1, 'admin');

    rdIn(fn () => ($page = rdScreen($admin))->call('openAdd')->assertSet('edOpen', true)
        ->set('edFrom', 'https://'.$page->get('host').'/Trang Cu/?utm=1#top')
        ->set('edTo', 'WWW.'.$page->get('host').'/gioi-thieu')
        ->call('setEdCode', '302')
        ->call('save')->assertHasNoErrors()->assertSet('edOpen', false)
        ->assertSet('toast', __('admin.rd.toast_added', ['from' => '/trang-cu', 'to' => '/gioi-thieu'])));

    expect(rdStored(1, '/trang-cu'))->toBe(['from' => '/trang-cu', 'to' => '/gioi-thieu', 'code' => 302, 'source' => 'manual', 'trashed' => false]);
});

it('says what is wrong with each field, as it is left and on save', function (string $from, string $to, string $field, string $message): void {
    $admin = blockLibraryUser(1, 'admin');
    rdRow(1, '/da-co', '/noi-khac');
    rdRow(1, '/b', '/a');

    rdIn(function () use ($admin, $from, $to, $field, $message): void {
        $page = rdScreen($admin)->call('openAdd')->set('edFrom', $from)->set('edTo', $to)
            ->call('save')->assertHasErrors(['ed'.ucfirst($field)])->assertSet('edOpen', true);

        expect($page->errors()->get('ed'.ucfirst($field)))->toBe([__($message, ['to' => '/noi-khac', 'chain' => '/a → /b → /a'])])
            ->and(Redirect::query()->count())->toBe(2);
    });
})->with([
    'no old address' => ['', '/moi', 'from', 'admin.rd.err_from_empty'],
    'no new address' => ['/cu', '   ', 'to', 'admin.rd.err_to_empty'],
    'old address of another website' => ['https://evil.example/cu', '/moi', 'from', 'admin.rd.err_from_site'],
    'new address on another website' => ['/cu', 'https://evil.example/x', 'to', 'admin.rd.err_to_site'],
    'protocol-relative new address' => ['/cu', '//evil.example/x', 'to', 'admin.rd.err_to_site'],
    'script address' => ['/cu', 'javascript:alert(1)', 'to', 'admin.rd.err_to_site'],
    'the same address' => ['/Cu/', '/cu', 'to', 'admin.rd.err_same'],
    'an old address in use' => ['/da-co/', '/moi', 'from', 'admin.rd.err_taken'],
    'a loop' => ['/a', '/b', 'to', 'admin.rd.err_loop'],
]);

it('checks a field as it is left', function (): void {
    $admin = blockLibraryUser(1, 'admin');

    rdIn(fn () => rdScreen($admin)->call('openAdd')
        ->set('edTo', 'https://evil.example/x')->assertHasErrors(['edTo'])->assertHasNoErrors(['edFrom'])
        ->set('edTo', '/moi')->assertHasNoErrors(['edTo']));
});

it('edits a row, says which content an automatic one came from, and lets its own old address stand', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    $entry = app(Tenancy::class)->enter(1, function (): Entry {
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Khai trương xưởng', 'slug' => 'khai-truong', 'status' => 'published', 'body' => ['blocks' => []]]);

        return $entry;
    });
    $row = rdRow(1, '/tin-cu', '/khai-truong', ['entry_id' => $entry->id, 'source' => Redirect::SOURCE_AUTO]);

    rdIn(fn () => rdScreen($admin)
        ->assertSee(__('admin.rd.src_auto_for', ['what' => __('admin.rd.auto_for_post', ['name' => 'Khai trương xưởng'])]))
        ->call('openEdit', $row->ulid)->assertSet('edOpen', true)->assertSet('edAuto', true)
        ->assertSet('edAutoFor', __('admin.rd.auto_for_post', ['name' => 'Khai trương xưởng']))
        ->set('edTo', '/tin-tuc/khai-truong')->call('setEdCode', '302')
        ->call('save')->assertHasNoErrors()->assertSet('toast', __('admin.rd.toast_saved', ['from' => '/tin-cu'])));

    // made by the system still, but the new destination is not known to be that post any more
    expect(rdStored(1, '/tin-cu'))->toMatchArray(['to' => '/tin-tuc/khai-truong', 'code' => 302, 'source' => 'auto'])
        ->and(DB::connection('pgsql_admin')->table('redirects')->where('ulid', $row->ulid)->value('entry_id'))->toBeNull();
    rdIn(fn () => rdScreen($admin)->assertSee(__('admin.rd.src_auto'))
        ->assertDontSee(__('admin.rd.auto_for_post', ['name' => 'Khai trương xưởng'])));
});

it('moves a row to the Trash with an undo, and a row in the Trash no longer redirects', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    seedHomepage(1);
    $row = rdRow(1, '/cu', '/moi');

    $this->get('http://a.test/cu')->assertStatus(301)->assertRedirect('http://a.test/moi');

    $page = rdIn(fn () => rdScreen($admin)->call('trashOne', $row->ulid)
        ->assertSet('toast', __('admin.rd.toast_trashed', ['from' => '/cu']))->assertDontSee('/moi'));
    expect(rdStored(1, '/cu')['trashed'])->toBeTrue();
    $this->get('http://a.test/cu')->assertNotFound();

    rdIn(fn () => $page->call('undoTrash')->assertSet('toast', __('admin.rd.toast_undone'))->assertSee('/moi'));
    expect(rdStored(1, '/cu')['trashed'])->toBeFalse();
    $this->get('http://a.test/cu')->assertStatus(301);
});

it('lets an old address in the Trash be added again, and keeps the trashed one there when it is taken', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    $old = rdRow(1, '/cu', '/moi');

    rdIn(function () use ($admin, $old): void {
        $page = rdScreen($admin)->call('trashOne', $old->ulid)
            ->call('openAdd')->set('edFrom', '/cu')->set('edTo', '/moi-hon')->call('save')->assertHasNoErrors();

        $page->set('tab', 'trash')->assertSee('/moi')
            ->call('restoreOne', $old->ulid)->assertSet('toast', __('admin.rd.restore_blocked', ['n' => 1]))->assertSet('toastTone', 'error');

        $rows = Redirect::withTrashed()->orderBy('id')->get(['to_path', 'deleted_at'])
            ->map(fn (Redirect $r): array => [$r->to_path, $r->deleted_at !== null])->all();
        expect($rows)->toBe([['/moi', true], ['/moi-hon', false]]);

        // once the one in use is gone, the trashed one comes back
        $live = (string) Redirect::query()->value('ulid');
        $page->set('tab', 'live')->call('trashOne', $live)
            ->set('tab', 'trash')->call('restoreOne', $old->ulid)->assertSet('toast', __('admin.rd.toast_restored', ['n' => 1]));
        expect(Redirect::query()->value('to_path'))->toBe('/moi');
    });
    expect(rdStored(1, '/cu'))->toMatchArray(['to' => '/moi-hon', 'trashed' => true]);
});

it('deletes a row for good only from the Trash, after asking', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    $row = rdRow(1, '/cu', '/moi');

    rdIn(function () use ($admin, $row): void {
        // a row in use cannot be deleted for good
        rdScreen($admin)->call('askPurge', $row->ulid)->assertSet('purgeOpen', false);

        rdScreen($admin)->call('trashOne', $row->ulid)->set('tab', 'trash')
            ->call('askPurge', $row->ulid)->assertSet('purgeOpen', true)->assertSet('purgingPath', '/cu')
            ->call('purgeNow')->assertSet('purgeOpen', false)->assertSet('toast', __('admin.rd.toast_purged'));
    });

    expect(rdStored(1, '/cu'))->toBeNull();
});

it('switches the kind of one row or of the picked temporary ones', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    $a = rdRow(1, '/a', '/x');
    $b = rdRow(1, '/b', '/x', ['status_code' => 302]);
    $c = rdRow(1, '/c', '/x', ['status_code' => 302]);

    rdIn(fn () => rdScreen($admin)->call('switchCode', $a->ulid)->assertSet('toast', __('admin.rd.toast_kind', ['from' => '/a']))
        ->call('togglePick', [$b->ulid, $c->ulid])->call('makePickedPermanent')
        ->assertSet('toast', __('admin.rd.toast_kind_n', ['n' => 2])));

    expect([rdStored(1, '/a')['code'], rdStored(1, '/b')['code'], rdStored(1, '/c')['code']])->toBe([302, 301, 301]);
});

it('filters by source, language and kind, searches both addresses, and counts each group', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    rdRow(1, '/tu-them', '/dich-mot');
    rdRow(1, '/tu-dong', '/dich-hai', ['source' => Redirect::SOURCE_AUTO, 'status_code' => 302]);
    rdRow(1, '/vong', '/vong-lai');
    rdRow(1, '/vong-lai', '/vong');

    rdIn(function () use ($admin): void {
        $page = rdScreen($admin);
        expect($page->viewData('counts')['tabs'])->toBe(['live' => 4, 'warn' => 2, 'trash' => 0]);

        $page->set('sources', ['auto'])->assertSee('/tu-dong')->assertDontSee('/tu-them')
            ->set('sources', [])->set('codes', ['301'])->assertSee('/tu-them')->assertDontSee('/tu-dong')
            ->set('codes', [])->set('search', 'DICH-HAI')->assertSee('/tu-dong')->assertDontSee('/tu-them')
            ->call('clearSearch')->set('tab', 'warn')->assertSee('/vong-lai')->assertDontSee('/tu-them');
    });
});

it('pages with Trước/Sau through rows of the same time, each once, by the id tie-break', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    foreach (range(1, 25) as $i) {
        rdRow(1, sprintf('/cu-%02d', $i), '/moi');
    }
    DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->update(['created_at' => '2026-11-01 08:00:00+00']);

    rdIn(function () use ($admin): void {
        $page = rdScreen($admin)->set('per', 10);
        $seen = [];
        $pages = [];
        do {
            $paths = array_column($page->viewData('rows'), 'from');
            $pages[] = $paths;
            $seen = [...$seen, ...$paths];
            $older = $page->viewData('older');
            if ($older !== null) {
                $page->call('older', $older);
            }
        } while ($older !== null && count($pages) < 5);

        $expected = array_map(fn (int $i): string => sprintf('/cu-%02d', $i), range(25, 1));
        expect($seen)->toBe($expected)
            ->and(array_map('count', $pages))->toBe([10, 10, 5])
            ->and($page->viewData('page'))->toBe(3);

        $page->call('newer', $page->viewData('newer'));
        expect(array_column($page->viewData('rows'), 'from'))->toBe($pages[1]);

        // a cursor of another list (or made up) starts again from the first page
        $page->set('cursor', 'nonsense');
        expect(array_column($page->viewData('rows'), 'from'))->toBe($pages[0]);
    });
});

it('lists a page of redirects on a flat query budget, whatever they lead to', function (): void {
    $admin = blockLibraryUser(1, 'admin');
    $seed = function (int $from, int $to): void {
        rdIn(function () use ($from, $to): void {
            foreach (range($from, $to) as $i) {
                $entry = Entry::query()->create(['type' => $i % 2 === 0 ? 'post' : 'page', 'status' => 'published', 'published_at' => now()]);
                $entry->translations()->create(['locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'published', 'body' => ['blocks' => []]]);
                $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => null, 'position' => $i]);
                $category->translations()->create(['locale' => 'vi', 'name' => "Mục {$i}", 'slug' => "muc-{$i}"]);
                Redirect::query()->create(['locale' => 'vi', 'from_path' => "/bai-cu-{$i}", 'to_path' => "/bai-{$i}", 'status_code' => 301, 'source' => 'auto', 'entry_id' => $entry->id]);
                Redirect::query()->create(['locale' => 'vi', 'from_path' => "/muc-cu-{$i}", 'to_path' => "/muc-{$i}", 'status_code' => 301, 'source' => 'auto', 'taxonomy_id' => $category->id]);
                Redirect::query()->create(['locale' => 'vi', 'from_path' => "/tay-{$i}", 'to_path' => "/bai-{$i}", 'status_code' => 302, 'source' => 'manual']);
            }
        });
    };
    $render = fn (): int => rdIn(fn (): int => countQueries(fn () => rdScreen($admin)->assertOk()));

    $seed(1, 2);
    $render(); // the permissions, languages and settings the process keeps after its first request
    $few = $render();
    $seed(3, 8);
    $many = $render();

    // 11: the page, its counts and its names, plus the website's switch for redirects to another website,
    // plus the settings menu's read of whether Email and Tích hợp are set up (its «Chưa cấu hình» dot; measured +1 here)
    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(11);
});

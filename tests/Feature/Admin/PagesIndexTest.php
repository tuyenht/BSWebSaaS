<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;

/*
 * P3 — the Pages list (design §3a). Status tabs + counts, name/slug search, sortable columns
 * that keep the parent/child tree intact, and the Trash view — all server-side, site-scoped by
 * BelongsToSite + RLS, gated by EntryPolicy::viewAny, at a flat query budget (no N+1).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function makePageEntry(User $author, string $title, string $slug, string $status = 'published', ?int $parentId = null): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $author->id, 'parent_id' => $parentId]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $entry;
}

it('lists pages for a user who can view entries', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        makePageEntry($user, 'Trang chủ', 'home');
        makePageEntry($user, 'Giới thiệu', 'gioi-thieu');

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->assertOk()
            ->assertSee('Trang chủ')
            ->assertSee('Giới thiệu');
    });
});

it('denies viewing without the entries.view permission', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'nobody', []);

        Livewire::actingAs($user->fresh())->test(Index::class)->assertForbidden();
    });
});

it('filters by the status tab', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        makePageEntry($user, 'Bài đã đăng', 'a', 'published');
        makePageEntry($user, 'Bản nháp của tôi', 'b', 'draft');

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->call('setTab', 'draft')
            ->assertSee('Bản nháp của tôi')
            ->assertDontSee('Bài đã đăng');
    });
});

it('searches by title', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        makePageEntry($user, 'Liên hệ', 'lien-he');
        makePageEntry($user, 'Giới thiệu', 'gioi-thieu');

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->set('search', 'liên')
            ->assertSee('Liên hệ')
            ->assertDontSee('Giới thiệu');
    });
});

it('keeps a child directly under its parent even when it would sort first', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $parent = makePageEntry($user, 'Zeta', 'zeta');
        makePageEntry($user, 'Alpha child', 'alpha-child', 'published', $parent->id);
        makePageEntry($user, 'Beta', 'beta');

        // Sorted by name asc, a detached 'Alpha child' would lead; as a child it must ride under Zeta.
        Livewire::actingAs($user->fresh())->test(Index::class)
            ->call('sortBy', 'name')
            ->assertSeeInOrder(['Beta', 'Zeta', 'Alpha child']);
    });
});

it('shows soft-deleted pages only in the trash tab', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $trashed = makePageEntry($user, 'Đã xoá rồi', 'da-xoa');
        makePageEntry($user, 'Còn sống', 'con-song');
        $trashed->delete();

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->assertSee('Còn sống')
            ->assertDontSee('Đã xoá rồi')
            ->call('setTab', 'trash')
            ->assertSee('Đã xoá rồi')
            ->assertDontSee('Còn sống');
    });
});

it('renders at a flat query budget regardless of row count', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        Livewire::actingAs($user->fresh())->test(Index::class); // warm permission/config caches

        foreach (range(1, 4) as $i) {
            makePageEntry($user, "Trang {$i}", "trang-{$i}");
        }
        $few = countQueries(fn () => Livewire::actingAs($user->fresh())->test(Index::class));

        foreach (range(5, 18) as $i) {
            makePageEntry($user, "Trang {$i}", "trang-{$i}");
        }
        $many = countQueries(fn () => Livewire::actingAs($user->fresh())->test(Index::class));

        expect($many)->toBeLessThanOrEqual($few); // no per-row queries → count does not grow
    });
});

it('renders the language column with the site content-locales', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);

        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $user->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Chỉ tiếng Việt', 'slug' => 'vi-only', 'status' => 'published', 'body' => ['blocks' => []]]);

        // The column header + both content-locale chips render (vi has content, en is missing).
        Livewire::actingAs($user->fresh())->test(Index::class)
            ->assertSee(__('entries.col_language'))
            ->assertSeeInOrder(['VI', 'EN']);
    });
});

it('filters pages by content-locale', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);

        makePageEntry($user, 'Chỉ Việt', 'chi-viet'); // vi only
        $bilingual = makePageEntry($user, 'Song ngữ', 'song-ngu');
        $bilingual->translations()->create(['locale' => 'en', 'title' => 'Bilingual', 'slug' => 'bilingual', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->call('toggleFilterValue', 'lang', 'en')
            ->assertSee('Song ngữ')
            ->assertDontSee('Chỉ Việt');
    });
});

it('filters pages by author (using the ulid, never the raw id)', function (): void {
    $ngoc = User::factory()->create(['name' => 'Ngọc Anh']);
    $minh = User::factory()->create(['name' => 'Minh Trí']);

    app(Tenancy::class)->enter(1, function () use ($ngoc, $minh): void {
        grantRole($ngoc, 'editor', ['entries.view']);
        makePageEntry($ngoc, 'Của Ngọc Anh', 'na');
        makePageEntry($minh, 'Của Minh Trí', 'mt');

        Livewire::actingAs($ngoc->fresh())->test(Index::class)
            ->call('toggleFilterValue', 'creator', $minh->ulid)
            ->assertSee('Của Minh Trí')
            ->assertDontSee('Của Ngọc Anh');
    });
});

it('clears all filters and restores the full list', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
        makePageEntry($user, 'Trang một', 'mot');
        makePageEntry($user, 'Trang hai', 'hai');

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->call('toggleFilterValue', 'lang', 'en') // no page has en → both filtered out
            ->assertDontSee('Trang một')
            ->call('clearAllFilters')
            ->assertSee('Trang một')
            ->assertSee('Trang hai');
    });
});

it('changes a row status to published through the workflow', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $page = makePageEntry($editor, 'Nháp', 'nhap', 'draft');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('changeStatus', $page->ulid, 'published')
            ->assertDispatched('bs-toast');

        expect($page->fresh()->status)->toBe('published');
    });
});

it('cycles rows-per-page 20 → 50 → 10 → 20 and returns to the first page', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        makePageEntry($user, 'Trang một', 'trang-mot');

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->assertSet('perPage', 20)
            ->set('page', 2)
            ->call('cyclePerPage')->assertSet('perPage', 50)->assertSet('page', 1)
            ->call('cyclePerPage')->assertSet('perPage', 10)
            ->call('cyclePerPage')->assertSet('perPage', 20);
    });
});

it('schedules a row for a future time', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $page = makePageEntry($editor, 'Hẹn giờ', 'hen-gio', 'draft');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('scheduleEntry', $page->ulid, now()->addDay()->format('Y-m-d\TH:i'))
            ->assertDispatched('bs-toast');

        expect($page->fresh()->status)->toBe('scheduled');
    });
});

it('rejects scheduling a page for a past time so the publish-due cron cannot insta-publish it', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $page = makePageEntry($editor, 'Quá khứ', 'qua-khu', 'draft');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('scheduleEntry', $page->ulid, now()->subDay()->format('Y-m-d\TH:i'))
            ->assertDispatched('bs-toast', text: __('entries.schedule_future_only'));

        expect($page->fresh()->status)->toBe('draft'); // rejected — still a draft, never scheduled
    });
});

it('duplicates a row into a fresh draft copy', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create']);
        makePageEntry($editor, 'Gốc', 'goc');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('duplicate', Entry::query()->where('type', 'page')->firstOrFail()->ulid)
            ->assertDispatched('bs-toast');

        expect(Entry::query()->where('type', 'page')->count())->toBe(2);
    });
});

it('trashes then restores a row', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $page = makePageEntry($editor, 'Xoá thử', 'xoa-thu');

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('trash', $page->ulid)
            ->assertDispatched('bs-toast');
        expect($page->fresh()->trashed())->toBeTrue();

        $component->call('restore', $page->ulid);
        expect($page->fresh()->trashed())->toBeFalse();
    });
});

it('permanently deletes a trashed row', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $page = makePageEntry($editor, 'Xoá hẳn', 'xoa-han');
        $page->delete();

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('forceDelete', $page->ulid)
            ->assertDispatched('bs-toast');

        expect(Entry::withTrashed()->where('ulid', $page->ulid)->exists())->toBeFalse();
    });
});

it('forbids a viewer without publish from changing a status', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'viewer', ['entries.view']);
        $page = makePageEntry($viewer, 'Chỉ xem', 'chi-xem', 'draft');

        Livewire::actingAs($viewer->fresh())->test(Index::class)
            ->call('changeStatus', $page->ulid, 'draft')
            ->assertForbidden();
    });
});

it('selects all visible rows then clears them', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view']);
        $a = makePageEntry($editor, 'A', 'a');
        $b = makePageEntry($editor, 'B', 'b');

        $component = Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('toggleSelectAll', [$a->ulid, $b->ulid]);
        expect($component->get('selected'))->toEqualCanonicalizing([$a->ulid, $b->ulid]);

        $component->call('clearSelection');
        expect($component->get('selected'))->toBe([]);
    });
});

it('bulk-trashes the selected rows and clears the selection', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = makePageEntry($editor, 'A', 'a');
        $b = makePageEntry($editor, 'B', 'b');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$a->ulid, $b->ulid])
            ->call('bulkTrash')
            ->assertDispatched('bs-toast')
            ->assertSet('selected', []);

        expect($a->fresh()->trashed())->toBeTrue()
            ->and($b->fresh()->trashed())->toBeTrue();
    });
});

it('bulk-publishes the selected rows', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $a = makePageEntry($editor, 'A', 'a', 'draft');
        $b = makePageEntry($editor, 'B', 'b', 'draft');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->set('selected', [$a->ulid, $b->ulid])
            ->call('bulkPublish')
            ->assertSet('selected', []);

        expect($a->fresh()->status)->toBe('published')
            ->and($b->fresh()->status)->toBe('published');
    });
});

it('inline-renames a page title', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']);
        $page = makePageEntry($editor, 'Tên cũ', 'ten-cu');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('saveTitle', $page->ulid, 'Tên mới')
            ->assertDispatched('bs-toast');

        expect($page->translationFor('vi')->fresh()->title)->toBe('Tên mới');
    });
});

it('inline-edits a published page slug and moves its route with a 301', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $page = makePageEntry($editor, 'Giới thiệu', 'gioi-thieu', 'draft');
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('saveSlug', $page->ulid, 'Về chúng tôi');

        expect($page->translationFor('vi')->fresh()->slug)->toBe('ve-chung-toi')
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/ve-chung-toi')
            ->and(Redirect::query()->where('from_path', '/gioi-thieu')->where('to_path', '/ve-chung-toi')->exists())->toBeTrue();
    });
});

it('locks the home page slug against inline edits', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']);
        $home = makePageEntry($editor, 'Trang chủ', 'home');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('saveSlug', $home->ulid, 'khong-cho-doi');

        expect($home->translationFor('vi')->fresh()->slug)->toBe('home'); // unchanged
    });
});

it('slugifies a Vietnamese slug server-side', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']);
        $page = makePageEntry($editor, 'Trang', 'trang', 'draft');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('saveSlug', $page->ulid, 'Đặt lịch hẹn');

        expect($page->translationFor('vi')->fresh()->slug)->toBe('dat-lich-hen');
    });
});

it('clamps an out-of-range per_page back to the default on a live update', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        makePageEntry($user, 'Trang một', 'trang-mot');

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->set('page', 3)
            ->set('perPage', 0)
            ->assertSet('perPage', 20) // 0 (and any value outside 10/20/50) resets to the default
            ->assertSet('page', 1); // …and strands nobody on a now-invalid page number

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->set('perPage', 999999)
            ->assertSet('perPage', 20);
    });
});

it('does not degenerate when the URL supplies a zero per_page', function (): void {
    // perPage is a #[Url] property, so `?per_page=0` sets it directly during mount — a path
    // updatedPerPage() never sees (that hook only fires on a later property *change*). Without
    // mount()'s own clamp, paginate() divides the row total by a zero perPage and 500s.
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        makePageEntry($user, 'Trang một', 'trang-mot');
    });

    $this->actingAs($user)->get('http://a.test/admin/content?per_page=0')->assertOk();
});

it('navigates to a specific page via the footer pager', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        foreach (range(1, 25) as $i) {
            makePageEntry($user, "Trang {$i}", "trang-{$i}");
        }

        Livewire::actingAs($user->fresh())->test(Index::class)
            ->call('gotoPage', 2)
            ->assertSet('page', 2);
    });
});

<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
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

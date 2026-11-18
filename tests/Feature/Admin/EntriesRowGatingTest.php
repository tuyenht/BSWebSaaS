<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The content list offers each row only what this member may do on THAT row: the status menu on a
 * row they may edit (a publisher's moves, a sender's «Gửi duyệt» on their draft), «Sửa» and the
 * quick edit only where the editor would open, Nhân bản and Thùng rác only where the policy allows
 * them. A row outside their category limits shows «🔒 Xem» and the reason, its items locked under
 * it, and a page list gives a limited member a locked «Thêm trang». An action called on a row it
 * may not touch (a stale screen or a forged call) is answered with the reason in a toast that
 * stays — never an error page — and leaves the row as it was.
 *
 * Four members: an owner (everything), an own-only publisher (edit/delete their own, publish), an
 * editor limited to one category, and a contributor who may only send for review.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** A member of site 1 with a role of $level holding $permissions. */
function rgMember(string $role, int $level, array $permissions): User
{
    $user = User::factory()->create(['name' => Str::title($role).' '.Str::random(4)]);
    app(Tenancy::class)->enter(1, function () use ($user, $role, $level, $permissions): void {
        $granted = Role::findOrCreate($role, PermissionRegistry::GUARD);
        $granted->forceFill(['level' => $level, 'is_system' => true])->save();
        $granted->syncPermissions($permissions);
        $user->assignRole($granted);
    });
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** @return array{owner: User, own: User, limited: User, sender: User} */
function rgMembers(): array
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate');

    return [
        'owner' => rgMember('owner', 90, PermissionRegistry::TENANT_PERMISSIONS),
        'own' => rgMember('own-publisher', 40, ['entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own', 'entries.publish']),
        'limited' => rgMember('editor', 50, $template['editor']['permissions']),
        'sender' => rgMember('contributor', 20, $template['contributor']['permissions']),
    ];
}

/** The «Tin tức» section with «Sự kiện» and «Tuyển dụng» below it. @return array{0: Taxonomy, 1: Taxonomy} */
function rgSection(): array
{
    return app(Tenancy::class)->enter(1, function (): array {
        $create = app(CreateTaxonomy::class);
        $root = $create->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
        $events = $create->handle('post_cat', $root, new TaxonomyData(names: ['vi' => 'Sự kiện']));
        $jobs = $create->handle('post_cat', $root, new TaxonomyData(names: ['vi' => 'Tuyển dụng']));
        ContentSection::query()->create([
            'key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $root->id,
            'permission_prefix' => 'entries', 'sort_order' => 1,
        ]);

        return [$events, $jobs];
    });
}

function rgLimit(User $user, Taxonomy $category): void
{
    app(Tenancy::class)->enter(1, function () use ($user, $category): void {
        MemberCategoryScope::query()->create(['user_id' => $user->id, 'taxonomy_id' => $category->id]);
        app(CategoryScopes::class)->forget();
    });
}

/** A post (under $category) or a page (no category) on site 1. */
function rgEntry(User $author, string $title, string $status = 'draft', ?Taxonomy $category = null, bool $page = false): Entry
{
    return app(Tenancy::class)->enter(1, function () use ($author, $title, $status, $category, $page): Entry {
        $entry = Entry::query()->create([
            'type' => $page ? 'page' : 'post', 'status' => $status, 'author_id' => $author->id, 'meta' => [],
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'status' => $status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => []],
        ]);
        if ($category !== null) {
            $entry->assignCategories([$category->id]);
        }

        return $entry->fresh() ?? $entry;
    });
}

function rgStatus(Entry $entry): ?string
{
    return app(Tenancy::class)->enter(1, fn (): ?string => Entry::withTrashed()->whereKey($entry->id)->value('status'));
}

function rgTrashed(Entry $entry): bool
{
    return app(Tenancy::class)->enter(1, fn (): bool => Entry::onlyTrashed()->whereKey($entry->id)->exists());
}

/** The posts list of «Tin tức» as $who sees it. */
function rgPosts(User $who): mixed
{
    return Livewire::actingAs($who)->test(Index::class, ['section' => 'tin-tuc']);
}

it('offers a full-access owner every row action on every row', function (): void {
    ['owner' => $owner, 'own' => $colleague] = rgMembers();
    [$events, $jobs] = rgSection();
    $theirs = rgEntry($colleague, 'Bài của đồng nghiệp', 'draft', $events);
    $mine = rgEntry($owner, 'Bài của chủ', 'published', $jobs);

    app(Tenancy::class)->enter(1, function () use ($owner, $theirs, $mine): void {
        $list = rgPosts($owner)->assertOk();
        foreach ([$theirs, $mine] as $row) {
            expect($list->viewData('rowCan')[$row->ulid])->toMatchArray(['publish' => true, 'send' => false, 'delete' => true, 'duplicate' => true, 'out' => null])
                ->and($list->viewData('rowEditable')[$row->ulid])->toBeTrue();
        }
        $list->assertSee(route('admin.entries.edit', ['ulid' => $theirs->ulid]), false)
            ->assertSee(__('entries.add_post'))
            ->assertSee(__('entries.status_menu_label'));
        expect($list->html())->toContain("changeStatus('{$theirs->ulid}', 'published')")
            ->and($list->html())->not->toContain("'pending')");

        $list->call('trash', $theirs->ulid)->assertOk()->assertSet('toastMsg', __('entries.trashed_one', ['name' => 'Bài của đồng nghiệp']));
    });

    expect(rgTrashed($theirs))->toBeTrue();
});

it('gives an own-only member their own rows and only a look at a colleague\'s', function (): void {
    ['own' => $me, 'sender' => $colleague] = rgMembers();
    [$events] = rgSection();
    $mine = rgEntry($me, 'Bài của tôi', 'draft', $events);
    $theirs = rgEntry($colleague, 'Bài của người khác', 'published', $events);

    app(Tenancy::class)->enter(1, function () use ($me, $mine, $theirs): void {
        // an own-only member opens on «Của tôi»: everyone's rows, to see the colleague's
        $list = rgPosts($me)->call('resetFilterGroup', 'creator')->assertOk();
        expect($list->viewData('rowCan')[$mine->ulid])->toMatchArray(['publish' => true, 'delete' => true, 'duplicate' => true, 'out' => null])
            ->and($list->viewData('rowCan')[$theirs->ulid])->toMatchArray(['publish' => false, 'send' => false, 'delete' => false, 'duplicate' => true, 'out' => null])
            ->and($list->viewData('rowEditable')[$theirs->ulid])->toBeFalse();

        // the colleague's row: no link into the editor (it would refuse), no Sửa, no status menu
        $html = $list->html();
        expect($html)->toContain(route('admin.entries.edit', ['ulid' => $mine->ulid]))
            ->and($html)->not->toContain(route('admin.entries.edit', ['ulid' => $theirs->ulid]))
            ->and($html)->toContain("trash('{$mine->ulid}')")
            ->and($html)->not->toContain("trash('{$theirs->ulid}')")
            ->and($html)->toContain("changeStatus('{$mine->ulid}'")
            ->and($html)->not->toContain("changeStatus('{$theirs->ulid}'")
            ->and($html)->toContain("duplicate('{$theirs->ulid}')")
            ->and(substr_count($html, 'data-guide="row-edit"'))->toBe(1);

        // a forged call on the colleague's row: the reason in a toast that stays, no error page
        $others = __('entries.refused_others_delete', ['noun' => __('entries.noun_post')]);
        $list->call('trash', $theirs->ulid)->assertOk()->assertDispatched('bs-toast', text: $others, stay: true);
        $list->call('saveTitle', $theirs->ulid, 'Tên mới')->assertOk()
            ->assertDispatched('bs-toast', text: __('entries.refused_others_edit', ['noun' => __('entries.noun_post')]), stay: true);
    });

    expect(rgTrashed($theirs))->toBeFalse()
        ->and(app(Tenancy::class)->enter(1, fn (): ?string => $theirs->fresh()?->translationFor('vi')?->title))->toBe('Bài của người khác');
});

it('locks the rows outside a limited member\'s categories with the reason, and refuses them without an error page', function (): void {
    ['limited' => $lan] = rgMembers();
    [$events, $jobs] = rgSection();
    rgLimit($lan, $events);
    $inside = rgEntry($lan, 'Bài trong phạm vi', 'draft', $events);
    $outside = rgEntry($lan, 'Bài ngoài phạm vi', 'published', $jobs);
    $why = 'Bạn chỉ được viết bài trong: Sự kiện.';

    app(Tenancy::class)->enter(1, function () use ($lan, $inside, $outside, $why): void {
        $list = rgPosts($lan)->assertOk();
        expect($list->viewData('rowCan')[$inside->ulid])->toMatchArray(['publish' => true, 'delete' => true, 'duplicate' => true, 'out' => null])
            ->and($list->viewData('rowCan')[$outside->ulid])->toMatchArray(['publish' => false, 'delete' => false, 'duplicate' => false, 'out' => $why, 'deleteRole' => true]);

        // «🔒 Xem» with the reason instead of «Sửa»; the reason under the status; the items locked under it
        $html = $list->html();
        expect(substr_count($html, 'data-guide="row-edit"'))->toBe(1)
            ->and(substr_count($html, 'data-guide="row-lock"'))->toBe(1)
            ->and($html)->toContain(e(__('entries.locked_view_live', ['why' => 'Bạn chỉ được viết bài trong: Sự kiện'])))
            ->and(substr_count($html, '<span class="list-mwhy">Bạn chỉ được viết bài trong: Sự kiện</span>'))->toBe(3)
            ->and($html)->not->toContain(route('admin.entries.edit', ['ulid' => $outside->ulid]));

        $list->call('trash', $outside->ulid)->assertOk()->assertDispatched('bs-toast', text: $why, stay: true);
        $list->call('duplicate', $outside->ulid)->assertOk()->assertDispatched('bs-toast', text: $why, stay: true);
        $list->call('changeStatus', $outside->ulid, 'draft')->assertOk()->assertDispatched('bs-toast', text: $why, stay: true);
        $list->call('saveSlug', $outside->ulid, 'duong-dan-moi')->assertOk()->assertDispatched('bs-toast', text: $why, stay: true);
    });

    expect(rgTrashed($outside))->toBeFalse()->and(rgStatus($outside))->toBe('published')
        ->and(app(Tenancy::class)->enter(1, fn (): int => Entry::query()->where('type', 'post')->count()))->toBe(2);
});

it('gives a limited member a locked «Thêm trang» and every page as «🔒 Xem»', function (): void {
    ['limited' => $lan, 'owner' => $owner] = rgMembers();
    [$events] = rgSection();
    rgLimit($lan, $events);
    $page = rgEntry($owner, 'Giới thiệu', 'published', null, true);
    $why = 'Bạn chỉ được viết bài trong: Sự kiện — không sửa được Trang.';

    app(Tenancy::class)->enter(1, function () use ($lan, $page, $why): void {
        $list = Livewire::actingAs($lan)->test(Index::class)->assertOk();
        expect($list->viewData('addLock'))->toBe($why)
            ->and($list->viewData('rowCan')[$page->ulid]['out'])->toBe($why);
        $html = $list->html();
        expect($html)->not->toContain(route('admin.entries.create'))
            ->and($html)->toContain('aria-disabled="true"')
            ->and($html)->not->toContain('data-guide="row-edit"');

        $list->call('trash', $page->ulid)->assertOk()->assertDispatched('bs-toast', text: $why, stay: true);
    });

    expect(rgTrashed($page))->toBeFalse();
});

it('shows no «Thêm» to a member without the right to create', function (): void {
    $viewer = rgMember('viewer', 10, ['entries.view']);
    rgSection();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        $html = rgPosts($viewer)->assertOk()->html();
        expect($html)->not->toContain(route('admin.entries.create', ['section' => 'tin-tuc']))
            ->and($html)->not->toContain('data-guide="add"');
    });
});

it('gives a submit-only member «Gửi duyệt» on their draft, in the status menu and the bulk bar', function (): void {
    ['sender' => $me, 'owner' => $owner] = rgMembers();
    [$events] = rgSection();
    $draft = rgEntry($me, 'Bản nháp của tôi', 'draft', $events);
    $second = rgEntry($me, 'Bản nháp thứ hai', 'draft', $events);
    $waiting = rgEntry($me, 'Đang chờ duyệt', 'pending', $events);
    $theirs = rgEntry($owner, 'Bài của chủ', 'draft', $events);

    app(Tenancy::class)->enter(1, function () use ($me, $draft, $second, $waiting, $theirs): void {
        $list = rgPosts($me)->call('resetFilterGroup', 'creator')->assertOk();
        $can = $list->viewData('rowCan');
        expect($can[$draft->ulid])->toMatchArray(['publish' => false, 'send' => true, 'delete' => false])
            ->and($can[$waiting->ulid]['send'])->toBeFalse()
            ->and($can[$theirs->ulid])->toMatchArray(['send' => false, 'delete' => false]);
        $html = $list->html();
        expect($html)->toContain("changeStatus('{$draft->ulid}', 'pending')")
            ->and($html)->not->toContain("changeStatus('{$waiting->ulid}'")
            ->and($html)->not->toContain("changeStatus('{$theirs->ulid}'");

        $list->call('changeStatus', $draft->ulid, 'pending')->assertOk()
            ->assertDispatched('bs-toast', text: __('entries.ed.toast_sent', ['unit' => __('entries.noun_post')]));

        // the bulk bar: «Gửi duyệt», which sends their drafts and skips the rest
        $list->set('selected', [$second->ulid, $theirs->ulid])
            ->assertSeeHtml('data-bulk="send"')
            ->assertDontSee(__('entries.bulk_publish'))
            ->call('bulkPublish')
            ->assertDispatched('bs-toast', text: __('entries.bulk_sent', ['count' => 1]).' '.__('entries.bulk_skipped', ['count' => 1]));

        // sending a colleague's draft, or publishing, is refused with the reason — no error page
        $list->call('changeStatus', $theirs->ulid, 'pending')->assertOk()
            ->assertDispatched('bs-toast', text: __('entries.refused_status'), stay: true);
        $list->call('changeStatus', $theirs->ulid, 'published')->assertOk()
            ->assertDispatched('bs-toast', text: __('entries.refused_status'), stay: true);
    });

    expect(rgStatus($draft))->toBe('pending')
        ->and(rgStatus($second))->toBe('pending')
        ->and(rgStatus($theirs))->toBe('draft');
});

it('lists every member type within the same query budget', function (): void {
    $members = rgMembers();
    [$events, $jobs] = rgSection();
    rgLimit($members['limited'], $events);
    foreach (range(1, 6) as $i) {
        rgEntry($members['own'], "Bài A{$i}", 'draft', $events);
        rgEntry($members['sender'], "Bài B{$i}", 'published', $jobs);
    }

    $list = function (User $who): int {
        app()->forgetScopedInstances();

        return countQueries(fn () => $this->actingAs($who)->get('http://a.test/admin/content?section=tin-tuc')->assertOk());
    };
    $counts = [];
    foreach ($members as $key => $who) {
        $list($who);                 // warm caches (permissions, preferences)
        $counts[$key] = $list($who);
    }

    // per-row answers come from what the rows carry and what is read once per request: no member
    // pays a query per row (twelve rows here), and only the limited one pays for their limits
    expect($counts['own'])->toBeLessThanOrEqual($counts['owner'] + 1)
        ->and($counts['sender'])->toBeLessThanOrEqual($counts['owner'] + 1)
        ->and($counts['limited'])->toBeLessThanOrEqual($counts['owner'] + 4);
});

it('shows a locked «Thùng rác» only to a role that could otherwise remove the row', function (): void {
    ['limited' => $lan, 'owner' => $owner] = rgMembers();
    [$events] = rgSection();
    rgLimit($lan, $events);
    $page = rgEntry($owner, 'Giới thiệu', 'published', null, true);
    $nobody = rgMember('contributor-limited', 20, ['entries.view', 'entries.create', 'entries.edit.own']);
    rgLimit($nobody, $events);

    app(Tenancy::class)->enter(1, function () use ($page, $lan, $nobody): void {
        // no delete right at all: no locked Thùng rác, on a page or a post
        expect(Livewire::actingAs($nobody)->test(Index::class)->call('resetFilterGroup', 'creator')->viewData('rowCan')[$page->ulid]['deleteRole'])->toBeFalse();
        // the editor role may delete any: its locked Thùng rác stays, with the reason
        $may = Livewire::actingAs($lan)->test(Index::class)->viewData('rowCan')[$page->ulid];
        expect($may['deleteRole'])->toBe($lan->can('entries.delete.any') || $lan->can('entries.delete.own'));
    });
});

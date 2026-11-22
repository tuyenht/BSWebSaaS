<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Admin\Support\Members\RoleAbilities;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * What a role lets its holder do, said in plain words on the members screen (design 22): the dialog
 * «Nhóm này làm được gì?» and the sentence of the roles panel and the invite dialog. Both are read
 * from the roles as stored on this website (their permissions, level, system or not) and from the
 * permission catalog — never from a copy — and only this website's roles are ever explained. Also:
 * the unsaved-changes count of the panel and the invite dialog, and the «Tải lại» offered when the
 * member being changed has left meanwhile.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
});

/** Every corporate role on $site (system roles, at their template level and permissions). */
function rxRoles(int $site = 1): void
{
    foreach (PermissionRegistry::tenantRoleTemplates('corporate') as $name => $tpl) {
        app(Tenancy::class)->enter($site, function () use ($name, $tpl): void {
            $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
            $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
            $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        });
    }
}

/** A role of the website's own (not a system role). */
function rxCustom(string $name, int $level, array $permissions, int $site = 1): Role
{
    return app(Tenancy::class)->enter($site, function () use ($name, $level, $permissions): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => false])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

/** A new member of $site holding $role (which exists there). */
function rxMember(string $role, string $name = 'Nguyễn Thị Lan', int $site = 1): User
{
    $user = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($role));

    return $user;
}

/** The role $name of $site with its permissions. */
function rxRole(string $name, int $site = 1): Role
{
    return app(Tenancy::class)->enter($site, fn (): Role => Role::query()->where('site_id', $site)->where('name', $name)->with('permissions')->firstOrFail());
}

/** @return list<Role> */
function rxSiteRoles(int $site = 1): array
{
    return app(Tenancy::class)->enter($site, fn (): array => array_values(Role::query()->where('site_id', $site)->get()->all()));
}

/** The screen as $actor sees it on site 1; $then runs in the same site context. */
function rxPage(User $actor, Closure $then): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)));
}

it('says what each seeded role can and cannot do, from its stored permissions', function (bool $catalog, string $role, string $sentence): void {
    rxRoles();

    expect(RoleAbilities::sentence('Lan', [rxRole($role)], [], $catalog))->toBe($sentence);
})->with([
    'owner' => [true, 'owner', 'Lan được: mọi việc trên website, ở mọi nơi.'],
    'admin' => [false, 'admin', 'Lan được: mọi việc trên website, ở mọi nơi.'],
    'editor, catalog on' => [true, 'editor', 'Lan được: viết, duyệt và đăng bài, quản lý chuyên mục bài viết ở mọi chuyên mục; thêm, sửa sản phẩm và chuyên mục sản phẩm ở mọi chuyên mục sản phẩm; tải ảnh lên, xóa mọi ảnh; xem và trả lời khách liên hệ, quản lý biểu mẫu; sửa trình đơn; quản lý khối tái dùng. Không được: thành viên, cài đặt.'],
    'editor, catalog off' => [false, 'editor', 'Lan được: viết, duyệt và đăng bài, quản lý chuyên mục bài viết ở mọi chuyên mục; tải ảnh lên, xóa mọi ảnh; xem và trả lời khách liên hệ, quản lý biểu mẫu; sửa trình đơn; quản lý khối tái dùng. Không được: thành viên, cài đặt.'],
    'author, catalog on' => [true, 'author', 'Lan được: viết bài và gửi duyệt ở mọi chuyên mục; tải ảnh lên, xóa ảnh của mình. Không được: sản phẩm, liên hệ, trình đơn, thành viên, cài đặt.'],
    'contributor' => [false, 'contributor', 'Lan được: viết bài và gửi duyệt ở mọi chuyên mục; xem thư viện ảnh. Không được: liên hệ, trình đơn, thành viên, cài đặt.'],
    'viewer' => [true, 'viewer', 'Lan được: xem bài; xem sản phẩm; xem thư viện ảnh; xem khách liên hệ. Không được: trình đơn, thành viên, cài đặt.'],
]);

it('reads a role of the website\'s own the same way, and changes as soon as its permissions do', function (): void {
    rxRoles();
    $role = rxCustom('Duyệt tin', 40, ['entries.view', 'entries.approve', 'forms.view']);

    expect(RoleAbilities::sentence('Lan', [rxRole('Duyệt tin')], [], false))
        ->toBe('Lan được: duyệt bài ở mọi chuyên mục; xem khách liên hệ. Không được: thư viện ảnh, trình đơn, thành viên, cài đặt.')
        ->and(RoleAbilities::position($role, rxSiteRoles()))->toBe('Giữa Biên tập và Người viết');

    app(Tenancy::class)->enter(1, fn () => $role->syncPermissions(['entries.view', 'entries.create', 'entries.approve', 'entries.publish', 'users.invite']));

    expect(RoleAbilities::sentence('Lan', [rxRole('Duyệt tin')], [], false))
        ->toBe('Lan được: viết, duyệt và đăng bài ở mọi chuyên mục; mời thành viên. Không được: thư viện ảnh, liên hệ, trình đơn, cài đặt.');
});

it('joins the permissions of every role ticked, and names the categories a scope keeps the person to', function (): void {
    rxRoles();
    $roles = [rxRole('contributor'), rxRole('viewer')];

    expect(RoleAbilities::sentence('Lan', $roles, [CategoryScopes::POSTS => ['Tin tức', 'Dự án']], false))
        ->toBe('Lan được: viết bài và gửi duyệt trong Tin tức, Dự án; xem thư viện ảnh; xem khách liên hệ. Ngoài phạm vi và các Trang: chỉ xem. Không được: trình đơn, thành viên, cài đặt.')
        ->and(RoleAbilities::sentence('Lan', [rxRole('author')], [CategoryScopes::POSTS => []], false))
        ->toStartWith('Lan được: viết bài và gửi duyệt — chưa chọn chuyên mục nào;')
        // a role from the administrators' level up is never limited, whatever is passed
        ->and(RoleAbilities::sentence('Lan', [rxCustom('Trưởng nhóm', 70, ['entries.view', 'entries.create', 'entries.submit'])->load('permissions')], [CategoryScopes::POSTS => ['Tin tức']], false))
        ->toStartWith('Lan được: viết bài và gửi duyệt ở mọi chuyên mục.')->not->toContain('Ngoài phạm vi');
});

it('lists every permission of the catalog in the dialog, done or not, an "own" one held through its "any"', function (): void {
    rxRoles();
    $perms = fn (string $name): array => rxRole($name)->permissions->pluck('name')->all();

    $items = fn (array $areas): array => array_merge(...array_map(fn (array $a): array => $a['items'], $areas));
    $editor = RoleAbilities::explain($perms('editor'), true);
    expect($items($editor))->toHaveCount(count(PermissionRegistry::TENANT_PERMISSIONS))
        ->and(array_column($editor, 'key'))->toBe(['entries', 'media', 'taxonomies', 'menus', 'blocks', 'domains', 'settings', 'forms', 'catalog', 'users', 'roles'])
        ->and($items($editor))->toContain(['ok' => true, 'text' => 'Được sửa bài của mình (nằm trong "Sửa mọi bài")'])
        ->and($items($editor))->toContain(['ok' => false, 'text' => 'Không được đổi nhóm thành viên'])
        ->and($items($editor))->toContain(['ok' => true, 'text' => 'Được quản lý chuyên mục']);

    $author = RoleAbilities::explain($perms('author'), false);
    expect($items($author))->toContain(['ok' => true, 'text' => 'Được sửa bài của mình'])
        ->and($items($author))->toContain(['ok' => false, 'text' => 'Không được sửa bài người khác'])
        ->and(collect($author)->firstWhere('key', 'catalog')['off'])->toBe('Sản phẩm đang tắt theo gói của website — nhóm chưa dùng được các việc dưới đây.');
    // categories of what the role sees: with the catalog off, only post categories
    expect($items(RoleAbilities::explain($perms('editor'), false)))->toContain(['ok' => true, 'text' => 'Được quản lý chuyên mục bài viết']);
});

it('words every permission and area of the catalog in all four languages, so none is ever shown by its code', function (string $locale): void {
    app()->setLocale($locale);
    foreach (array_keys(RoleAbilities::areas()) as $area) {
        expect(__('admin.members.xa.'.$area))->not->toBe('admin.members.xa.'.$area);
    }
    foreach (PermissionRegistry::TENANT_PERMISSIONS as $permission) {
        expect(__('admin.members.xc.'.$permission))->not->toBe('admin.members.xc.'.$permission)
            ->and(__('admin.members.xn.'.$permission))->not->toBe('admin.members.xn.'.$permission);
    }
    expect(array_sum(array_map(fn (array $a): int => count($a['items']), RoleAbilities::explain([], true))))->toBe(count(PermissionRegistry::TENANT_PERMISSIONS));
})->with(['vi', 'en', 'ja', 'zh']);

it('places each role among the website\'s system roles', function (): void {
    rxRoles();
    $all = rxSiteRoles();

    expect(RoleAbilities::position(rxRole('owner'), $all))->toBe('Cao nhất')
        ->and(RoleAbilities::position(rxRole('editor'), $all))->toBe('Dưới Quản trị')
        ->and(RoleAbilities::position(rxRole('viewer'), $all))->toBe('Dưới Cộng tác viên')
        ->and(RoleAbilities::position(rxCustom('Ngang', 30, []), $all))->toBe('Ngang Người viết')
        ->and(RoleAbilities::position(rxCustom('Thấp', 5, []), $all))->toBe('Dưới Chỉ xem');
});

it('opens «Nhóm này làm được gì?» from a member\'s row, an invitation\'s row and beside each role', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    rxMember('editor', 'Lê Văn Hùng');

    rxPage($owner, fn (Testable $page) => $page
        ->assertSee(__('admin.members.x_member_item'))
        ->call('openExplain', 'editor')
        ->assertSet('explainOpen', true)
        ->assertSee(__('admin.members.x_title', ['role' => 'Biên tập']))
        ->assertSee('Nhóm hệ thống · Dưới Quản trị · 1 thành viên')
        ->assertSee('Được duyệt đăng bài')
        ->assertSee('Không được đổi cài đặt website')
        ->call('closeExplain')
        ->assertSet('explainOpen', false)
        ->assertDontSee(__('admin.members.x_title', ['role' => 'Biên tập']))
        ->call('openInvite')
        ->assertSee(__('admin.members.x_more'))
        ->assertSeeHtml("wire:click=\"openExplain('author')\""));
});

it('never explains a role of another website, whether asked by name or forged into the state', function (): void {
    rxRoles();
    rxRoles(2);
    rxCustom('Bí mật B', 40, ['settings.manage'], 2);
    $owner = rxMember('owner', 'Chủ Website');

    rxPage($owner, fn (Testable $page) => $page
        ->call('openExplain', 'Bí mật B')
        ->assertSet('explainOpen', false)
        ->assertSet('explainRole', '')
        ->assertSee(__('admin.members.x_gone'))
        ->call('openExplain', str_repeat('x', 300))
        ->assertSet('explainOpen', false)
        // the role name sent back by the browser is resolved again among this website's roles
        ->set('explainRole', 'Bí mật B')
        ->set('explainOpen', true)
        ->assertSet('explainOpen', false)
        ->assertDontSee('Bí mật B'));
});

it('refuses every new action to a member who may not manage members', function (string $method, array $args): void {
    rxRoles();
    $editor = rxMember('editor');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($editor->fresh())->test(Index::class)
        ->call($method, ...$args)
        ->assertForbidden());
})->with([
    'explain' => ['openExplain', ['viewer']],
    'reload' => ['reloadList', []],
    'save on leaving' => ['saveForLeave', []],
    'discard on leaving' => ['discardDraft', []],
]);

it('says in the panel and the invite dialog what the person will be able to do', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');

    rxPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $lan->ulid)
        ->assertSee('Lan được: viết bài và gửi duyệt ở mọi chuyên mục; tải ảnh lên, xóa ảnh của mình.')
        ->set('panelRoles', ['contributor'])
        ->assertSee('Lan được: viết bài và gửi duyệt ở mọi chuyên mục; xem thư viện ảnh.')
        ->call('closePanel')
        ->call('openInvite')
        ->assertSee(__('admin.members.sm_inv_none'))
        ->set('inviteRoles', ['viewer'])
        ->assertSee('Người được mời được: xem bài;')
        ->assertDontSee(__('admin.members.sm_inv_none')));
});

it('names the picked categories in the panel\'s sentence', function (): void {
    rxRoles();
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
    $news = app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức'])));
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');

    rxPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $lan->ulid)
        ->set('panelScope.post_cat', 'only')
        ->assertSee('viết bài và gửi duyệt — chưa chọn chuyên mục nào')
        ->set('panelCategories.post_cat', [$news->ulid])
        ->assertSee('viết bài và gửi duyệt trong Tin tức')
        ->assertSee('Ngoài phạm vi và các Trang: chỉ xem.'));
});

it('counts an unsaved change while the panel differs from what is saved or an invitation is half written', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');

    rxPage($owner, fn (Testable $page) => $page
        ->assertViewHas('dirty', 0)
        ->call('openPanel', $lan->ulid)
        ->assertViewHas('dirty', 0)
        ->set('panelRoles', ['contributor'])
        ->assertViewHas('dirty', 1)
        ->set('panelRoles', ['author'])
        ->assertViewHas('dirty', 0)
        ->call('closePanel')
        ->call('openInvite')
        ->assertViewHas('dirty', 0)
        ->set('inviteMessage', '   ')
        ->assertViewHas('dirty', 0)
        ->set('inviteEmail', 'moi@example.test')
        ->assertViewHas('dirty', 1)
        ->call('discardDraft')
        ->assertSet('inviteOpen', false)
        ->assertSet('inviteEmail', '')
        ->assertViewHas('dirty', 0));
});

it('saves the panel through «Lưu và rời đi», and never sends an invitation from it', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');

    rxPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $lan->ulid)
        ->set('panelRoles', ['contributor'])
        ->call('saveForLeave')
        ->assertSet('panelFor', '')
        ->assertViewHas('dirty', 0)
        ->call('openInvite')
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRoles', ['viewer'])
        ->call('saveForLeave')
        ->assertSet('inviteResults', [])
        ->assertViewHas('dirty', 1));

    expect(app(Tenancy::class)->enter(1, fn (): array => $lan->fresh()->roles()->pluck('name')->all()))->toBe(['contributor'])
        ->and(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(0);
});

it('closes the panel of a member who left meanwhile, and offers «Tải lại» instead of failing quietly', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');
    $hung = rxMember('author', 'Lê Văn Hùng');
    $leave = fn (User $u) => DB::connection('pgsql_admin')->table('model_has_roles')->where('model_id', $u->id)->delete();

    rxPage($owner, function (Testable $page) use ($lan, $hung, $leave): void {
        $page->call('openPanel', $lan->ulid)->set('panelRoles', ['contributor']);
        $leave($lan);
        $page->call('saveRoles')
            ->assertSet('panelFor', '')
            ->assertSet('toastReload', true)
            ->assertSee(__('admin.member_not_found'))
            ->assertSeeHtml('wire:click="reloadList"')
            ->assertSee(__('admin.members.reload'))
            ->call('reloadList')
            ->assertSet('toast', null)
            ->assertSet('toastReload', false)
            ->assertDontSeeHtml('wire:click="reloadList"');

        // gone before the save: the next redraw says so too
        $page->call('openPanel', $hung->ulid);
        $leave($hung);
        $page->set('panelRoles', ['contributor'])
            ->assertSet('panelFor', '')
            ->assertSet('toastReload', true)
            ->assertSee(__('admin.member_not_found'));
    });

    expect(app(Tenancy::class)->enter(1, fn (): array => $lan->fresh()->roles()->pluck('name')->all()))->toBe([]);
});

it('closes the panel with «Tải lại» when the member leaves while the save is under way', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');

    rxPage($owner, function (Testable $page) use ($lan): void {
        $page->call('openPanel', $lan->ulid)->set('panelRoles', ['contributor']);
        // between the screen's own check and the grant: another admin removes them, and the grant is refused
        $gone = false;
        DB::listen(function (QueryExecuted $q) use (&$gone, $lan): void {
            if (! $gone && str_starts_with($q->sql, 'insert into "model_has_roles"')) {
                $gone = true;
                DB::connection('pgsql_admin')->table('model_has_roles')->where('model_id', $lan->id)->delete();

                throw new AuthorizationException;
            }
        });
        $page->call('saveRoles')
            ->assertSet('panelFor', '')
            ->assertSet('panelError', '')
            ->assertSet('toastReload', true)
            ->assertSee(__('admin.member_not_found'));
        expect($gone)->toBeTrue();
    });
});

it('offers «Tải lại» when an invitation was answered or withdrawn meanwhile', function (string $method): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');

    rxPage($owner, fn (Testable $page) => $page
        ->call($method, (string) Str::ulid())
        ->assertSet('toastReload', true)
        ->assertSee(__('admin.members.inv_gone'))
        ->assertSeeHtml('wire:click="reloadList"'));
})->with(['resendInvitation', 'askRevoke', 'reinvite']);

it('names the leave button after what it does: stay to send an invitation, save a panel', function (): void {
    rxRoles();
    $owner = rxMember('owner', 'Chủ Website');
    $lan = rxMember('author');

    rxPage($owner, function (Testable $page) use ($lan): void {
        $page->call('openInvite')->set('inviteEmail', 'moi@example.test')
            ->assertSeeHtml('data-unsaved-save="'.e(__('admin.members.ud_invite_save')).'"');
        $page->call('discardDraft')->call('openPanel', $lan->ulid)
            ->assertSeeHtml('data-unsaved-save="'.e(__('admin.unsaved.save_leave')).'"');
    });
});

it('counts nothing unsaved when an editor held to categories opens a member\'s panel', function (): void {
    rxRoles();
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.category_scopes', 'value' => true]));
    $news = app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức'])));
    rxCustom('Trưởng nhóm', 60, ['users.manage', 'users.invite', 'entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
    $lead = rxMember('Trưởng nhóm', 'Trần Minh');
    DB::connection('pgsql_admin')->table('member_category_scopes')->insert(['site_id' => 1, 'user_id' => $lead->id, 'taxonomy_id' => $news->id]);
    $lan = rxMember('author');

    rxPage($lead, fn (Testable $page) => $page
        ->call('openPanel', $lan->ulid)
        ->assertSet('panelFor', $lan->ulid)
        ->assertSet('panelScope.post_cat', 'all')
        ->assertViewHas('dirty', 0)
        ->set('panelCategories.post_cat', [$news->ulid])
        ->assertViewHas('dirty', 1));
});

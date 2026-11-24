<?php

use App\Modules\Admin\Livewire\Roles\Index;
use App\Modules\Admin\Support\UpgradeInvitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The roles screen, read-only: every system role and every role of the website's own, highest
 * first, with where each stands, who holds it and what it typically does; the search ignores
 * accents, the chips narrow to the default or the custom roles, one role opens read-only and
 * «Nhóm này làm được gì?» explains one in a side panel. It is for `roles.manage` only and shows only
 * this website's live roles (never another website's, never one in the trash). Its writes: RolesEditTest.
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

/** Every corporate system role on $site, at its template level and permissions. */
function rscSystemRoles(int $site = 1): void
{
    foreach (PermissionRegistry::tenantRoleTemplates('corporate') as $name => $tpl) {
        app(Tenancy::class)->enter($site, function () use ($name, $tpl): void {
            $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
            $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
            $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        });
    }
}

/** A role of the website's own. */
function rscCustom(string $name, int $level, array $permissions, int $site = 1): Role
{
    return app(Tenancy::class)->enter($site, function () use ($name, $level, $permissions): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => false])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

/** A new member of $site holding $role (which exists there). */
function rscMember(string $role, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, fn () => $user->assignRole($role));

    return $user;
}

/** The screen as $actor sees it on site 1, with optional query parameters; $then runs in the same site context. */
function rscPage(User $actor, Closure $then, array $query = []): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::withQueryParams($query)->actingAs($actor->fresh())->test(Index::class)));
}

/** @return list<string> the role names of the rows shown, in order */
function rscKeys(Testable $page): array
{
    return array_column($page->viewData('rows'), 'key');
}

it('lists this website\'s roles, highest first, a system role before a custom one at the same level', function (): void {
    rscSystemRoles();
    rscCustom('Duyệt tin', 50, ['entries.view', 'entries.approve']);
    rscCustom('Trực trang', 15, ['entries.view']);
    $owner = rscMember('owner');

    $this->actingAs($owner)->get('http://a.test/admin/roles')
        ->assertOk()
        ->assertSee(__('admin.roles.title'))
        ->assertSee(__('admin.roles.desc'))
        ->assertSee('Duyệt tin')
        ->assertSee(__('admin.roles.chip_sys'))
        ->assertSee(__('admin.roles.chip_cust'))
        ->assertDontSee(__('admin.roles.noperm_title'));

    rscPage($owner, fn (Testable $page) => expect(rscKeys($page))
        ->toBe(['owner', 'admin', 'editor', 'Duyệt tin', 'author', 'contributor', 'Trực trang', 'viewer'])
        ->and($page->viewData('total'))->toBe(8)
        ->and($page->viewData('custom'))->toBe(2));
});

it('shows a member without roles.manage the no-permission card, and refuses every action', function (): void {
    rscSystemRoles();
    rscCustom('Duyệt tin', 40, ['entries.view']);
    $editor = rscMember('editor');

    $this->actingAs($editor)->get('http://a.test/admin/roles')
        ->assertOk()
        ->assertSee(__('admin.roles.noperm_title'))
        ->assertSee(__('admin.roles.noperm_text', ['role' => __('rbac.role.editor')]))
        ->assertDontSee('Duyệt tin')
        ->assertDontSee(__('admin.roles.col_typical'));

    $calls = [['setKind', 'cust'], ['clearFilters'], ['openRole', 'editor'], ['backToList'], ['clearPermSearch'],
        ['openExplain', 'editor'], ['closeExplain'], ['dismissToast'], ['reloadList']];
    foreach ($calls as $call) {
        rscPage($editor, fn (Testable $page) => $page->call(...$call)->assertForbidden());
    }
    rscPage($editor, fn (Testable $page) => $page->set('search', 'x')->assertForbidden());
});

it('is a website\'s own: roles.manage on one website opens nothing on another', function (): void {
    rscSystemRoles();
    $owner = rscMember('owner');

    $this->actingAs($owner)->get('http://b.test/admin/roles')->assertForbidden();
});

it('never shows, opens or explains another website\'s role', function (): void {
    rscSystemRoles();
    rscSystemRoles(2);
    rscCustom('Riêng của B', 40, ['entries.view'], 2);
    $owner = rscMember('owner');

    $this->actingAs($owner)->get('http://a.test/admin/roles')->assertOk()->assertDontSee('Riêng của B');

    rscPage($owner, fn (Testable $page) => $page
        ->assertSet('role', '')
        ->call('openRole', 'Riêng của B')
        ->assertSet('role', '')->assertSet('mode', '')
        ->assertSet('toast', __('admin.members.x_gone'))->assertSet('toastReload', true)
        ->call('openExplain', 'Riêng của B')
        ->assertSet('explainRole', '')
        ->call('reloadList')->assertSet('toast', null));

    // a link naming it opens the list
    rscPage($owner, fn (Testable $page) => $page->assertSet('role', '')->assertSet('mode', '')
        ->assertViewHas('viewed', null), ['nhom' => 'Riêng của B', 'che' => 'xem']);

    expect(rscPage($owner, fn (Testable $page): array => rscKeys($page)))->not->toContain('Riêng của B');
});

it('keeps a role in the trash out of the list, its counts and its links', function (): void {
    rscSystemRoles();
    $gone = rscCustom('Đã bỏ', 40, ['entries.view']);
    rscCustom('Còn dùng', 30, ['entries.view']);
    $owner = rscMember('owner');
    app(Tenancy::class)->enter(1, fn () => $gone->delete());

    $this->actingAs($owner)->get('http://a.test/admin/roles')->assertOk()->assertDontSee('Đã bỏ')->assertSee('Còn dùng');

    rscPage($owner, fn (Testable $page) => expect(rscKeys($page))->not->toContain('Đã bỏ')
        ->and($page->viewData('custom'))->toBe(1));
    rscPage($owner, fn (Testable $page) => $page->call('openRole', 'Đã bỏ')->assertSet('mode', '')
        ->assertSet('toast', __('admin.members.x_gone')));
    rscPage($owner, fn (Testable $page) => $page->assertViewHas('viewed', null), ['nhom' => 'Đã bỏ', 'che' => 'xem']);
});

it('counts each role\'s members and pending invitations, and links the members to the members screen', function (): void {
    rscSystemRoles();
    $owner = rscMember('owner');
    rscMember('editor');
    rscMember('editor');
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)
        ->handle($owner->fresh(), 'moi@example.test', [Role::findByName('editor', PermissionRegistry::GUARD)]));

    rscPage($owner, function (Testable $page): void {
        $rows = collect($page->viewData('rows'))->keyBy('key');
        expect($rows['editor']['members'])->toBe(2)
            ->and($rows['editor']['invites'])->toBe(1)
            ->and($rows['editor']['membersText'])->toBe('2 thành viên · 1 lời mời')
            ->and($rows['owner']['members'])->toBe(1)
            ->and($rows['viewer']['members'])->toBe(0);
        $page->assertSee(trans_choice('admin.roles.invites_waiting', 1, ['n' => 1]))
            ->assertSee(route('admin.users.index', ['nhom' => ['editor']]), false);
    });
});

it('says where each role stands and what it typically does', function (): void {
    rscSystemRoles();
    rscCustom('Duyệt tin', 40, ['entries.view', 'entries.approve', 'forms.view']);
    rscCustom('Trống', 12, []);
    $owner = rscMember('owner');

    rscPage($owner, function (Testable $page): void {
        $rows = collect($page->viewData('rows'))->keyBy('key');
        expect($rows['owner']['typical'])->toBe(__('admin.members.does_all'))
            ->and($rows['Duyệt tin']['typical'])->toBe(__('admin.members.perm.entries.approve').' · '.__('admin.members.perm.entries.view').' · '.__('admin.members.perm.forms.view'))
            ->and($rows['Trống']['typical'])->toBe(__('admin.members.does_nothing'))
            ->and($rows['owner']['pos'])->toBe(__('admin.members.x_top'))
            ->and($rows['admin']['pos'])->toBe(__('admin.members.x_below', ['role' => __('rbac.role.owner')]));
        // three telling jobs, then how many more
        expect($rows['editor']['typical'])->toMatch('/^[^·]+ · [^·]+ · [^·]+ · \+\d+ việc$/u');
    });
});

it('says which role a copy was made from, also once that role is in the trash, and nothing for a role that is no copy', function (): void {
    rscSystemRoles();
    $admin = app(Tenancy::class)->enter(1, fn (): ?Role => Role::query()->where('site_id', 1)->where('name', 'admin')->first());
    $source = rscCustom('Gốc', 40, ['entries.view']);
    $elsewhere = rscCustom('Ngoài', 40, ['entries.view'], 2);
    $link = fn (Role $copy, Role $from) => app(Tenancy::class)->enter(1, fn () => $copy->forceFill(['forked_from_role_id' => $from->getKey()])->save());
    $link(rscCustom('Trợ lý', 60, ['entries.view']), $admin);
    $link(rscCustom('Bản sao', 30, ['entries.view']), $source);
    $link(rscCustom('Lạc', 20, ['entries.view']), $elsewhere);
    rscCustom('Nhóm A', 15, ['entries.view']);
    app(Tenancy::class)->enter(1, fn () => $source->delete());
    $owner = rscMember('owner');

    rscPage($owner, function (Testable $page): void {
        $rows = collect($page->viewData('rows'))->keyBy('key');
        expect($rows['Trợ lý']['from'])->toBe(__('rbac.role.admin'))
            ->and($rows['Bản sao']['from'])->toBe('Gốc')
            ->and($rows['Lạc']['from'])->toBeNull()        // another website's role is never named
            ->and($rows['Nhóm A']['from'])->toBeNull()
            ->and($rows['admin']['from'])->toBeNull();
        $page->assertSeeText(__('admin.roles.from', ['role' => __('rbac.role.admin')]))
            ->assertSeeText(__('admin.roles.from', ['role' => 'Gốc']))
            ->assertDontSeeText(__('admin.roles.from', ['role' => 'Ngoài']));
    });
});

it('gives a system role its one-line description when it has one', function (): void {
    rscSystemRoles();
    app(Tenancy::class)->enter(1, function (): void {
        $role = Role::findOrCreate('customer_care', PermissionRegistry::GUARD);
        $role->forceFill(['level' => 40, 'is_system' => true])->save();
        $role->syncPermissions(['forms.view', 'forms.manage']);
    });
    rscCustom('customer_care_copy', 35, ['forms.view']);
    $owner = rscMember('owner');

    rscPage($owner, function (Testable $page): void {
        $rows = collect($page->viewData('rows'))->keyBy('key');
        expect($rows['customer_care']['desc'])->toBe(__('admin.roles.sys_desc.customer_care'))
            ->and($rows['customer_care']['desc'])->not->toBe('admin.roles.sys_desc.customer_care')
            ->and($rows['editor']['desc'])->toBeNull()
            ->and($rows['customer_care_copy']['desc'])->toBeNull();
        $page->assertSeeText(__('admin.roles.sys_desc.customer_care'));
    });
});

it('finds roles without minding accents, narrows them by chip, and says how many it found', function (): void {
    rscSystemRoles();
    rscCustom('Duyệt tin', 40, ['entries.view']);
    $owner = rscMember('owner');

    rscPage($owner, function (Testable $page): void {
        $page->set('search', 'bien tap');
        expect(rscKeys($page))->toBe(['editor']);
        $page->assertSeeText(strip_tags(__('admin.roles.found', ['n' => 1, 'm' => 7])));

        $page->set('search', 'DUYET');
        expect(rscKeys($page))->toBe(['Duyệt tin']);

        $page->set('search', 'không có nhóm này')->assertSee(__('admin.roles.nomatch', ['q' => 'không có nhóm này']));
        expect(rscKeys($page))->toBe([]);

        $page->call('clearFilters')->assertSet('search', '')->assertSet('kind', 'all');
        expect(rscKeys($page))->toHaveCount(7);

        $page->call('setKind', 'cust');
        expect(rscKeys($page))->toBe(['Duyệt tin']);
        $page->call('setKind', 'sys');
        expect(rscKeys($page))->toBe(['owner', 'admin', 'editor', 'author', 'contributor', 'viewer']);
        $page->call('setKind', 'made-up')->assertSet('kind', 'all');
    });
});

it('shows the empty card for custom roles while the website has none of its own', function (): void {
    rscSystemRoles();
    $owner = rscMember('owner');

    rscPage($owner, fn (Testable $page) => $page->assertSee(__('admin.roles.empty_title'))
        ->call('setKind', 'sys')->assertDontSee(__('admin.roles.empty_title')));

    rscCustom('Duyệt tin', 40, ['entries.view']);
    rscPage($owner, fn (Testable $page) => $page->assertDontSee(__('admin.roles.empty_title')));
});

it('opens one role read-only from a link or the list, every catalog permission held or not', function (): void {
    rscSystemRoles();
    rscCustom('Duyệt tin', 40, ['entries.view', 'entries.approve']);
    $owner = rscMember('owner');

    rscPage($owner, function (Testable $page): void {
        $page->assertSet('mode', 'xem')->assertSee(__('admin.roles.ro_title', ['role' => 'Duyệt tin']));
        $viewed = $page->viewData('viewed');
        $cells = collect($viewed['areas'])->flatMap(fn (array $a): array => $a['cells'])->keyBy('key');
        expect($cells->keys()->sort()->values()->all())->toBe(collect(PermissionRegistry::TENANT_PERMISSIONS)->sort()->values()->all())
            ->and($cells->where('on', true)->keys()->all())->toBe(['entries.view', 'entries.approve'])
            ->and($viewed['note'])->toBeNull();
        $page->assertSee(__('admin.roles.cd.entries.approve'));

        $page->set('permSearch', 'duyet');
        $keys = collect($page->viewData('viewed')['areas'])->flatMap(fn (array $a): array => array_column($a['cells'], 'key'))->all();
        expect($keys)->toContain('entries.approve')->not->toContain('media.upload');

        $page->set('permSearch', 'zzz')->assertSee(__('admin.roles.perm_nomatch', ['q' => 'zzz']))
            ->call('clearPermSearch')->assertSet('permSearch', '')
            ->call('backToList')->assertSet('role', '')->assertSet('mode', '')->assertViewHas('viewed', null);
    }, ['nhom' => 'Duyệt tin', 'che' => 'xem']);

    // «sua» on a system role is its read-only view; «moi» while the plan has no custom roles is the list
    rscPage($owner, fn (Testable $page) => $page->assertSet('mode', 'xem')->assertSet('role', 'editor'), ['nhom' => 'editor', 'che' => 'sua']);
    rscPage($owner, fn (Testable $page) => $page->assertSet('mode', '')->assertSet('role', ''), ['che' => 'moi']);

    rscPage($owner, fn (Testable $page) => $page->call('openRole', 'editor')
        ->assertSet('role', 'editor')->assertSet('mode', 'xem')
        ->assertSee(__('admin.roles.ro_system')));
});

it('says why a role at or above the viewer\'s level is read-only', function (): void {
    rscSystemRoles();
    rscCustom('Ngang quản trị', 70, ['entries.view']);
    rscCustom('Dưới quản trị', 40, ['entries.view']);
    $admin = rscMember('admin');

    rscPage($admin, fn (Testable $page) => $page->assertSee(__('admin.roles.ro_level')), ['nhom' => 'Ngang quản trị', 'che' => 'xem']);
    rscPage($admin, fn (Testable $page) => $page->assertDontSee(__('admin.roles.ro_level'))
        ->assertDontSee(__('admin.roles.ro_system')), ['nhom' => 'Dưới quản trị', 'che' => 'xem']);
});

it('explains a role in the side panel and gives the focus back to the button that opened it', function (): void {
    rscSystemRoles();
    $owner = rscMember('owner');

    rscPage($owner, fn (Testable $page) => $page
        ->call('openExplain', 'author', 'xd-r1234567890')
        ->assertSet('explainRole', 'author')->assertSet('explainFrom', 'xd-r1234567890')
        ->assertSee(__('admin.members.x_title', ['role' => __('rbac.role.author')]))
        ->assertViewHas('explain', fn (?array $x): bool => $x !== null && $x['areas'] !== [])
        ->call('closeExplain')->assertSet('explainRole', '')->assertViewHas('explain', null)
        // a made-up element id is never handed to the page
        ->call('openExplain', 'author', '"><script>')->assertSet('explainFrom', ''));
});

it('says plainly when the plan has no custom roles, inviting an upgrade only when the platform does', function (): void {
    rscSystemRoles();
    $owner = rscMember('owner');

    rscPage($owner, fn (Testable $page) => $page->assertSee(__('admin.roles.plan_off_title'))
        ->assertDontSee(__('admin.roles.plan_up_title'))->assertDontSee(__('admin.roles.plan_kept')));

    app(UpgradeInvitation::class)->set(true);
    rscCustom('Duyệt tin', 40, ['entries.view']);
    rscPage($owner, fn (Testable $page) => $page->assertSee(__('admin.roles.plan_up_title'))
        ->assertSee(__('admin.roles.plan_kept')));

    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]));
    rscPage($owner, fn (Testable $page) => $page->assertDontSee(__('admin.roles.plan_up_title'))
        ->assertDontSee(__('admin.roles.plan_off_title')));
});

it('tells support staff signed in as the member that they can only look', function (): void {
    rscSystemRoles();
    $owner = rscMember('owner');

    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($owner);
    $staff = User::factory()->create(['name' => 'Nhân viên hỗ trợ']);
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    app(SupportSession::class)->start($staff, $owner, 1, 'Hỗ trợ kiểm tra lỗi', now()->addMinutes(30));
    app()->rebinding('request', function ($app, Request $request) use ($session): void {
        if (! $request->hasSession()) {
            $request->setLaravelSession($session);
        }
    });

    rscPage($owner, fn (Testable $page) => $page->assertSee(__('admin.members.support_title'))
        ->assertSee(__('admin.roles.support_text'))
        ->call('openRole', 'editor')->assertSee(__('admin.support.locked')));
});

it('tells platform staff who opened the website directly why its roles are not theirs to change', function (): void {
    rscSystemRoles();
    rscMember('owner');
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    rscPage($staff, fn (Testable $page) => $page->assertSee(__('admin.staff.locked')));
});

it('names and describes every permission of the catalog in every admin language', function (string $locale): void {
    app()->setLocale($locale);
    foreach (PermissionRegistry::TENANT_PERMISSIONS as $permission) {
        foreach (['admin.roles.cl.', 'admin.roles.cd.'] as $prefix) {
            expect(__($prefix.$permission))->not->toBe($prefix.$permission);
        }
    }
})->with(['vi', 'en', 'ja', 'zh']);

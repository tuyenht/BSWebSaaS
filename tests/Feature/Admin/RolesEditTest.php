<?php

use App\Modules\Admin\Livewire\Roles\Index;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\RestoreCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\CustomRoleRules;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The write side of «Nhóm quyền»: «Tạo nhóm ▾» (blank, or from a role the member may give),
 * «Sửa», «Nhân bản», «Nhân bản để tùy chỉnh», «Xóa…» with «Hoàn tác». Every write goes through
 * the Rbac actions; the screen only offers what they would allow and says their refusals plainly.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
    foreach ([1, 2] as $site) {
        DB::connection('pgsql_admin')->table('sites')->where('id', $site)
            ->update(['site_type_id' => DB::table('site_types')->where('key', 'corporate')->value('id')]);
        reeSystemRoles($site);
    }
});

function reeIn(int $site, Closure $fn): mixed
{
    return app(Tenancy::class)->enter($site, $fn);
}

function reeSystemRoles(int $site): void
{
    foreach (PermissionRegistry::tenantRoleTemplates('corporate') as $name => $tpl) {
        reeIn($site, function () use ($name, $tpl): void {
            $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
            $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
            $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        });
    }
}

function reePlan(int $site = 1): void
{
    reeIn($site, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]));
}

function reeCustom(string $name, int $level, array $permissions, int $site = 1): Role
{
    return reeIn($site, function () use ($name, $level, $permissions): Role {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => false])->save();
        $role->syncPermissions($permissions);

        return $role;
    });
}

function reeMember(string $role, int $site = 1): User
{
    $user = User::factory()->create();
    reeIn($site, fn () => $user->assignRole($role));

    return $user;
}

function reePage(User $actor, Closure $then, array $query = []): mixed
{
    return reeIn(1, fn () => $then(Livewire::withQueryParams($query)->actingAs($actor->fresh())->test(Index::class)));
}

/** @return list<string> the role's permission names as stored, sorted */
function reePerms(string $name, int $site = 1, bool $withTrashed = false): array
{
    return reeIn($site, function () use ($name, $withTrashed): array {
        $names = Role::query()->when($withTrashed, fn ($q) => $q->withTrashed())->where('site_id', app(CurrentSite::class)->id())->where('name', $name)->firstOrFail()
            ->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    });
}

function reeRole(string $name, int $site = 1): ?Role
{
    return Role::withTrashed()->where('site_id', $site)->where('name', $name)->first();
}

it('creates a blank role: a name, a level below the member and the ticked tasks, «Xem» ticked with what needs it', function (): void {
    reePlan();
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('newRole')->assertSet('mode', 'moi')->assertSet('name', '')->assertSet('level', 15)
            ->assertSet('sel', ['entries.view'])->assertSee(__('admin.roles.new_title'))->assertSee(__('admin.roles.pv_new'));
        expect(array_column($page->viewData('editor')['levels'], 'level'))->toBe([80, 60, 40, 25, 15, 5]);

        // ticking a task that needs «Xem» keeps it; unticking «Xem» while it is needed is refused, the reason in its area
        $page->call('toggle', 'entries.approve')->assertSet('sel', ['entries.view', 'entries.approve'])
            ->call('toggle', 'entries.view')->assertSet('sel', ['entries.view', 'entries.approve'])
            ->assertSet('noteArea', 'entries')->assertSee(__('admin.roles.implied_block', ['perm' => __('admin.roles.cl.entries.view'), 'by' => __('admin.roles.cl.entries.approve')]));
        expect($page->viewData('editor')['dirty'])->toBe(1);

        $page->set('name', 'Người duyệt bài')->call('pickLevel', 25)->call('pickLevel', 95)->assertSet('level', 25)
            ->call('save')
            ->assertSet('mode', '')->assertSet('toast', __('admin.roles.saved_new', ['role' => 'Người duyệt bài']));
    });

    $role = reeRole('Người duyệt bài');
    expect($role)->not->toBeNull()->and($role->level)->toBe(25)->and($role->is_system)->toBeFalse()
        ->and(reePerms('Người duyệt bài'))->toBe(['entries.approve', 'entries.view']);
});

it('copies a role the member may give: «Từ: X», «Nhân bản» and «Nhân bản để tùy chỉnh» start from its tasks', function (): void {
    reePlan();
    $owner = reeMember('owner');
    $editorPerms = PermissionRegistry::tenantRoleTemplates('corporate')['editor']['permissions'];

    reePage($owner, function (Testable $page) use ($editorPerms): void {
        expect(array_column($page->viewData('sources'), 'key'))->toContain('editor', 'author')->not->toContain('owner');
        $page->assertSee(__('admin.roles.create_from', ['role' => __('rbac.role.editor')]));

        $page->call('copyRole', 'editor')->assertSet('mode', 'moi')->assertSet('role', 'editor')
            ->assertSet('name', __('admin.roles.copy_name', ['role' => __('rbac.role.editor')]))->assertSet('level', 40)
            ->assertSee(__('admin.roles.from', ['role' => __('rbac.role.editor')]));
        expect($page->get('sel'))->toEqualCanonicalizing($editorPerms);

        $page->set('name', 'Biên tập tin')->call('toggle', 'entries.publish')->call('save')->assertSet('mode', '');
    });

    $copy = reeRole('Biên tập tin');
    expect($copy->forked_from_role_id)->toBe(reeRole('editor')->id)
        ->and(reePerms('Biên tập tin'))->toEqual(collect($editorPerms)->reject(fn (string $p): bool => $p === 'entries.publish')->sort()->values()->all());

    // the read-only page of a system role offers «Nhân bản để tùy chỉnh»
    reePage($owner, fn (Testable $page) => $page->assertSet('mode', 'xem')->assertSee(__('admin.roles.copy_custom'))
        ->call('copyRole', 'author')->assertSet('mode', 'moi')->assertSet('role', 'author'), ['nhom' => 'author', 'che' => 'xem']);

    // a deep link to a new role from a source
    reePage($owner, fn (Testable $page) => $page->assertSet('mode', 'moi')->assertSet('role', 'author'), ['nhom' => 'author', 'che' => 'moi']);
});

it('edits a role of the website\'s own: renamed, a level moved, tasks changed, «Đã đổi» and the preview saying what changes', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view', 'entries.approve']);
    $owner = reeMember('owner');
    reeMember('Duyệt tin');

    reePage($owner, function (Testable $page): void {
        $page->assertSee(__('admin.roles.edit_aria', ['role' => 'Duyệt tin']))
            ->call('openRole', 'Duyệt tin')->assertSet('mode', 'sua')->assertSet('name', 'Duyệt tin')->assertSet('level', 40)
            ->assertSee(__('admin.roles.edit_title', ['role' => 'Duyệt tin']))
            ->assertSee(__('admin.roles.pv_none', ['base' => __('admin.roles.pv_base_saved')]));
        expect($page->viewData('editor')['saveOff'])->toBeTrue();

        $page->call('toggle', 'entries.publish')->call('toggle', 'entries.approve')->set('name', 'Duyệt bài')->call('pickLevel', 25);
        $editor = $page->viewData('editor');
        $cells = collect($editor['areas'])->flatMap(fn (array $a): array => $a['cells'])->keyBy('key');
        expect($editor['dirty'])->toBe(4)->and($editor['saveOff'])->toBeFalse()
            ->and($cells['entries.publish']['changed'])->toBeTrue()->and($cells['entries.view']['changed'])->toBeFalse()
            ->and($editor['pv']['add'])->toBe(__('admin.members.perm.entries.publish'))
            ->and($editor['pv']['rem'])->toBe(__('admin.members.perm.entries.approve'))
            ->and($editor['pv']['affect'])->toBe(__('admin.roles.pv_affect', ['members' => trans_choice('admin.members.x_members', 1, ['n' => 1])]));

        $page->call('save')->assertSet('mode', '')
            ->assertSet('toast', __('admin.roles.saved', ['role' => 'Duyệt bài', 'members' => trans_choice('admin.members.x_members', 1, ['n' => 1])]));
    });

    expect(reeRole('Duyệt tin'))->toBeNull()->and(reeRole('Duyệt bài')->level)->toBe(25)
        ->and(reePerms('Duyệt bài'))->toBe(['entries.publish', 'entries.view']);
});

it('keeps what the member may not give, locked with the reason, and stores it unchanged', function (): void {
    reePlan();
    // a lead who manages roles but holds no media task
    reeCustom('Trưởng nhóm', 80, ['roles.manage', 'entries.view', 'entries.approve', 'entries.publish']);
    reeCustom('Duyệt tin', 40, ['entries.view', 'entries.approve', 'media.view', 'media.upload']);
    $lead = reeMember('Trưởng nhóm');

    reePage($lead, function (Testable $page): void {
        $page->call('openRole', 'Duyệt tin')->assertSet('mode', 'sua');
        $cells = collect($page->viewData('editor')['areas'])->flatMap(fn (array $a): array => $a['cells'])->keyBy('key');
        expect($cells['media.upload']['state'])->toBe('lock')->and($cells['media.upload']['on'])->toBeTrue()
            ->and($cells['media.upload']['why'])->toBe(__('admin.roles.lock_why_on', ['area' => __('admin.members.xa.media'), 'perm' => __('admin.roles.cl.media.upload')]))
            ->and($cells['entries.publish']['state'])->toBe('free');

        // a locked task cannot be toggled from the browser
        $page->call('toggle', 'media.upload')->call('toggle', 'entries.publish');
        expect($page->get('sel'))->toBe(['entries.view', 'entries.approve', 'entries.publish', 'media.view', 'media.upload']);
        expect($page->viewData('editor')['pv']['keep'])->toContain(__('admin.members.perm.media.upload'));
        $page->call('save')->assertSet('mode', '');
    });

    expect(reePerms('Duyệt tin'))->toBe(['entries.approve', 'entries.publish', 'entries.view', 'media.upload', 'media.view']);
});

it('says a refused name under the field and keeps the draft', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('newRole')->call('toggle', 'forms.view')
            ->call('save')->assertSet('mode', 'moi')->assertSet('nameError', __('admin.roles.err_name_invalid'))
            ->set('name', 'editor')->assertSet('nameError', '')->call('checkName')->assertSet('nameError', __('admin.roles.err_name_reserved', ['name' => 'editor']))
            ->set('name', 'DUYỆT TIN')->call('save')->assertSet('nameError', __('admin.roles.err_name_taken', ['name' => 'DUYỆT TIN']))
            ->assertSet('sel', ['entries.view', 'forms.view'])
            ->set('name', 'Xem biểu mẫu')->call('checkName')->assertSet('nameError', '')->call('save')->assertSet('mode', '');
    });

    expect(reeRole('Xem biểu mẫu'))->not->toBeNull();
});

it('moves a role into the trash at once with «Hoàn tác», which brings it back', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view', 'entries.approve']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('askDelete', 'Duyệt tin')->assertSet('ask', '')->assertSet('toastUndo', true)->assertSet('undoName', 'Duyệt tin')
            ->assertSet('toast', __('admin.roles.deleted', ['role' => 'Duyệt tin']))->assertSee(__('admin.undo'));
        expect(reeKeys($page))->not->toContain('Duyệt tin');
        expect(reeRole('Duyệt tin')->trashed())->toBeTrue();

        $page->call('undoDelete')->assertSet('toast', __('admin.roles.restored'))->assertSet('toastUndo', false);
        expect(reeKeys($page))->toContain('Duyệt tin');
    });

    expect(reeRole('Duyệt tin')->trashed())->toBeFalse()->and(reePerms('Duyệt tin'))->toBe(['entries.approve', 'entries.view']);
});

/** @return list<string> */
function reeKeys(Testable $page): array
{
    return array_column($page->viewData('rows'), 'key');
}

it('does not delete a role someone holds or is invited to: it says who and opens Thành viên', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');
    reeMember('Duyệt tin');

    reePage($owner, function (Testable $page): void {
        $page->call('askDelete', 'Duyệt tin')->assertSet('ask', 'inuse')->assertSet('asking', true)
            ->assertSee(__('admin.roles.inuse_title'))
            ->assertSee(__('admin.roles.inuse_text', ['role' => 'Duyệt tin', 'members' => trans_choice('admin.members.x_members', 1, ['n' => 1])]))
            ->call('confirmAsk')->assertRedirect(route('admin.users.index', ['nhom' => ['Duyệt tin']]));
    });

    expect(reeRole('Duyệt tin')->trashed())->toBeFalse();
});

it('while the plan has no custom roles: no new role, an edit still saves, and a delete asks first since nothing brings it back', function (): void {
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->assertSee(__('admin.roles.lock_plan_off'))->assertViewHas('planWhy', __('admin.roles.lock_plan_off'))
            ->call('newRole')->assertSet('mode', '')->assertSet('toast', __('admin.roles.lock_plan_off'))
            ->call('copyRole', 'editor')->assertSet('mode', '');

        $page->call('openRole', 'Duyệt tin')->assertSet('mode', 'sua')->call('toggle', 'forms.view')->call('save')->assertSet('mode', '')
            ->assertSet('toast', __('admin.roles.saved', ['role' => 'Duyệt tin', 'members' => trans_choice('admin.members.x_members', 0, ['n' => 0])]));

        $page->call('askDelete', 'Duyệt tin')->assertSet('ask', 'delete')->assertSee(__('admin.roles.delete_off_text'));
        expect(reeRole('Duyệt tin')->trashed())->toBeFalse();
        $page->call('confirmAsk')->assertSet('toast', __('admin.roles.deleted_off', ['role' => 'Duyệt tin']))->assertSet('toastUndo', false);
    });

    expect(reeRole('Duyệt tin')->trashed())->toBeTrue()->and(reePerms('Duyệt tin', withTrashed: true))->toBe(['entries.view', 'forms.view']);
    // a deep link to a new role opens the list
    reePage($owner, fn (Testable $page) => $page->assertSet('mode', ''), ['che' => 'moi']);
});

it('says the 50-role cap and the trash cap in plain words when a new role is refused', function (): void {
    reePlan();
    $owner = reeMember('owner');
    $now = now();
    $insert = function (int $live, int $trashed) use ($now): void {
        $rows = [];
        foreach (range(1, $live + $trashed) as $i) {
            $rows[] = ['site_id' => 1, 'name' => "Nhóm {$i}", 'guard_name' => PermissionRegistry::GUARD, 'level' => 10, 'is_system' => false,
                'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $i > $live ? $now : null];
        }
        DB::connection('pgsql_admin')->table('roles')->insert($rows);
    };
    $insert(CustomRoleRules::MAX_CUSTOM_ROLES, 0);

    reePage($owner, fn (Testable $page) => $page->call('newRole')->set('name', 'Nhóm thừa')->call('save')
        ->assertSet('mode', 'moi')->assertSet('toast', __('rbac.role_change.too_many', ['max' => CustomRoleRules::MAX_CUSTOM_ROLES])));

    DB::connection('pgsql_admin')->table('roles')->where('site_id', 1)->where('is_system', false)->delete();
    $insert(10, CustomRoleRules::MAX_KEPT_CUSTOM_ROLES - 10);
    reePage($owner, fn (Testable $page) => $page->call('newRole')->set('name', 'Nhóm thừa')->call('save')
        ->assertSet('mode', 'moi')->assertSet('toast', __('rbac.role_change.trash_full', ['max' => CustomRoleRules::MAX_KEPT_CUSTOM_ROLES, 'days' => Role::TRASH_DAYS])));
    expect(reeRole('Nhóm thừa'))->toBeNull();
});

it('refuses a save over a change someone else made meanwhile, and «Tải lại» opens the role as it is now', function (): void {
    reePlan();
    $role = reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page) use ($role): void {
        $page->call('openRole', 'Duyệt tin')->call('toggle', 'forms.view');
        $role->fresh()->givePermissionTo('media.view');   // the page runs inside site 1 already

        $page->call('save')->assertSet('mode', 'sua')->assertSet('toast', __('rbac.role_change.changed_elsewhere'))->assertSet('toastReload', true)
            ->call('reloadList')->assertSet('mode', 'sua')->assertSet('sel', ['entries.view', 'media.view']);
    });

    expect(reePerms('Duyệt tin'))->toBe(['entries.view', 'media.view']);
});

it('asks before leaving unsaved changes, and the unsaved-changes dialog can drop them', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('openRole', 'Duyệt tin')->call('backToList')->assertSet('mode', '');

        $page->call('openRole', 'Duyệt tin')->call('toggle', 'forms.view')->call('backToList')->assertSet('mode', 'sua')->assertSet('ask', 'leave')
            ->assertSee(trans_choice('admin.roles.leave_text', 1, ['n' => 1]))
            ->call('cancelAsk')->assertSet('mode', 'sua')->assertSet('ask', '')
            ->call('discardDraft')->assertSet('sel', ['entries.view'])
            ->call('toggle', 'forms.view')->call('backToList')->call('confirmAsk')->assertSet('mode', '');
    });

    expect(reePerms('Duyệt tin'))->toBe(['entries.view']);
});

it('explains the draft before it is saved', function (): void {
    reePlan();
    $owner = reeMember('owner');

    reePage($owner, fn (Testable $page) => $page->call('newRole')->set('name', 'Nháp')->call('toggle', 'forms.view')->call('explainEditor')
        ->assertSet('explainDraft', true)->assertSee(__('admin.members.x_title', ['role' => 'Nháp']))
        ->assertViewHas('explain', fn (?array $x): bool => $x !== null && $x['editable'] === false)
        ->call('closeExplain')->assertViewHas('explain', null));
});

it('refuses every write to a member without roles.manage', function (string $method, array $args): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $editor = reeMember('editor');

    reePage($editor, fn (Testable $page) => $page->call($method, ...$args)->assertForbidden());
    expect(reeRole('Duyệt tin')->trashed())->toBeFalse();
})->with([
    'newRole' => ['newRole', []],
    'copyRole' => ['copyRole', ['author']],
    'save' => ['save', []],
    'toggle' => ['toggle', ['entries.view']],
    'toggleArea' => ['toggleArea', ['entries']],
    'pickLevel' => ['pickLevel', [15]],
    'checkName' => ['checkName', []],
    'askDelete' => ['askDelete', ['Duyệt tin']],
    'confirmAsk' => ['confirmAsk', []],
    'undoDelete' => ['undoDelete', []],
    'discardDraft' => ['discardDraft', []],
    'explainEditor' => ['explainEditor', []],
]);

it('never copies, edits or deletes another website\'s role', function (): void {
    reePlan();
    reePlan(2);
    reeCustom('Riêng của B', 20, ['entries.view'], site: 2);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('copyRole', 'Riêng của B')->assertSet('mode', '')->assertSet('toast', __('admin.members.x_gone'))
            ->call('openRole', 'Riêng của B')->assertSet('mode', '')
            ->call('askDelete', 'Riêng của B')->assertSet('ask', '');
        // the browser cannot put the screen into the editor of a role it names
        $page->set('mode', 'sua')->set('role', 'Riêng của B')->assertSet('mode', '')->call('save');
    });
    reePage($owner, fn (Testable $page) => $page->assertSet('mode', ''), ['nhom' => 'Riêng của B', 'che' => 'sua']);

    expect(reeRole('Riêng của B', 2)->trashed())->toBeFalse()->and(reePerms('Riêng của B', 2))->toBe(['entries.view'])
        ->and(Role::query()->where('site_id', 1)->where('is_system', false)->count())->toBe(0);
});

it('offers only what the member may do: a role at their level is read-only, a role with tasks they lack cannot be copied', function (): void {
    reePlan();
    reeCustom('Trưởng nhóm', 60, ['roles.manage', 'entries.view']);
    reeCustom('Ngang', 60, ['entries.view']);
    reeCustom('Dưới', 30, ['entries.view', 'media.view']);
    $lead = reeMember('Trưởng nhóm');

    reePage($lead, function (Testable $page): void {
        $rows = collect($page->viewData('rows'))->keyBy('key');
        expect($rows['Ngang']['editable'])->toBeFalse()->and($rows['Ngang']['deletable'])->toBeFalse()
            ->and($rows['Dưới']['editable'])->toBeTrue()->and($rows['Dưới']['copyable'])->toBeFalse()
            ->and(array_column($page->viewData('sources'), 'key'))->not->toContain('Dưới', 'Ngang', 'editor');

        $page->call('copyRole', 'Dưới')->assertSet('mode', '')
            ->call('openRole', 'Ngang')->assertSet('mode', 'xem')->assertSee(__('admin.roles.ro_level'));
    });
});

it('refuses a direct call on a role the member may not change: a system role, one at their level, one raised above them', function (): void {
    reePlan();
    reeCustom('Trưởng nhóm', 60, ['roles.manage', 'entries.view', 'media.view']);
    reeCustom('Ngang', 60, ['entries.view']);
    reeCustom('Dưới', 30, ['entries.view']);
    $lead = reeMember('Trưởng nhóm');

    reePage($lead, function (Testable $page): void {
        // a role at the member's level: nothing moves, the words say it changed
        $page->call('askDelete', 'Ngang')->assertSet('toast', __('rbac.role_change.changed_elsewhere'))->assertSet('toastUndo', false);
        // the editor never opens on it, and a save from the read-only view writes nothing
        $page->call('openRole', 'Ngang')->assertSet('mode', 'xem')->call('toggle', 'media.view')->call('save')->assertSet('mode', 'xem');
        $page->set('mode', 'sua')->assertSet('mode', '')->call('save');

        // raised above the member while their editor was open: the save is refused in plain words
        $page->call('openRole', 'Dưới')->assertSet('mode', 'sua')->call('toggle', 'media.view');
        Role::query()->where('site_id', 1)->where('name', 'Dưới')->update(['level' => 70]);
        $page->call('save')->assertSet('mode', 'sua')->assertSet('toast', __('rbac.role_change.changed_elsewhere'))->assertSet('toastReload', true);
        $page->call('askDelete', 'Dưới')->assertSet('toast', __('rbac.role_change.changed_elsewhere'));
    });
    reePage($lead, fn (Testable $page) => $page->call('askDelete', 'editor')->assertForbidden());

    expect(reeRole('Ngang')->trashed())->toBeFalse()->and(reePerms('Ngang'))->toBe(['entries.view'])
        ->and(reeRole('Dưới')->trashed())->toBeFalse()->and(reePerms('Dưới'))->toBe(['entries.view'])
        ->and(reeRole('editor')->trashed())->toBeFalse();
});

it('keeps the draft and says so when someone else renames the role being edited', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('openRole', 'Duyệt tin')->call('toggle', 'forms.view');
        Role::query()->where('site_id', 1)->where('name', 'Duyệt tin')->update(['name' => 'Duyệt tin mới']);

        $page->call('toggle', 'media.view')->assertSet('mode', 'sua')->assertSet('toast', __('rbac.role_change.changed_elsewhere'))->assertSet('toastReload', true)
            ->assertSet('sel', fn (array $sel): bool => collect($sel)->sort()->values()->all() === ['entries.view', 'forms.view', 'media.view']);
        // «Đóng» closes the message for good; a save says it again
        $page->call('dismissToast')->assertSet('toast', null)->call('toggle', 'forms.view')->assertSet('toast', null);
        $page->call('save')->assertSet('mode', 'sua')->assertSet('toast', __('rbac.role_change.changed_elsewhere'));
        // «Tải lại»: the role no longer goes by that name, so the list comes back
        $page->call('reloadList')->assertSet('mode', '');
    });

    expect(reePerms('Duyệt tin mới'))->toBe(['entries.view']);
});

it('brings back with «Hoàn tác» exactly the row this member deleted, not another of the same name', function (): void {
    reePlan();
    $first = reeCustom('Duyệt tin', 40, ['entries.view', 'entries.approve']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('askDelete', 'Duyệt tin')->assertSet('toastUndo', true);
        // meanwhile another role of the same name is made and deleted
        $other = Role::findOrCreate('Duyệt tin', PermissionRegistry::GUARD);
        $other->forceFill(['level' => 20, 'is_system' => false])->save();
        $other->syncPermissions(['media.view']);
        $other->delete();

        $page->call('undoDelete')->assertSet('toast', __('admin.roles.restored'));
    });

    expect(Role::query()->where('site_id', 1)->where('name', 'Duyệt tin')->pluck('id')->all())->toBe([$first->getKey()])
        ->and(reePerms('Duyệt tin'))->toBe(['entries.approve', 'entries.view']);
});

it('says whether a delete moved the role, so the screen offers «Hoàn tác» only for its own', function (): void {
    reePlan();
    $role = reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reeIn(1, fn () => expect(app(DeleteCustomRole::class)->handle($owner->fresh(), $role->fresh()))->toBeTrue()
        ->and(app(DeleteCustomRole::class)->handle($owner->fresh(), $role->fresh()))->toBeFalse());
});

it('asks before deleting a role that holds a permission the member may not give, since «Hoàn tác» could not bring it back', function (): void {
    reePlan();
    reeCustom('Trưởng nhóm', 60, ['roles.manage', 'media.view']);
    reeCustom('Kho ảnh', 20, ['media.view', 'media.upload']);   // the owner gave it «Tải lên», which the lead lacks
    $lead = reeMember('Trưởng nhóm');

    reePage($lead, function (Testable $page): void {
        $page->call('askDelete', 'Kho ảnh')->assertSet('ask', 'delete_kept')->assertSet('asking', true)->assertSet('toastUndo', false)
            ->assertSee(__('admin.roles.delete_kept_text'));
        expect(reeRole('Kho ảnh')->trashed())->toBeFalse();

        $page->call('confirmAsk')->assertSet('toast', __('admin.roles.deleted_off', ['role' => 'Kho ảnh']))
            ->assertSet('toastUndo', false)->assertSet('undoKey', '');
    });

    expect(reeRole('Kho ảnh')->trashed())->toBeTrue();
});

it('lets «Hoàn tác» bring back only what that member deleted on that website by that delete', function (string $case): void {
    reePlan();
    $role = reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');
    $other = reeMember('owner');

    reePage($owner, function (Testable $page) use ($case, $role, $other): void {
        $page->call('askDelete', 'Duyệt tin')->assertSet('toastUndo', true);
        if ($case === 'another member') {
            // the same sealed token replayed in another member's session
            $this->actingAs($other->fresh());
        } else {
            // brought back and deleted again later by someone else: no longer this member's delete
            $this->travel(2)->seconds();
            app(RestoreCustomRole::class)->handle($other->fresh(), $role->fresh());
            $this->travel(2)->seconds();
            app(DeleteCustomRole::class)->handle($other->fresh(), $role->fresh());
        }

        $page->call('undoDelete')->assertSet('undoKey', '');
        expect($page->get('toast'))->not->toBe(__('admin.roles.restored'));
    });

    expect(reeRole('Duyệt tin')->trashed())->toBeTrue();
})->with(['another member', 'trashed again']);

it('refuses «Hoàn tác» once the plan has dropped custom roles, and the role stays in the trash', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $page->call('askDelete', 'Duyệt tin')->assertSet('toastUndo', true);
        SiteFeature::query()->where('feature_key', 'rbac.custom_roles')->delete();

        $page->call('undoDelete')->assertSet('toastTone', 'error');
        expect($page->get('toast'))->not->toBe(__('admin.roles.restored'));
    });

    expect(reeRole('Duyệt tin')->trashed())->toBeTrue();
});

it('refuses a browser write to the state only the server sets', function (string $property, mixed $value): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    $owner = reeMember('owner');

    reePage($owner, fn (Testable $page) => expect(fn () => $page->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class));
})->with([
    'level' => ['level', 95],
    'sel' => ['sel', ['roles.manage']],
    'undoKey' => ['undoKey', 'forged'],
    'ask' => ['ask', 'delete'],
    'askRole' => ['askRole', 'Duyệt tin'],
]);

it('shows «+ Thêm ▾» on phones with «Hướng dẫn» right before it, the full label kept in its name', function (): void {
    reePlan();
    $owner = reeMember('owner');
    $words = '<span class="md:hidden">'.e(__('admin.list.add_short')).'</span><span class="max-md:sr-only">'.e(__('admin.roles.create')).'</span>';

    reePage($owner, fn (Testable $page) => $page
        ->assertSeeInOrder(['data-title-help', 'data-guide-open', 'data-guide="add"', 'aria-haspopup="menu"', $words], false));
    // locked while the plan has no custom roles: the same words, the reason in the title
    reeIn(1, fn () => SiteFeature::query()->where('feature_key', 'rbac.custom_roles')->delete());
    reePage($owner, fn (Testable $page) => $page->assertSeeInOrder(['data-guide="add"', 'aria-disabled="true"', $words], false));
});

it('locks every write for platform staff who opened the website directly, with the reason', function (): void {
    reePlan();
    reeCustom('Duyệt tin', 40, ['entries.view']);
    reeMember('owner');
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    reePage($staff, function (Testable $page): void {
        $lock = __('admin.staff.locked');
        $page->assertViewHas('writeLock', $lock)
            ->call('newRole')->assertSet('mode', '')->assertSet('toast', $lock)
            ->call('openRole', 'Duyệt tin')->assertSet('mode', 'xem')
            ->call('askDelete', 'Duyệt tin')->assertSet('ask', '')->assertSet('toast', $lock);
    });

    expect(reeRole('Duyệt tin')->trashed())->toBeFalse();
});

it('prints the guide with the list header, and again when the list comes back', function (): void {
    reePlan();
    $owner = reeMember('owner');

    reePage($owner, function (Testable $page): void {
        $guide = $page->viewData('guide');
        expect($guide)->toBeArray()->and(json_encode($guide, JSON_UNESCAPED_UNICODE))->toContain(__('guide.roles.part_main'))->toContain(__('guide.roles.more_title'));
        $page->call('setKind', 'sys')->assertViewHas('guide', null)
            ->call('newRole')->call('backToList')->assertViewHas('guide', fn (?array $g): bool => is_array($g));
    });
});

<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Member actions on the members screen. `users` is GLOBAL identity (D6 — no site_id, no RLS),
 * so RLS cannot stop a lookup by key: a member action must resolve its target INSIDE the current
 * site's membership, addressed by ulid, never by a client-supplied bigint (§2 #3/#4). The role
 * hierarchy is drawn ahead of time (rows you may not change show why) and enforced again on every
 * call: nobody changes a member at or above their own level, nor themselves.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** A fresh user holding the canonical `corporate` role template $name inside $site's team. */
function usersPageMember(int $site, string $name, array $attributes = []): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create($attributes);

    app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });

    return $user;
}

/** Role names $user holds in $site's team, sorted. */
function usersPageRoles(int $site, User $user): array
{
    return app(Tenancy::class)->enter($site, fn (): array => $user->fresh()?->roles()->orderBy('name')->pluck('name')->all() ?? []);
}

/** The screen as $actor sees it on site 1, then $then runs in the same site context. */
function usersPage(User $actor, Closure $then): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)));
}

it('refuses to pull an account from another site into this one', function (): void {
    $owner = usersPageMember(1, 'owner');
    $outsider = usersPageMember(2, 'viewer');
    usersPageMember(1, 'viewer'); // materialize the grantable role in site 1

    usersPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $outsider->ulid)
        ->assertSet('panelFor', '')
        ->assertSee(__('admin.member_not_found'))
        // a forged panel target is resolved again on save, among this site's members only
        ->set('panelFor', $outsider->ulid)
        ->set('panelRoles', ['viewer'])
        ->call('saveRoles')
        ->assertDontSee($outsider->email));

    expect(usersPageRoles(1, $outsider))->toBe([])
        ->and(usersPageRoles(2, $outsider))->toBe(['viewer']);
});

it('refuses the raw bigint id of an account that belongs to no site at all', function (): void {
    $owner = usersPageMember(1, 'owner');
    $loner = User::factory()->create();
    usersPageMember(1, 'viewer');

    usersPage($owner, fn (Testable $page) => $page
        ->call('openPanel', (string) $loner->id) // the enumeration an attacker would run
        ->assertSet('panelFor', '')
        ->call('askRemove', (string) $loner->id)
        ->assertSet('confirmOpen', false)
        ->assertDontSee($loner->email));

    expect(usersPageRoles(1, $loner))->toBe([]);
});

it('gives a member exactly the ticked roles, and undo gives the earlier ones back', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer', ['name' => 'Đỗ Thu Hà']);
    usersPageMember(1, 'editor');
    usersPageMember(1, 'author');

    $page = usersPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $member->ulid)
        ->assertSet('panelRoles', ['viewer'])
        ->set('panelRoles', ['editor', 'author'])
        ->call('saveRoles')
        ->assertHasNoErrors()
        ->assertSet('panelFor', '')
        ->assertSee(__('admin.members.saved_roles', ['name' => 'Đỗ Thu Hà'])));

    expect(usersPageRoles(1, $member))->toBe(['author', 'editor']);

    app(Tenancy::class)->enter(1, fn () => $page->call('undo')->assertSee(__('admin.members.undone', ['name' => 'Đỗ Thu Hà'])));

    expect(usersPageRoles(1, $member))->toBe(['viewer']);
});

it('keeps a member in at least one role, saying why in plain words', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer');

    usersPage($owner, fn (Testable $page) => $page
        ->call('openPanel', $member->ulid)
        ->set('panelRoles', [])
        ->call('saveRoles')
        ->assertSet('panelError', __('admin.members.panel_need_one'))
        ->assertSee(__('admin.members.panel_need_one')));

    expect(usersPageRoles(1, $member))->toBe(['viewer']);
});

it('never lets a member change someone at or above their own level, nor themselves', function (): void {
    $owner = usersPageMember(1, 'owner', ['name' => 'Trần Văn Đức']);
    $admin = usersPageMember(1, 'admin', ['name' => 'Lê Thị Hồng']);
    $peer = usersPageMember(1, 'admin', ['name' => 'Phạm Quốc Bảo']);
    $editor = usersPageMember(1, 'editor', ['name' => 'Vũ Minh Tâm']);

    $html = usersPage($admin, function (Testable $page) use ($owner, $admin, $peer): string {
        foreach ([$owner, $admin, $peer] as $target) {
            $page->call('openPanel', $target->ulid)->assertSet('panelFor', '')
                ->call('askRemove', $target->ulid)->assertSet('confirmOpen', false);
        }
        // a forged panel or removal target is checked again when it is saved
        $page->set('panelFor', $owner->ulid)->set('panelRoles', ['viewer'])->call('saveRoles')
            ->set('removeFor', $peer->ulid)->call('confirmRemove')
            ->assertSee(__('admin.members.cannot_touch'));

        return $page->html();
    });

    expect(usersPageRoles(1, $owner))->toBe(['owner'])
        ->and(usersPageRoles(1, $admin))->toBe(['admin'])
        ->and(usersPageRoles(1, $peer))->toBe(['admin'])
        // the rows say why there is no button; the editor's row has one
        ->and($html)->toContain(__('admin.members.why_level'))
        ->toContain(__('admin.members.why_you'))
        ->toContain("member-edit-{$editor->ulid}")
        ->not->toContain("member-edit-{$owner->ulid}")
        ->not->toContain("member-edit-{$peer->ulid}")
        ->not->toContain("member-edit-{$admin->ulid}");
});

it('offers in the panel only the roles below the actor, keeping a held role it may not grant locked', function (): void {
    $admin = usersPageMember(1, 'admin');
    $member = usersPageMember(1, 'editor');
    usersPageMember(1, 'owner');
    usersPageMember(1, 'viewer');

    $html = usersPage($admin, fn (Testable $page): string => $page->call('openPanel', $member->ulid)->html());
    $panel = substr($html, (int) strpos($html, 'members-panel-'));

    expect($panel)->toContain('value="viewer"')
        ->toContain('value="editor"')
        ->not->toContain('value="admin"')
        ->not->toContain('value="owner"');
});

it('removes a real member after the confirmation, and leaves a non-member untouched with a plain-language error', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer', ['name' => 'Ngô Bảo Châu']);
    $outsider = usersPageMember(2, 'viewer');

    usersPage($owner, fn (Testable $page) => $page
        ->call('askRemove', $outsider->ulid)
        ->assertSee(__('admin.member_not_found'))
        ->assertSet('confirmOpen', false)
        ->call('askRemove', $member->ulid)
        ->assertSet('confirmOpen', true)
        ->assertSee(__('admin.members.rm_l3'))
        ->call('confirmRemove')
        ->assertSee(__('admin.members.removed', ['name' => 'Ngô Bảo Châu'])));

    expect(usersPageRoles(1, $member))->toBe([])
        ->and(usersPageRoles(2, $outsider))->toBe(['viewer']);
});

it('changes nobody while support staff are signed in as the member, and says so', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer');
    usersPageMember(1, 'editor');

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

    usersPage($owner, fn (Testable $page) => $page
        ->assertSee(__('admin.members.support_title'))
        ->call('openPanel', $member->ulid)
        ->assertSet('panelFor', '')
        ->assertSee(__('admin.support.locked'))
        ->set('panelFor', $member->ulid)->set('panelRoles', ['editor'])->call('saveRoles')
        ->set('removeFor', $member->ulid)->call('confirmRemove'));

    expect(usersPageRoles(1, $member))->toBe(['viewer']);
});

it('names roles in plain words in the member list and the role filter, keeping the role key as the value', function (): void {
    $owner = usersPageMember(1, 'owner');
    usersPageMember(1, 'viewer');

    $html = usersPage($owner, fn (Testable $page): string => $page->html());

    expect($html)->toContain('>Chỉ xem</span>')
        ->toContain('value="viewer"')
        ->not->toContain('>viewer<');
});

it('never prints a member bigint id into the page — actions and keys carry the ulid', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer');

    $html = usersPage($owner, fn (Testable $page): string => $page->call('openPanel', $member->ulid)->html());

    expect($html)->toContain($member->ulid)
        ->not->toContain("openPanel({$member->id})")
        ->not->toContain("askRemove({$member->id})")
        ->not->toContain("member-{$member->id}\"")
        ->not->toContain("members-panel-{$member->id}\"");
});

it('finds a member by name whatever the accents, or by part of the email', function (): void {
    $owner = usersPageMember(1, 'owner', ['name' => 'Trần Văn Đức']);
    usersPageMember(1, 'editor', ['name' => 'Đỗ Thu Hà', 'email' => 'ha.do@example.test']);
    usersPageMember(1, 'viewer', ['name' => 'Lê Quốc Việt', 'email' => 'viet.le@example.test']);

    usersPage($owner, fn (Testable $page) => $page
        ->set('search', 'do thu ha')
        ->assertSee('Đỗ Thu Hà')->assertDontSee('Lê Quốc Việt')
        ->set('search', 'ĐỖ THU HÀ')
        ->assertSee('Đỗ Thu Hà')->assertDontSee('Lê Quốc Việt')
        ->set('search', 'viet.le@')
        ->assertSee('Lê Quốc Việt')->assertDontSee('Đỗ Thu Hà')
        // `%` matches only itself, never "anything"
        ->set('search', 'viet%')
        ->assertDontSee('Lê Quốc Việt')->assertDontSee('Đỗ Thu Hà')
        ->assertSee(__('admin.members.nomatch')));
});

it('keeps only the members of the chosen roles', function (): void {
    $owner = usersPageMember(1, 'owner', ['name' => 'Trần Văn Đức']);
    usersPageMember(1, 'editor', ['name' => 'Đỗ Thu Hà']);
    usersPageMember(1, 'viewer', ['name' => 'Lê Quốc Việt']);
    usersPageMember(2, 'editor', ['name' => 'Hoàng Minh Khôi']); // another site's editor never shows

    usersPage($owner, fn (Testable $page) => $page
        ->set('groups', ['editor'])
        ->assertSee('Đỗ Thu Hà')->assertDontSee('Lê Quốc Việt')->assertDontSee('Hoàng Minh Khôi')
        ->assertSee(__('admin.members.match_of', ['n' => '1', 'm' => '3', 'unit' => __('admin.members.unit')]))
        ->call('clearFilters')
        ->assertSee('Lê Quốc Việt'));
});

it('pages the members by cursor, forward and back, meeting each one once', function (): void {
    $owner = usersPageMember(1, 'owner', ['name' => 'Đặng Anh Tú']);
    foreach (range(1, 24) as $i) {
        usersPageMember(1, 'viewer', ['name' => sprintf('Nguyễn %02d Mỹ', $i)]);
    }
    $all = app(Tenancy::class)->enter(1, fn (): array => DB::table('users')->orderBy('name')->orderBy('id')->pluck('ulid')->all());

    $seen = usersPage($owner, function (Testable $page): array {
        $page->set('per', 10);
        $pages = [];
        for ($i = 0; $i < 3; $i++) {
            preg_match_all('/id="member-([0-9A-Z]{26})"/', $page->html(), $m);
            $pages[] = $m[1];
            preg_match('/wire:click="older\(\'([0-9A-Z]{26})\'\)"/', $page->html(), $next);
            if ($next === []) {
                break;
            }
            $page->call('older', $next[1]);
        }
        preg_match('/wire:click="newer\(\'([0-9A-Z]{26})\'\)"/', $page->html(), $back);
        $page->call('newer', $back[1]);
        preg_match_all('/id="member-([0-9A-Z]{26})"/', $page->html(), $m);

        return ['pages' => $pages, 'back' => $m[1]];
    });

    expect(array_merge(...$seen['pages']))->toBe($all)
        ->and(count($seen['pages'][2]))->toBe(5)
        ->and($seen['back'])->toBe($seen['pages'][1]);
});

it('draws the failed card with a retry when a list read fails, and the page still answers', function (): void {
    $owner = usersPageMember(1, 'owner', ['name' => 'Đặng Anh Tú']);
    usersPageMember(1, 'viewer', ['name' => 'Ngô Bảo Châu']);

    $fail = true;
    DB::beforeExecuting(function (string $query) use (&$fail): void {
        if ($fail && str_contains($query, 'grouping sets')) {
            $fail = false;
            // a statement Postgres itself refuses: it aborts the transaction it runs in, as a timeout would
            DB::select('select 1 / 0');
        }
    });

    $this->actingAs($owner)->get('http://a.test/admin/users')->assertOk()
        ->assertSee(__('admin.members.error_title'))->assertSee(__('admin.members.retry'))->assertDontSee('Ngô Bảo Châu');

    $this->actingAs($owner)->get('http://a.test/admin/users')->assertOk()
        ->assertDontSee(__('admin.members.error_title'))->assertSee('Ngô Bảo Châu');
});

it('refuses an admin who ticks a role at or above their own level, changing nothing', function (): void {
    $admin = usersPageMember(1, 'admin', ['name' => 'Lê Thị Hồng']);
    $editor = usersPageMember(1, 'editor', ['name' => 'Vũ Minh Tâm']);
    usersPageMember(1, 'owner');

    foreach ([['admin'], ['owner'], ['editor', 'owner']] as $forged) {
        usersPage($admin, fn (Testable $page) => $page
            ->call('openPanel', $editor->ulid)
            ->set('panelRoles', $forged)
            ->call('saveRoles')
            ->assertSet('panelError', __('admin.role_change_denied'))
            ->assertSet('panelFor', $editor->ulid)
            ->assertSet('undoRoles', null));
    }

    expect(usersPageRoles(1, $editor))->toBe(['editor']);
});

it('refuses a forged undo — another site’s member, a role at or above the actor, an oversized or repeated list — changing nothing', function (): void {
    $admin = usersPageMember(1, 'admin', ['name' => 'Lê Thị Hồng']);
    $editor = usersPageMember(1, 'editor', ['name' => 'Vũ Minh Tâm']);
    $outsider = usersPageMember(2, 'viewer');
    usersPageMember(1, 'owner');
    usersPageMember(1, 'viewer');

    $forged = [
        ['ulid' => $outsider->ulid, 'roles' => ['viewer']],
        ['ulid' => $editor->ulid, 'roles' => ['owner']],
        ['ulid' => $editor->ulid, 'roles' => ['admin']],
        // unknown names would be dropped, leaving a valid grant: the list itself is refused
        ['ulid' => $editor->ulid, 'roles' => array_merge(['viewer'], array_map(fn (int $i): string => "nhóm-{$i}", range(1, 50)))],
        ['ulid' => $editor->ulid, 'roles' => ['viewer', 'viewer']],
    ];
    foreach ($forged as $undo) {
        usersPage($admin, fn (Testable $page) => $page
            ->set('undoRoles', $undo)
            ->call('undo')
            ->assertSee(__('admin.members.undo_failed'))
            ->assertSet('undoRoles', null));
    }

    expect(usersPageRoles(1, $editor))->toBe(['editor'])
        ->and(usersPageRoles(2, $outsider))->toBe(['viewer']);
});

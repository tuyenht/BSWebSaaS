<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * Member actions on the tenant Users page. `users` is GLOBAL identity (D6 — no site_id, no RLS),
 * so RLS cannot stop a lookup by key: a member action must resolve its target INSIDE the current
 * site's membership, addressed by ulid, never by a client-supplied bigint (§2 #3/#4). Before this,
 * `changeRole(<any id>, 'viewer')` let the owner of ANY site pull an arbitrary platform account
 * into their site — and the members table then printed that account's name + email.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A fresh user holding the canonical `corporate` role template $name inside $site's team. */
function usersPageMember(int $site, string $name): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create();

    app(Tenancy::class)->enter($site, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });

    return $user;
}

/** Role names $user holds in $site's team. */
function usersPageRoles(int $site, User $user): array
{
    return app(Tenancy::class)->enter($site, fn (): array => $user->fresh()?->roles()->pluck('name')->all() ?? []);
}

it('refuses to pull an account from another site into this one via changeRole', function (): void {
    $owner = usersPageMember(1, 'owner');
    $outsider = usersPageMember(2, 'viewer');
    usersPageMember(1, 'viewer'); // materialize the grantable role in site 1

    app(Tenancy::class)->enter(1, function () use ($owner, $outsider): void {
        Livewire::actingAs($owner->fresh())
            ->test(Index::class)
            ->call('changeRole', $outsider->ulid, 'viewer')
            ->assertDontSee($outsider->email);
    });

    expect(usersPageRoles(1, $outsider))->toBe([])
        ->and(usersPageRoles(2, $outsider))->toBe(['viewer']);
});

it('refuses the raw bigint id of an account that belongs to no site at all', function (): void {
    $owner = usersPageMember(1, 'owner');
    $loner = User::factory()->create();
    usersPageMember(1, 'viewer');

    app(Tenancy::class)->enter(1, function () use ($owner, $loner): void {
        Livewire::actingAs($owner->fresh())
            ->test(Index::class)
            ->call('changeRole', $loner->id, 'viewer') // the enumeration an attacker would run
            ->assertDontSee($loner->email);
    });

    expect(usersPageRoles(1, $loner))->toBe([]);
});

it('changes the role of a real member addressed by ulid', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer');
    usersPageMember(1, 'editor'); // materialize the target role

    app(Tenancy::class)->enter(1, function () use ($owner, $member): void {
        Livewire::actingAs($owner->fresh())
            ->test(Index::class)
            ->call('changeRole', $member->ulid, 'editor');
    });

    expect(usersPageRoles(1, $member))->toBe(['editor']);
});

it('removes a real member but leaves a non-member untouched with a plain-language error', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer');
    $outsider = usersPageMember(2, 'viewer');

    app(Tenancy::class)->enter(1, function () use ($owner, $member, $outsider): void {
        Livewire::actingAs($owner->fresh())
            ->test(Index::class)
            ->call('removeMember', $outsider->ulid)
            ->assertSee(__('admin.member_not_found'))
            ->call('removeMember', $member->ulid);
    });

    expect(usersPageRoles(1, $member))->toBe([])
        ->and(usersPageRoles(2, $outsider))->toBe(['viewer']);
});

it('never prints a member bigint id into the page — actions and keys carry the ulid', function (): void {
    $owner = usersPageMember(1, 'owner');
    $member = usersPageMember(1, 'viewer');

    $html = app(Tenancy::class)->enter(1, fn (): string => Livewire::actingAs($owner->fresh())->test(Index::class)->html());

    expect($html)->toContain($member->ulid)
        ->not->toContain("changeRole({$member->id},")
        ->not->toContain("removeMember({$member->id})")
        ->not->toContain("member-{$member->id}\"");
});

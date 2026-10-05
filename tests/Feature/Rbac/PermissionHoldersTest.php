<?php

use App\Modules\Admin\Listeners\TellSiteAboutDomain;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Messages\SiteNotice;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Events\DomainNeedsAttention;
use App\Modules\Workflow\Models\WorkflowTransition;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/*
 * "Who on this site holds permission X" — User::holdingPermission(). Every site carries its own
 * copy of the role templates, so spatie's permission() scope, which first collects the id of EVERY
 * role on EVERY site holding X and binds them one by one, grows a parameter per site and fails past
 * PostgreSQL's 65,535-parameter limit long before 10,000 sites. The scope asks by name through the
 * same team-scoped relations instead: the same people, a fixed number of parameters.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** $count more sites' copies of a role holding $permissions, as provisioning would leave them. */
function roleCopiesOnOtherSites(int $count, array $permissions): void
{
    $admin = DB::connection('pgsql_admin');
    $permissionIds = $admin->table('permissions')->whereIn('name', $permissions)->pluck('id');

    $first = max(1000, (int) $admin->table('roles')->max('site_id') + 1);

    for ($site = $first; $site < $first + $count; $site++) {
        $roleId = $admin->table('roles')->insertGetId([
            'site_id' => $site, 'name' => 'editor-copy', 'guard_name' => PermissionRegistry::GUARD,
            'level' => 10, 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin->table('role_has_permissions')->insert(
            $permissionIds->map(fn (int $id): array => ['permission_id' => $id, 'role_id' => $roleId])->all(),
        );
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** The largest number of bound parameters any single statement run by $callback carried. */
function widestStatement(Closure $callback): int
{
    $widest = 0;
    $listening = true;
    DB::listen(function (QueryExecuted $query) use (&$widest, &$listening): void {
        if ($listening) {
            $widest = max($widest, count($query->bindings));
        }
    });

    try {
        $callback();
    } finally {
        $listening = false;
    }

    return $widest;
}

/**
 * @return list<int>
 */
function holderIds(string $permission): array
{
    return User::query()->holdingPermission($permission)->orderBy('id')->pluck('id')->all();
}

it('finds the same people as spatie\'s scope: direct grants, roles, site-wide roles — and no one from another site', function (): void {
    $tenancy = app(Tenancy::class);
    [$direct, $viaRole, $viaGlobalRole, $elsewhere, $without] = User::factory()->count(5)->create()->all();

    $global = $tenancy->enter(1, function () use ($direct, $viaRole, $without): Role {
        $direct->givePermissionTo('domains.manage');
        grantRole($viaRole, 'manager', ['domains.manage']);
        grantRole($without, 'viewer', ['entries.view']);

        // A role every site shares (site_id NULL) still counts where it is assigned.
        return Role::create(['name' => 'shared-manager', 'guard_name' => PermissionRegistry::GUARD, 'site_id' => null]);
    });
    $global->givePermissionTo('domains.manage');
    $tenancy->enter(1, fn () => $viaGlobalRole->assignRole($global));

    // Holds it on site 2 only — and a role of site 2 holds it, which must not reach site 1.
    $tenancy->enter(1, fn () => grantRole($elsewhere, 'viewer', ['entries.view']));
    $tenancy->enter(2, fn () => grantRole($elsewhere, 'manager', ['domains.manage']));

    $onSiteOne = $tenancy->enter(1, fn (): array => holderIds('domains.manage'));
    $spatieOnSiteOne = $tenancy->enter(1, fn (): array => User::permission('domains.manage')->orderBy('id')->pluck('id')->all());
    $onSiteTwo = $tenancy->enter(2, fn (): array => holderIds('domains.manage'));

    expect($onSiteOne)->toBe([$direct->id, $viaRole->id, $viaGlobalRole->id])
        ->and($onSiteOne)->toBe($spatieOnSiteOne)
        ->and($onSiteTwo)->toBe([$elsewhere->id]);
});

it('takes several names as "any of them", like spatie\'s scope', function (): void {
    $tenancy = app(Tenancy::class);
    [$publisher, $manager, $viewer] = User::factory()->count(3)->create()->all();

    $tenancy->enter(1, function () use ($publisher, $manager, $viewer): void {
        grantRole($publisher, 'publisher', ['entries.publish']);
        grantRole($manager, 'manager', ['domains.manage']);
        grantRole($viewer, 'viewer', ['entries.view']);
    });

    $names = ['entries.publish', 'domains.manage'];

    expect($tenancy->enter(1, fn (): array => User::query()->holdingPermission($names)->orderBy('id')->pluck('id')->all()))
        ->toBe([$publisher->id, $manager->id])
        ->toBe($tenancy->enter(1, fn (): array => User::permission($names)->orderBy('id')->pluck('id')->all()));
});

it('finds nobody without a team, and nobody for a permission that does not exist', function (): void {
    $tenancy = app(Tenancy::class);
    $holder = User::factory()->create();
    $tenancy->enter(1, fn () => $holder->givePermissionTo('domains.manage'));

    app(TeamContext::class)->force(null);
    $noTeam = holderIds('domains.manage');
    app(TeamContext::class)->forget();

    expect($noTeam)->toBe([])
        ->and($tenancy->enter(1, fn (): array => holderIds('no.such.permission')))->toBe([]);
});

it('binds a fixed number of parameters however many sites carry the role', function (): void {
    $tenancy = app(Tenancy::class);
    $bindings = fn (Closure $scope): int => $tenancy->enter(1, fn (): int => count($scope(User::query())->getBindings()));
    $ours = fn ($query) => $query->holdingPermission('domains.manage');
    $spatie = fn ($query) => $query->permission('domains.manage');

    roleCopiesOnOtherSites(1, ['domains.manage']);
    [$oursFew, $spatieFew] = [$bindings($ours), $bindings($spatie)];

    roleCopiesOnOtherSites(49, ['domains.manage']);
    [$oursMany, $spatieMany] = [$bindings($ours), $bindings($spatie)];

    expect($oursMany)->toBe($oursFew)
        ->and($spatieMany - $spatieFew)->toBe(49);   // the growth this scope exists to avoid
});

it('keeps every recipient lookup flat as sites are added — notices, domain warnings, hand-off lists', function (): void {
    Notification::fake();
    $tenancy = app(Tenancy::class);
    $listener = app(TellSiteAboutDomain::class);
    $resolver = app(EntryWorkflowResolver::class);
    $moves = [new WorkflowTransition(['required_permission' => 'entries.publish']), new WorkflowTransition(['required_permission' => 'entries.submit'])];
    $entry = (new Entry)->forceFill(['author_id' => 1]);

    $widths = function () use ($tenancy, $listener, $resolver, $moves, $entry): array {
        return [
            widestStatement(fn () => $tenancy->enter(1, fn () => Notify::holdersOf('domains.manage', 'lead.new', []))),
            widestStatement(fn () => $listener->handle(new DomainNeedsAttention(1, 'failing', ['shop.example']))),
            $tenancy->enter(1, fn (): int => count($resolver->membersWhoMayApply($moves, $entry)?->getBindings() ?? [])),
        ];
    };

    $permissions = ['domains.manage', 'entries.publish', 'entries.submit', 'entries.edit.any', 'entries.edit.own'];
    roleCopiesOnOtherSites(1, $permissions);
    $few = $widths();
    roleCopiesOnOtherSites(49, $permissions);

    expect($widths())->toBe($few);
});

it('reaches the same recipients through Notify in one query, without loading every site\'s roles first', function (): void {
    Notification::fake();
    $tenancy = app(Tenancy::class);
    [$manager, $actor, $viewer] = User::factory()->count(3)->create()->all();
    $tenancy->enter(1, function () use ($manager, $actor, $viewer): void {
        grantRole($manager, 'manager', ['domains.manage']);
        grantRole($actor, 'manager', ['domains.manage']);
        grantRole($viewer, 'viewer', ['entries.view']);
    });
    roleCopiesOnOtherSites(5, ['domains.manage']);
    // A cold cache: spatie's scope would first load every permission and every role of every site.
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $queries = $tenancy->enter(1, fn (): int => countQueries(
        fn () => Notify::holdersOf('domains.manage', 'lead.new', [], except: $actor),
    ));

    Notification::assertSentTo($manager, SiteNotice::class);
    Notification::assertNotSentTo([$actor, $viewer], SiteNotice::class);
    expect($queries)->toBe(1);
});

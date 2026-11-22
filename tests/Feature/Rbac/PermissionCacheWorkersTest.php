<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Listeners\SyncPermissionCache;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\TaskReceived;
use Laravel\Octane\Events\TickReceived;
use Spatie\Permission\PermissionRegistrar;

/*
 * Octane keeps one PermissionRegistrar per worker for the worker's whole life, and the package
 * keeps the loaded catalog (which role grants which permission) in it. A role or permission change
 * on one worker must reach every other worker at that worker's next request, task, tick or job,
 * for the price of one cache read when nothing changed.
 *
 * A worker is modelled as its own registrar instance over the same cache store and database (what
 * separate Octane workers share); its "next request" runs exactly the app's own listeners the event
 * dispatcher holds for RequestReceived.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();

    DB::connection('pgsql_admin')->table('sites')->where('id', 1)
        ->update(['site_type_id' => DB::table('site_types')->where('key', 'corporate')->value('id')]);
    app(Tenancy::class)->enter(1, fn () => SiteFeature::query()->create(['feature_key' => 'rbac.custom_roles', 'value' => true]));
});

/** The shared token naming the current permission catalog (null = none written yet). */
function permissionVersion(): mixed
{
    return cache()->get(config('permission.cache.key').'.version');
}

/** Another Octane worker: its own registrar, the same cache store and database. */
function otherWorker(): PermissionRegistrar
{
    $class = app(PermissionRegistrar::class)::class;

    return new $class(app('cache'));
}

/** Run $fn as if on $worker. */
function onWorker(PermissionRegistrar $worker, Closure $fn): mixed
{
    $own = app(PermissionRegistrar::class);
    app()->instance(PermissionRegistrar::class, $worker);

    try {
        return $fn();
    } finally {
        app()->instance(PermissionRegistrar::class, $own);
    }
}

/** What this app does when Octane hands $worker its next unit of work ($event names which kind). */
function nextUnitOfWork(PermissionRegistrar $worker, string $event = RequestReceived::class): void
{
    onWorker($worker, function () use ($event): void {
        foreach (app('events')->getRawListeners()[$event] ?? [] as $listener) {
            if (is_string($listener) && str_starts_with($listener, 'App\\')) {
                app($listener)->handle();
            }
        }
    });
}

/** Does $user hold $permission on site 1, checked on $worker as a fresh request would (user re-read)? */
function workerSays(PermissionRegistrar $worker, User $user, string $permission): bool
{
    nextUnitOfWork($worker);

    return onWorker($worker, fn (): bool => app(Tenancy::class)->enter(1, fn (): bool => User::query()->findOrFail($user->getKey())->can($permission)));
}

function cacheOwner(): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')['owner'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($user, $tpl): void {
        $role = Role::findOrCreate('owner', PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions']);
        $user->assignRole($role);
    });
    app(TeamContext::class)->forget();

    return $user;
}

/** A custom role "Kế toán" on site 1 created through the action, plus a member who holds it. */
function accountantWith(User $owner, array $permissions): array
{
    $role = app(Tenancy::class)->enter(1, fn (): Role => app(CreateCustomRole::class)->handle($owner->fresh(), 'Kế toán', 20, $permissions));
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => $member->assignRole($role));
    app(TeamContext::class)->forget();

    return [$role, $member];
}

it('stops a permission removed from a custom group on another worker at its next request', function (): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['forms.view', 'entries.view']);
    $b = otherWorker();

    expect(workerSays($b, $member, 'forms.view'))->toBeTrue(); // worker B has loaded the catalog

    app(Tenancy::class)->enter(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán', 20, ['entries.view']));

    expect(workerSays($b, $member, 'forms.view'))->toBeFalse()
        ->and(workerSays($b, $member, 'entries.view'))->toBeTrue()
        ->and(workerSays(app(PermissionRegistrar::class), $member, 'forms.view'))->toBeFalse();
});

it('shows a permission added to a custom group on another worker at its next request', function (): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['entries.view']);
    $b = otherWorker();

    expect(workerSays($b, $member, 'forms.view'))->toBeFalse();

    app(Tenancy::class)->enter(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán', 20, ['entries.view', 'forms.view']));

    expect(workerSays($b, $member, 'forms.view'))->toBeTrue();
});

it('shows a new custom group and a deleted one on another worker at its next request', function (): void {
    $owner = cacheOwner();
    $b = otherWorker();
    expect(workerSays($b, $owner, 'entries.view'))->toBeTrue(); // B loaded the catalog before the group existed

    [$role, $member] = accountantWith($owner, ['forms.view']);
    expect(workerSays($b, $member, 'forms.view'))->toBeTrue();

    app(Tenancy::class)->enter(1, fn () => app(RevokeRole::class)->handle($owner->fresh(), $member->fresh(), $role->fresh()));
    $before = permissionVersion();
    app(Tenancy::class)->enter(1, fn () => app(DeleteCustomRole::class)->handle($owner->fresh(), $role->fresh()));

    expect(permissionVersion())->not->toBe($before)
        ->and(workerSays($b, $member, 'forms.view'))->toBeFalse();
});

it('needs no new version for giving or taking a role: members\' roles are read on each request', function (): void {
    $owner = cacheOwner();
    [$role] = accountantWith($owner, ['forms.view']);
    $member = User::factory()->create();
    $b = otherWorker();
    expect(workerSays($b, $member, 'forms.view'))->toBeFalse();
    $version = permissionVersion();

    app(Tenancy::class)->enter(1, fn () => app(AssignRole::class)->handle($owner->fresh(), $member->fresh(), $role->fresh()));
    expect(workerSays($b, $member, 'forms.view'))->toBeTrue();

    app(Tenancy::class)->enter(1, fn () => app(RevokeRole::class)->handle($owner->fresh(), $member->fresh(), $role->fresh()));
    expect(workerSays($b, $member, 'forms.view'))->toBeFalse()
        ->and(permissionVersion())->toBe($version);
});

it('never keeps a grant whose transaction rolled back, on any worker', function (): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['entries.view']);
    $b = otherWorker();
    expect(workerSays($b, $member, 'forms.view'))->toBeFalse();

    try {
        app(Tenancy::class)->enter(1, function () use ($role, $member): void {
            $role->fresh()->givePermissionTo('forms.view');
            // the writing worker reads the catalog inside the transaction: it sees the grant
            expect(User::query()->findOrFail($member->getKey())->can('forms.view'))->toBeTrue();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    $c = otherWorker(); // a worker that had not loaded anything yet

    expect(workerSays($b, $member, 'forms.view'))->toBeFalse()
        ->and(workerSays($c, $member, 'forms.view'))->toBeFalse()
        ->and(workerSays(app(PermissionRegistrar::class), $member, 'forms.view'))->toBeFalse();
});

it('keeps a catalog read inside an open transaction out of the shared cache', function (): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['entries.view']);
    $registrar = app(PermissionRegistrar::class);
    expect(workerSays($registrar, $member, 'entries.view'))->toBeTrue();
    $sharedKey = $registrar->cacheKey;

    $written = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
        $written[] = $event->key;
    });

    app(Tenancy::class)->enter(1, function () use ($role, $member, $sharedKey, &$written): void {
        $role->fresh()->givePermissionTo('forms.view');
        expect(User::query()->findOrFail($member->getKey())->can('forms.view'))->toBeTrue()
            // if the process died here, no worker could load the uncommitted grant
            ->and(cache()->get($sharedKey))->toBeNull()
            ->and($written)->toBe([]);
    });

    expect(workerSays(otherWorker(), $member, 'forms.view'))->toBeTrue();
});

it('drops a grant made in a savepoint that rolled back while the outer transaction committed', function (): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['entries.view']);
    $b = otherWorker();
    expect(workerSays($b, $member, 'forms.view'))->toBeFalse();

    app(Tenancy::class)->enter(1, function () use ($role, $member): void {
        try {
            DB::transaction(function () use ($role, $member): void {
                $role->fresh()->givePermissionTo('forms.view');
                expect(User::query()->findOrFail($member->getKey())->can('forms.view'))->toBeTrue();

                throw new RuntimeException('savepoint rolled back');
            });
        } catch (RuntimeException) {
        }
        $role->fresh()->givePermissionTo('domains.view');
    });

    expect(workerSays($b, $member, 'forms.view'))->toBeFalse()
        ->and(workerSays($b, $member, 'domains.view'))->toBeTrue()
        ->and(workerSays(otherWorker(), $member, 'forms.view'))->toBeFalse()
        ->and(workerSays(app(PermissionRegistrar::class), $member, 'forms.view'))->toBeFalse();
});

it('writes one new version per transaction, however many changes it holds', function (): void {
    $owner = cacheOwner();
    [$role] = accountantWith($owner, ['entries.view']);
    $versionKey = config('permission.cache.key').'.version';

    $writes = 0;
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$writes, $versionKey): void {
        $writes += $event->key === $versionKey ? 1 : 0;
    });

    // rename + re-level + re-permission: a role save and a permission sync, each flushing the package's cache
    app(Tenancy::class)->enter(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán trưởng', 30, ['entries.view', 'forms.view']));

    expect($writes)->toBe(1);
});

it('starts a new version when the token is missing from the cache, never the bare catalog key', function (): void {
    $owner = cacheOwner();
    [, $member] = accountantWith($owner, ['forms.view']);
    $base = config('permission.cache.key');
    $b = otherWorker();
    expect(workerSays($b, $member, 'forms.view'))->toBeTrue();

    cache()->forget($base.'.version'); // evicted
    cache()->put($base, ['stale' => true], 3600); // what an older build left under the bare key

    expect(workerSays($b, $member, 'forms.view'))->toBeTrue()
        ->and(permissionVersion())->toBeString()
        ->and($b->cacheKey)->toBe($base.'.'.permissionVersion());
});

it('ignores a stale catalog another worker writes back after the change', function (): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['forms.view', 'entries.view']);
    $registrar = app(PermissionRegistrar::class);
    expect(workerSays($registrar, $member, 'forms.view'))->toBeTrue();
    $staleKey = $registrar->cacheKey;
    $staleCatalog = cache()->get($staleKey);

    app(Tenancy::class)->enter(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán', 20, ['entries.view']));
    // a worker that read the rows before the commit finishes its rebuild late
    cache()->put($staleKey, $staleCatalog, 3600);

    expect(workerSays(otherWorker(), $member, 'forms.view'))->toBeFalse();
});

it('costs one cache read per request and no reload when nothing changed', function (): void {
    $owner = cacheOwner();
    [, $member] = accountantWith($owner, ['forms.view']);
    $b = otherWorker();
    expect(workerSays($b, $member, 'forms.view'))->toBeTrue();

    $reads = [];
    Event::listen(RetrievingKey::class, function (RetrievingKey $event) use (&$reads): void {
        $reads[] = $event->key;
    });
    $user = User::query()->findOrFail($member->getKey());
    DB::enableQueryLog();
    DB::flushQueryLog();

    nextUnitOfWork($b);
    $allowed = onWorker($b, fn (): bool => app(Tenancy::class)->enter(1, fn (): bool => $user->can('forms.view')));

    $catalogQueries = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'role_has_permissions')); // the catalog: every permission with its roles

    expect($allowed)->toBeTrue()
        ->and($reads)->toBe([config('permission.cache.key').'.version'])
        ->and($catalogQueries)->toBeEmpty();
});

it('syncs at the start of Octane tasks and ticks and of queued jobs too', function (string $event): void {
    $owner = cacheOwner();
    [$role, $member] = accountantWith($owner, ['forms.view', 'entries.view']);
    $b = otherWorker();
    expect(workerSays($b, $member, 'forms.view'))->toBeTrue();

    app(Tenancy::class)->enter(1, fn () => app(UpdateCustomRole::class)->handle($owner->fresh(), $role->fresh(), 'Kế toán', 20, ['entries.view']));

    if ($event === JobProcessing::class) {
        onWorker($b, fn () => event(new JobProcessing('redis', Mockery::mock(Job::class)->allows('payload')->andReturn([])->getMock())));
    } else {
        nextUnitOfWork($b, $event);
    }

    expect(onWorker($b, fn (): bool => app(Tenancy::class)->enter(1, fn (): bool => User::query()->findOrFail($member->getKey())->can('forms.view'))))->toBeFalse();
})->with([TaskReceived::class, TickReceived::class, JobProcessing::class]);

it('moves the version on rbac:sync and on ProvisionSite', function (): void {
    $before = permissionVersion();

    $this->artisan('rbac:sync')->assertSuccessful();
    $afterSync = permissionVersion();

    DB::connection('pgsql_admin')->statement('truncate tenant_probes, site_profile_translations, site_profiles, site_settings, site_locales, site_modules, content_sections, domains, sites, tenants, tenant_users, users restart identity cascade');
    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Cache Co',
        siteName: 'Cache Co',
        hostname: 'cache.platform.test',
        ownerEmail: 'owner@cache.test',
        ownerName: 'Cache Owner',
        ownerPassword: 'a-strong-password',
    ));

    expect($afterSync)->toBeString()->not->toBe($before)
        ->and(permissionVersion())->toBeString()->not->toBe($afterSync);
});

it('checks the permission catalog at the start of every Octane request, task and tick and every queued job', function (): void {
    foreach ([RequestReceived::class, TaskReceived::class, TickReceived::class, JobProcessing::class] as $event) {
        expect(app('events')->getRawListeners()[$event] ?? [])->toContain(SyncPermissionCache::class);
    }
});

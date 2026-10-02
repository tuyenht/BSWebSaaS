<?php

use App\Modules\Admin\Support\SiteDomains;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\OwnershipProof;
use App\Modules\Tenancy\Exceptions\DomainChangeRejectedException;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Fixtures\FakeDns;

/*
 * Who may change a site's own domains (design.md §28): a member holding domains.manage on the
 * site's admin, platform staff holding platform.sites.manage on the console — and nobody on a
 * member's behalf during a support session. Every change names the person who made it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
    RateLimiter::clear('domain-https:shop.example');
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
    $this->dns->apexes = ['shop.example', 'kept.example'];
    Queue::fake();
});

/** A fresh user holding the corporate role template $name in $site. */
function sdMember(int $site, string $name): User
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
    app(TeamContext::class)->forget();

    return $user;
}

function sdStaff(string $role): User
{
    $user = User::factory()->create(['name' => 'Staff '.$role]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

/** Run $action as $member on the admin of $site — as themselves, or with staff signed in as them. */
function sdOnSite(User $member, Closure $action, int $site = 1, bool $support = false): mixed
{
    $session = app('session')->driver();
    $session->flush();
    request()->setLaravelSession($session);
    auth()->login($member);
    if ($support) {
        app(SupportSession::class)->start(sdStaff('support'), $member, $site, 'Kiểm tra', now()->addMinutes(30));
    }

    try {
        return app(Tenancy::class)->enter($site, $action);
    } finally {
        $session->flush();
        auth()->logout();
        app(TeamContext::class)->forget();
    }
}

/** @return list<array{action: string, actor_label: ?string, site_id: int}> */
function sdTrail(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')
        ->where('action', 'like', 'tenancy.domain.%')->orderBy('id')
        ->get(['action', 'actor_label', 'site_id'])
        ->map(fn (object $row): array => ['action' => $row->action, 'actor_label' => $row->actor_label, 'site_id' => $row->site_id])
        ->all();
}

function sdCustomDomains(): int
{
    return DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')->count();
}

it('lets a member who manages domains take a domain from attached to primary, naming them at every step', function (string $role): void {
    $member = sdMember(1, $role);
    $this->dns->pointHere('shop.example');

    sdOnSite($member, function () use ($member): void {
        $domains = app(SiteDomains::class);

        $created = $domains->attach($member, 'https://Shop.Example/');
        expect(array_map(fn ($row) => $row->hostname, $created))->toBe(['shop.example', 'www.shop.example']);

        expect($domains->check($member, 'shop.example')->verified())->toBeTrue();
        expect($domains->enableHttps($member, 'shop.example')->ssl_status)->toBe('pending');
    });

    DB::connection('pgsql_admin')->table('domains')->where('hostname', 'shop.example')->update(['ssl_status' => 'active']);

    sdOnSite($member, function () use ($member): void {
        expect(app(SiteDomains::class)->makePrimary($member, 'shop.example')->is_primary)->toBeTrue();
    });

    expect(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(1)
        ->and(sdTrail())->toBe(array_map(
            fn (string $action): array => ['action' => $action, 'actor_label' => $member->email, 'site_id' => 1],
            ['tenancy.domain.attached', 'tenancy.domain.verified', 'tenancy.domain.https_requested', 'tenancy.domain.primary_changed'],
        ));
})->with(['owner', 'admin']);

it('audits a removal', function (): void {
    $member = sdMember(1, 'owner');

    sdOnSite($member, function () use ($member): void {
        app(SiteDomains::class)->attach($member, 'shop.example');
        expect(app(SiteDomains::class)->remove($member, 'shop.example'))->toEqualCanonicalizing(['shop.example', 'www.shop.example']);
    });

    expect(sdCustomDomains())->toBe(0)
        ->and(array_column(sdTrail(), 'action'))->toBe(['tenancy.domain.attached', 'tenancy.domain.removed']);
});

it('refuses a member without domains.manage, changing nothing', function (string $what): void {
    $owner = sdMember(1, 'owner');
    sdOnSite($owner, fn () => app(SiteDomains::class)->attach($owner, 'kept.example'));
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');

    $editor = sdMember(1, 'editor');

    expect(fn () => sdOnSite($editor, fn () => match ($what) {
        'attach' => app(SiteDomains::class)->attach($editor, 'shop.example'),
        'check' => app(SiteDomains::class)->check($editor, 'kept.example'),
        'https' => app(SiteDomains::class)->enableHttps($editor, 'kept.example'),
        'primary' => app(SiteDomains::class)->makePrimary($editor, 'kept.example'),
        'remove' => app(SiteDomains::class)->remove($editor, 'kept.example'),
    }))->toThrow(AuthorizationException::class);

    expect(sdCustomDomains())->toBe(2)->and(sdTrail())->toBe([]);
})->with(['attach', 'check', 'https', 'primary', 'remove']);

it('refuses a member of another site: the permission counts only where it was granted', function (): void {
    $ownerOfB = sdMember(2, 'owner');

    expect(fn () => sdOnSite($ownerOfB, fn () => app(SiteDomains::class)->attach($ownerOfB, 'shop.example'), site: 1))
        ->toThrow(AuthorizationException::class);

    expect(sdCustomDomains())->toBe(0);
});

it('locks every domain change while staff are signed in as a member', function (string $what): void {
    $owner = sdMember(1, 'owner');
    sdOnSite($owner, fn () => app(SiteDomains::class)->attach($owner, 'kept.example'));
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');

    expect(fn () => sdOnSite($owner, fn () => match ($what) {
        'attach' => app(SiteDomains::class)->attach($owner, 'shop.example'),
        'check' => app(SiteDomains::class)->check($owner, 'kept.example'),
        'https' => app(SiteDomains::class)->enableHttps($owner, 'kept.example'),
        'primary' => app(SiteDomains::class)->makePrimary($owner, 'kept.example'),
        'remove' => app(SiteDomains::class)->remove($owner, 'kept.example'),
    }, support: true))->toThrow(AuthorizationException::class, __('admin.support.locked'));

    expect(sdCustomDomains())->toBe(2)->and(sdTrail())->toBe([]);
})->with(['attach', 'check', 'https', 'primary', 'remove']);

it('lets platform staff who manage sites change a site\'s domains from the console', function (): void {
    $staff = sdStaff('super_admin');
    $site = Site::query()->findOrFail(2);
    $this->dns->pointHere('shop.example');

    auth()->login($staff);
    $domains = app(SiteDomains::class);
    $domains->attach($staff, 'shop.example', site: $site);
    expect($domains->check($staff, 'shop.example', $site)->verified())->toBeTrue();

    expect(app(SiteResolver::class)->resolve('shop.example')?->siteId)->toBe(2)
        ->and(sdTrail())->toBe([
            ['action' => 'tenancy.domain.attached', 'actor_label' => $staff->email, 'site_id' => 2],
            ['action' => 'tenancy.domain.verified', 'actor_label' => $staff->email, 'site_id' => 2],
        ]);
});

it('refuses platform staff without platform.sites.manage, and a website\'s owner on the console path', function (string $who): void {
    $actor = $who === 'support staff' ? sdStaff('support') : sdMember(1, 'owner');
    $site = Site::query()->findOrFail(1);

    auth()->login($actor);

    expect(fn () => app(SiteDomains::class)->attach($actor, 'shop.example', site: $site))
        ->toThrow(AuthorizationException::class);

    expect(sdCustomDomains())->toBe(0)->and(sdTrail())->toBe([]);
})->with(['support staff', 'a website owner']);

it('tells the site that loses a contested domain, in its own trail', function (): void {
    $ownerA = sdMember(1, 'owner');
    $ownerB = sdMember(2, 'owner');
    $this->dns->pointHere('shop.example');

    sdOnSite($ownerA, function () use ($ownerA): void {
        app(SiteDomains::class)->attach($ownerA, 'shop.example');
        app(SiteDomains::class)->check($ownerA, 'shop.example');
    });

    $this->dns->txt['_site-verification.shop.example'] = [app(OwnershipProof::class)->recordValue(2, 'shop.example')];

    sdOnSite($ownerB, function () use ($ownerB): void {
        app(SiteDomains::class)->attach($ownerB, 'shop.example');
        expect(app(SiteDomains::class)->check($ownerB, 'shop.example')->evictedSiteIds)->toBe([1]);
    }, site: 2);

    expect(array_slice(sdTrail(), -1))->toBe([
        ['action' => 'tenancy.domain.taken_over', 'actor_label' => $ownerB->email, 'site_id' => 1],
    ]);
});

it('leaves no trail for a refused change', function (): void {
    $owner = sdMember(1, 'owner');

    expect(fn () => sdOnSite($owner, fn () => app(SiteDomains::class)->attach($owner, 'other.platform.test')))
        ->toThrow(DomainChangeRejectedException::class);

    expect(sdTrail())->toBe([]);
});

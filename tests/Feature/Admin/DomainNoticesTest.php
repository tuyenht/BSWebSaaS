<?php

use App\Modules\Admin\Notifications\DomainNoticeNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Channels\SiteDatabaseChannel;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Actions\AttachDomain;
use App\Modules\Tenancy\Actions\CheckDomain;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Domains\DomainHealth;
use App\Modules\Tenancy\Domains\OwnershipProof;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Fixtures\FakeDns;

/*
 * The people who manage a website's domains hear when one of its own domains needs them (design.md
 * §28): the day it stops pointing here, a week before it would stop being served, the day it does,
 * and when another site proves it owns the name. Mail goes with the warnings; good news and expired
 * waits only ring the bell. A takeover is a security notice nobody can switch off.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
    config(['tenancy.custom_domains.target' => 'target.platform.test', 'tenancy.base_domains' => ['platform.test']]);
    $this->dns = FakeDns::install();
    Notification::fake();
});

/** A fresh user holding the corporate role template $name in $site. */
function domainManager(int $site, string $name): User
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

/** Both spellings of shop.example attached to $site and live. */
function noticeLivePair(FakeDns $dns, int $site = 1): void
{
    $dns->pointHere('shop.example', 'www.shop.example');
    app(Tenancy::class)->enter($site, function (): void {
        app(AttachDomain::class)->handle('shop.example');
        app(CheckDomain::class)->handle('shop.example');
    });
}

function noticeFailingFor(int $days): void
{
    DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')
        ->update(['failing_since' => now()->subDays($days), 'failure_reason' => 'not_pointing']);
}

/**
 * @return list<array{variant: string, hostnames: list<string>, channels: list<string>}>
 */
function noticesTo(User $user): array
{
    return Notification::sent($user, DomainNoticeNotification::class)
        ->map(fn (DomainNoticeNotification $n): array => ['variant' => $n->variant, 'hostnames' => $n->hostnames, 'channels' => $n->via($user)])
        ->values()->all();
}

it('tells the site\'s domain managers once, by bell and mail, when a pair stops pointing here', function (): void {
    $owner = domainManager(1, 'owner');
    $admin = domainManager(1, 'admin');
    $editor = domainManager(1, 'editor');
    $elsewhere = domainManager(2, 'owner');
    noticeLivePair($this->dns);
    unset($this->dns->addresses['shop.example'], $this->dns->addresses['www.shop.example']);

    app(DomainHealth::class)->sweep();
    app(DomainHealth::class)->sweep();   // still failing: nothing new

    $expected = [['variant' => 'failing', 'hostnames' => ['shop.example', 'www.shop.example'], 'channels' => [SiteDatabaseChannel::class, 'mail']]];

    expect(noticesTo($owner))->toBe($expected)
        ->and(noticesTo($admin))->toBe($expected)
        ->and(noticesTo($editor))->toBe([])
        ->and(noticesTo($elsewhere))->toBe([]);
});

it('reminds them once, a week before the domain would stop being served', function (): void {
    $owner = domainManager(1, 'owner');
    noticeLivePair($this->dns);
    unset($this->dns->addresses['shop.example'], $this->dns->addresses['www.shop.example']);
    noticeFailingFor(24);

    app(DomainHealth::class)->sweep();
    app(DomainHealth::class)->sweep();

    expect(noticesTo($owner))->toBe([['variant' => 'expiring', 'hostnames' => ['shop.example', 'www.shop.example'], 'channels' => [SiteDatabaseChannel::class, 'mail']]]);
});

it('tells them when the domain stops being served', function (): void {
    $owner = domainManager(1, 'owner');
    noticeLivePair($this->dns);
    unset($this->dns->addresses['shop.example'], $this->dns->addresses['www.shop.example']);
    noticeFailingFor(31);

    app(DomainHealth::class)->sweep();

    expect(array_column(noticesTo($owner), 'variant'))->toBe(['lost']);
});

it('rings the bell without mail when the domain points here again', function (): void {
    $owner = domainManager(1, 'owner');
    noticeLivePair($this->dns);
    noticeFailingFor(2);

    app(DomainHealth::class)->sweep();

    expect(noticesTo($owner))->toBe([['variant' => 'recovered', 'hostnames' => ['shop.example', 'www.shop.example'], 'channels' => [SiteDatabaseChannel::class]]]);
});

it('rings the bell without mail when a name nobody pointed is dropped', function (): void {
    $owner = domainManager(1, 'owner');
    app(Tenancy::class)->enter(1, fn () => app(AttachDomain::class)->handle('shop.example'));
    DB::connection('pgsql_admin')->table('domains')->where('kind', 'custom')->update(['created_at' => now()->subDays(8)]);

    app(DomainHealth::class)->sweep();

    expect(noticesTo($owner))->toBe([['variant' => 'wait_expired', 'hostnames' => ['shop.example', 'www.shop.example'], 'channels' => [SiteDatabaseChannel::class]]]);
});

it('leaves the mail out for someone who switched it off', function (): void {
    $owner = domainManager(1, 'owner');
    $owner->forceFill(['preferences' => ['notifications' => ['system.domain_problem' => ['mail' => false]]]])->save();
    noticeLivePair($this->dns);
    unset($this->dns->addresses['shop.example'], $this->dns->addresses['www.shop.example']);

    app(DomainHealth::class)->sweep();

    expect(array_column(noticesTo($owner->fresh()), 'channels'))->toBe([[SiteDatabaseChannel::class]]);
});

it('tells the site that lost a name to an ownership proof, with a notice nobody can switch off', function (): void {
    $loser = domainManager(1, 'owner');
    $loser->forceFill(['preferences' => ['notifications' => ['security.domain_taken_over' => ['mail' => false]]]])->save();
    $winner = domainManager(2, 'owner');
    noticeLivePair($this->dns);

    app(Tenancy::class)->enter(2, fn () => app(AttachDomain::class)->handle('shop.example'));
    foreach (['shop.example', 'www.shop.example'] as $host) {
        $this->dns->txt['_site-verification.'.$host] = [app(OwnershipProof::class)->recordValue(2, $host)];
    }
    app(Tenancy::class)->enter(2, fn () => app(CheckDomain::class)->handle('shop.example'));

    expect(noticesTo($loser->fresh()))->toBe([['variant' => 'taken_over', 'hostnames' => ['shop.example', 'www.shop.example'], 'channels' => [SiteDatabaseChannel::class, 'mail']]])
        ->and(noticesTo($winner))->toBe([]);
});

it('tells them when a check finds the domain no longer points here, and when a check finds it back', function (): void {
    $owner = domainManager(1, 'owner');
    noticeLivePair($this->dns);
    unset($this->dns->addresses['shop.example'], $this->dns->addresses['www.shop.example']);

    app(Tenancy::class)->enter(1, fn () => app(CheckDomain::class)->handle('shop.example'));
    app(DomainHealth::class)->sweep();   // the streak is already known: not told twice

    $this->dns->pointHere('shop.example', 'www.shop.example');
    app(Tenancy::class)->enter(1, fn () => app(CheckDomain::class)->handle('shop.example'));

    expect(array_column(noticesTo($owner), 'variant'))->toBe(['failing', 'recovered']);
});

it('reads every notice in plain words', function (string $variant): void {
    $owner = domainManager(1, 'owner');
    $data = (new DomainNoticeNotification($variant, ['shop.example', 'www.shop.example'], '01/01/2027'))->toSiteDatabase($owner);
    $notice = new SiteNotification(['kind' => $data['kind'], 'data' => $data['data']]);

    expect($notice->summary())->toContain('shop.example, www.shop.example')
        ->and($notice->summary())->not->toContain('notifications.')
        ->and($notice->summary())->not->toContain(':until');
})->with(['failing', 'expiring', 'lost', 'recovered', 'wait_expired', 'taken_over']);

it('writes the bell entry in plain words on the site it concerns', function (): void {
    Notification::swap(new ChannelManager(app()));   // really send: queue is sync in tests
    $owner = domainManager(1, 'owner');
    noticeLivePair($this->dns);
    unset($this->dns->addresses['shop.example'], $this->dns->addresses['www.shop.example']);

    app(DomainHealth::class)->sweep();

    $notice = app(Tenancy::class)->enter(1, fn () => SiteNotification::query()->where('user_id', $owner->id)->sole());

    expect($notice->kind)->toBe('system.domain_problem')
        ->and($notice->summary())->toContain('shop.example, www.shop.example')
        ->and($notice->summary())->toMatch('#\d{2}/\d{2}/\d{4}#')   // the day it would stop working
        ->and($notice->summary())->not->toContain('notifications.');
});

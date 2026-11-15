<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Admin\Support\AccessRequests;
use App\Modules\Admin\Support\NotificationLink;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * "Nhờ cấp quyền" on the admin's no-permission page (design board 25) and the notice a member gets
 * when their roles change. The screen is named by its route and its permission read again on the
 * server; the member managers ABOVE the requester are told (else the owner), never the requester,
 * never another website; at most 3 requests a day, one per screen.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    config(['app.debug' => false]);
    app()->setLocale('vi');
});

/** A new member of $site holding a role $name at $level with exactly $permissions ('*' = every tenant one). */
function askMember(string $name, int $level, array|string $permissions, int $site = 1): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter($site, function () use ($name, $level, $permissions, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $level, 'is_system' => in_array($name, ['owner', 'admin', 'editor', 'viewer'], true)])->save();
        $role->syncPermissions($permissions === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $permissions);
        $user->assignRole($role);
    });
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** @return list<string> the notice kinds $user has on $site, newest first */
function askNotices(User $user, int $site = 1): array
{
    return app(Tenancy::class)->enter($site, fn (): array => SiteNotification::query()
        ->where('user_id', $user->id)->orderByDesc('id')->pluck('kind')->all());
}

function askFor(string $screen, string $back = '/admin/settings/general'): array
{
    return ['screen' => $screen, 'back' => $back];
}

it('offers "Nhờ cấp quyền" on the no-permission page, naming who is told, with "Về Tổng quan" second', function (): void {
    askMember('owner', 90, '*');
    askMember('admin', 70, '*');
    $member = askMember('media-only', 10, ['media.view']);

    $html = $this->actingAs($member)->get('http://a.test/admin/settings/general')->assertForbidden()->getContent();

    expect($html)
        ->toContain('action="'.route('admin.access.request', absolute: false).'"')
        ->toContain('name="screen" value="admin.settings.general"')
        ->toContain('name="back" value="/admin/settings/general"')
        ->toContain(__('errors.noperm.request'))
        ->toContain(e(__('errors.noperm.request_title', ['to' => __('rbac.role.owner').', '.__('rbac.role.admin')])))
        ->toContain(__('errors.back_dashboard'))
        ->not->toContain('te-ok');
});

it('tells the member managers above the requester, and nobody else', function (): void {
    $owner = askMember('owner', 90, '*');
    $admin = askMember('admin', 70, '*');
    $peer = askMember('peer-manager', 10, ['users.manage', 'media.view']);
    $lower = askMember('low-manager', 5, ['users.manage']);
    $otherSite = askMember('admin', 70, '*', 2);
    $member = askMember('media-only', 10, ['media.view']);

    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general'))
        ->assertStatus(303)->assertRedirect('/admin/settings/general');

    expect(askNotices($owner))->toBe(['member.access_requested'])
        ->and(askNotices($admin))->toBe(['member.access_requested'])
        ->and(askNotices($peer))->toBe([])
        ->and(askNotices($lower))->toBe([])
        ->and(askNotices($member))->toBe([])
        ->and(askNotices($otherSite, 2))->toBe([]);

    $notice = app(Tenancy::class)->enter(1, fn () => SiteNotification::query()->where('user_id', $admin->id)->sole());
    expect($notice->data)->toEqual([
        'actor' => $member->name, 'requester' => $member->ulid, 'screen' => __('admin.settings'), 'permission' => 'settings.manage',
    ])
        ->and($notice->summary())->toBe(__('notifications.member.access_requested', ['actor' => $member->name, 'screen' => __('admin.settings')]))
        ->and(app(Tenancy::class)->enter(1, fn () => NotificationLink::for($notice)))
        ->toBe(['url' => route('admin.users.index', ['mo' => $member->ulid], false), 'act' => __('admin.bell.act.member')]);
});

it('tells the owner when no member manager sits above the requester', function (): void {
    $owner = askMember('owner', 90, ['media.view']);   // an owner role without users.manage
    $peer = askMember('peer-manager', 50, ['users.manage']);
    $member = askMember('senior', 50, ['media.view']);

    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general'))->assertStatus(303);

    expect(askNotices($owner))->toBe(['member.access_requested'])
        ->and(askNotices($peer))->toBe([]);
});

it('reads "Đã gửi yêu cầu" after the request and sends one notice per screen, however often pressed', function (): void {
    $admin = askMember('admin', 70, '*');
    $member = askMember('media-only', 10, ['media.view']);

    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general'));
    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general'));

    $html = $this->actingAs($member)->get('http://a.test/admin/settings/general')->assertForbidden()->getContent();
    expect(askNotices($admin))->toBe(['member.access_requested'])
        ->and($html)->toContain(__('errors.noperm.requested'))
        ->toContain('aria-disabled="true"')
        ->toContain('te-ok')
        ->toContain(e(__('errors.noperm.sent', ['to' => __('rbac.role.admin')])))
        ->not->toContain('name="screen"');
});

it('refuses a fourth request in 24 hours and says why on the page', function (): void {
    $admin = askMember('admin', 70, '*');
    $member = askMember('media-only', 10, ['media.view']);
    foreach (['admin.settings.general', 'admin.settings.seo', 'admin.taxonomies.index', 'admin.menus.index'] as $screen) {
        $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor($screen))->assertStatus(303);
    }

    $html = $this->actingAs($member)->get('http://a.test/admin/menus')->assertForbidden()->getContent();
    expect(askNotices($admin))->toHaveCount(3)
        ->and($html)->toContain(e(__('errors.noperm.limited', ['count' => 3])))
        ->toContain('aria-disabled="true"')
        ->toContain('aria-describedby="te-why-noperm"')
        ->not->toContain('te-ok')
        ->not->toContain('name="screen"');
});

it('refuses a screen it cannot read a permission from, one the member can open, and a made-up one', function (string $screen): void {
    $admin = askMember('admin', 70, '*');
    $member = askMember('media-only', 10, ['media.view']);

    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor($screen))->assertStatus(422);

    expect(askNotices($admin))->toBe([]);
})->with([
    'no plain permission (the screen checks itself)' => ['admin.users.index'],
    'a screen the member can open' => ['admin.media.index'],
    'not a website admin screen' => ['platform.tenants.index'],
    'made up' => ['admin.nothing.here'],
    'a permission only the platform grants' => ['admin.settings.workflows'],
    'not a page (an upload step)' => ['admin.media.presign'],
]);

it('offers no request for a permission no role of the website can hold', function (): void {
    askMember('owner', 90, '*');
    $member = askMember('media-only', 10, ['media.view']);

    $html = $this->actingAs($member)->get('http://a.test/admin/settings/workflows')->assertForbidden()->getContent();

    expect($html)->not->toContain('name="screen"')->not->toContain(__('errors.noperm.request'));
});

it('offers no request when nobody else runs the website, and sends none', function (): void {
    $member = askMember('media-only', 10, ['media.view']);

    $html = $this->actingAs($member)->get('http://a.test/admin/settings/general')->assertForbidden()->getContent();
    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general'))->assertStatus(422);

    expect($html)->not->toContain('name="screen"')
        ->and(app(Tenancy::class)->enter(1, fn () => SiteNotification::query()->count()))->toBe(0)
        ->and(app(Tenancy::class)->enter(1, fn (): string => app(AccessRequests::class)->state($member, 'admin.settings.general')))->toBe('ready');
});

it('turns to the owner when the managers above turned the notice off', function (): void {
    $owner = askMember('owner', 90, '*');
    $admin = askMember('admin', 70, '*');
    $admin->forceFill(['preferences' => ['notifications' => ['member.access_requested' => ['in_app' => false]]]])->save();
    $member = askMember('media-only', 10, ['media.view']);

    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general'))->assertStatus(303);

    expect(askNotices($admin))->toBe([])
        ->and(askNotices($owner))->toBe(['member.access_requested']);
});

it('gives the request back when nobody could be told', function (): void {
    $member = askMember('media-only', 10, ['media.view']);
    $requests = app(AccessRequests::class);

    $asked = app(Tenancy::class)->enter(1, fn (): string => $requests->ask($member, 'admin.settings.general', 'settings.manage', 'Cài đặt'));

    expect($asked)->toBe('ready')
        ->and(app(Tenancy::class)->enter(1, fn (): string => $requests->state($member, 'admin.settings.general')))->toBe('ready')
        ->and(RateLimiter::attempts('access-request:1:'.$member->id))->toBe(0)
        ->and(app(Tenancy::class)->enter(1, fn () => SiteNotification::query()->count()))->toBe(0);
});

it('has nothing to ask on the platform host', function (): void {
    askMember('owner', 90, '*');
    $member = askMember('media-only', 10, ['media.view']);
    $requests = app(AccessRequests::class);

    $site = app(Tenancy::class)->enter(1, fn (): ?string => $requests->askable($member, 'admin.settings.general'));
    app()->instance('request', Request::create('http://'.config('tenancy.platform_host').'/platform'));
    $platform = app(Tenancy::class)->enter(1, fn (): ?string => $requests->askable($member, 'admin.settings.general'));

    expect($site)->toBe('settings.manage')->and($platform)->toBeNull();
});

it('goes back only to an address of this admin', function (): void {
    askMember('admin', 70, '*');
    $member = askMember('media-only', 10, ['media.view']);

    $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor('admin.settings.general', 'https://evil.test/x'))
        ->assertStatus(303)->assertRedirect('/admin');
});

it('offers no request to a guest', function (): void {
    askMember('admin', 70, '*');

    $this->get('http://a.test/admin/settings/general')->assertRedirect('http://a.test/admin/login');
    $this->post('http://a.test/admin/access-requests', askFor('admin.settings.general'))->assertRedirect('http://a.test/admin/login');
});

it('lets nobody ask while support staff are signed in as the member', function (): void {
    askMember('admin', 70, '*');
    $member = askMember('media-only', 10, ['media.view']);
    $session = app('session')->driver();
    request()->setLaravelSession($session);
    auth()->login($member);
    $requests = app(AccessRequests::class);

    $before = app(Tenancy::class)->enter(1, fn (): ?string => $requests->askable($member, 'admin.settings.general'));
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));
    app(PlatformAccess::class)->forgetMemo();
    app(SupportSession::class)->start($staff, $member, 1, 'Hỗ trợ kiểm tra lỗi', now()->addMinutes(30));
    $during = app(Tenancy::class)->enter(1, fn (): ?string => $requests->askable($member, 'admin.settings.general'));

    expect($before)->toBe('settings.manage')->and($during)->toBeNull();
});

it('sends a request at a fixed cost, whatever the number of managers told', function (): void {
    askMember('owner', 90, '*');
    $member = askMember('media-only', 10, ['media.view']);
    $cost = function (string $screen) use ($member): int {
        return countQueries(fn () => $this->actingAs($member)->post('http://a.test/admin/access-requests', askFor($screen))->assertStatus(303));
    };

    $cost('admin.settings.general');   // the permission caches fill on the first request
    $one = $cost('admin.settings.seo');
    askMember('admin', 70, '*');
    askMember('admin', 70, '*');
    // a new member's roles clear the permission caches: fill them again before measuring
    $this->actingAs($member)->get('http://a.test/admin/settings/general')->assertForbidden();
    $three = $cost('admin.taxonomies.index');

    // one insert for each further manager, nothing else that grows
    expect($three - $one)->toBe(2)
        ->and($one)->toBeLessThanOrEqual(30);
});

it('tells a member when their roles change, from one member, several at once, or "Hoàn tác"', function (): void {
    $owner = askMember('owner', 90, '*');
    $a = askMember('author', 30, ['entries.view']);
    $b = askMember('viewer', 10, ['entries.view']);
    $c = askMember('viewer', 10, ['entries.view']);

    // one member: their roles change, then "Hoàn tác" gives the old ones back — told twice
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->call('openPanel', $a->ulid)->set('panelRoles', ['viewer'])->call('saveRoles')
        ->call('undo'));
    // several at once: $c already holds the chosen role, so only $b is told
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->call('togglePick', [$b->ulid, $c->ulid])->call('openBulkPanel')->set('panelRoles', ['viewer', 'author'])->call('saveRoles'));
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->call('openPanel', $c->ulid)->set('panelRoles', ['viewer'])->call('saveRoles'));

    $notice = app(Tenancy::class)->enter(1, fn () => SiteNotification::query()->where('user_id', $a->id)->orderBy('id')->first());
    expect(askNotices($a))->toBe(['member.role_changed', 'member.role_changed'])
        ->and(askNotices($b))->toBe(['member.role_changed'])
        ->and(askNotices($owner))->toBe([])
        ->and($notice?->summary())->toBe(__('notifications.member.role_changed', ['actor' => $owner->name, 'roles' => __('rbac.role.viewer')]))
        ->and(app(Tenancy::class)->enter(1, fn () => NotificationLink::for($notice)))->toBeNull();
});

it('keeps quiet about a role change for a member who turned that notice off, one by one or several at once', function (): void {
    $owner = askMember('owner', 90, '*');
    $quiet = askMember('viewer', 10, ['entries.view']);
    $other = askMember('viewer', 10, ['entries.view']);
    askMember('author', 30, ['entries.view']); // the role the save gives
    $quiet->forceFill(['preferences' => ['notifications' => ['member.role_changed' => ['in_app' => false]]]])->save();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->call('togglePick', [$quiet->ulid, $other->ulid])->call('openBulkPanel')->set('panelRoles', ['author'])->call('saveRoles'));
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())->test(Index::class)
        ->call('openPanel', $quiet->ulid)->set('panelRoles', ['viewer'])->call('saveRoles'));

    expect(askNotices($quiet))->toBe([])
        ->and(askNotices($other))->toBe(['member.role_changed']);
});

it('opens the member named by ?mo= from the bell, through the panel\'s own checks', function (): void {
    $admin = askMember('admin', 70, '*');
    $viewer = askMember('viewer', 10, ['entries.view']);
    $owner = askMember('owner', 90, '*');
    $stranger = askMember('viewer', 10, ['entries.view'], 2);
    $page = fn (string $mo): Testable => app(Tenancy::class)->enter(1, fn () => Livewire::withQueryParams(['mo' => $mo])
        ->actingAs($admin->fresh())->test(Index::class));

    $page($viewer->ulid)->assertSet('panelFor', $viewer->ulid);
    $page($owner->ulid)->assertSet('panelFor', '')->assertSet('toast', __('admin.members.cannot_touch'));
    $page($stranger->ulid)->assertSet('panelFor', '')->assertSet('toast', __('admin.member_not_found'));
    $page(strtoupper((string) Str::ulid()))->assertSet('panelFor', '')->assertSet('toast', __('admin.member_not_found'));
    $page('not-a-ulid')->assertSet('panelFor', '')->assertSet('toast', __('admin.member_not_found'));
});

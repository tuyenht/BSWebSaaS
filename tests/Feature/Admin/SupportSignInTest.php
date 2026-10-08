<?php

use App\Modules\Admin\Http\Middleware\EnforceSupportSession;
use App\Modules\Admin\Notifications\SupportSignInNotification;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\AcceptInvitation as AcceptInvitationAction;
use App\Modules\Rbac\Actions\AssignRole;
use App\Modules\Rbac\Actions\CreateCustomRole;
use App\Modules\Rbac\Actions\DeleteCustomRole;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Actions\RevokeInvitation;
use App\Modules\Rbac\Actions\RevokeRole;
use App\Modules\Rbac\Actions\UpdateCustomRole;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/*
 * Support sign-in (design.md v2.33–v2.34): platform staff enter a website's admin AS one of its
 * members — only with the permission, only while the owner allows it, for a stated reason and a
 * fixed time — through a single-use link bound to the site and the browser. Inside, the member's
 * own limits apply, account and membership changes are locked, every audit row names the staff
 * member behind the account, and the website's owners are told.
 */

const SUPPORT_UA = 'Mozilla/5.0 (support desk)';

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users, site_settings, platform_settings, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Notification::fake();
    Mail::fake();
});

/** A fresh user holding the corporate role template $name in $site. */
function supportMember(int $site, string $name): User
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

/** A platform operator holding $role (support · billing · super_admin). */
function platformStaff(string $role): User
{
    $user = User::factory()->create(['name' => 'Staff '.$role]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

function siteA(): Site
{
    return Site::query()->findOrFail(1);
}

/** A sign-in link token for $member of site A, issued by $staff from the support desk browser. */
function supportToken(User $staff, User $member, int $minutes = 30): string
{
    return app(SupportSignIn::class)->issue($staff, siteA(), $member, 'Khách nhờ sửa trang chủ', $minutes, SUPPORT_UA);
}

/** Enter site A's admin with $token from the support desk browser. */
function enterSupport(mixed $test, string $token, string $host = 'a.test', string $ua = SUPPORT_UA): mixed
{
    return $test->withHeader('User-Agent', $ua)->get("http://{$host}/admin/support/enter?token={$token}");
}

/** @return list<string> audit actions, oldest first */
function supportAudit(): array
{
    return AuditLog::query()->orderBy('id')->pluck('action')->all();
}

// ── entering ────────────────────────────────────────────────────────────────────────

it('signs the staff member in as the chosen member, on record, and tells the owners', function (): void {
    $owner = supportMember(1, 'owner');
    $editor = supportMember(1, 'editor');
    $staff = platformStaff('support');

    enterSupport($this, supportToken($staff, $editor))
        ->assertStatus(303)
        ->assertRedirect(route('admin.dashboard'));

    $this->assertAuthenticatedAs($editor);

    $entered = AuditLog::query()->where('action', 'support.session.entered')->sole();
    expect($entered->actor_id)->toBe($editor->getKey())
        ->and($entered->impersonator_id)->toBe($staff->getKey())
        ->and($entered->impersonator_label)->toBe('Staff support')
        ->and($entered->site_id)->toBe(1)
        ->and(supportAudit())->toBe(['support.session.issued', 'support.session.entered'])
        ->and(supportAudit())->not->toContain('auth.login.succeeded');

    Notification::assertSentTo($owner, SupportSignInNotification::class);
    Notification::assertNotSentTo($editor, SupportSignInNotification::class);
});

it('names the staff member and the platform in the owner notice', function (): void {
    $owner = supportMember(1, 'owner');
    config(['admin.brand.name' => 'Nền tảng X']);

    $notice = new SupportSignInNotification('Staff support', $owner->name, 'Sửa menu', '30/09/2026 21:00');
    $row = $notice->toSiteDatabase($owner);

    expect($row['kind'])->toBe('security.support_sign_in')
        ->and(__('notifications.security.support_sign_in', $row['data']))
        ->toContain('Staff support')->toContain('Nền tảng X')->toContain('Sửa menu')->not->toContain(':platform');
});

it('lets each link work once, within a minute, in the browser it was made for, on its own site', function (string $case): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff('support');
    $token = supportToken($staff, $owner);

    match ($case) {
        'used twice' => (function () use ($token): void {
            enterSupport($this, $token)->assertStatus(303);
            auth()->logout();
            $this->flushSession();
            enterSupport($this, $token)->assertRedirectContains('/admin/login');
        })(),
        'too late' => (function () use ($token): void {
            $this->travel(61)->seconds();
            enterSupport($this, $token)->assertRedirectContains('/admin/login');
        })(),
        'another browser' => enterSupport($this, $token, ua: 'Mozilla/5.0 (someone else)')->assertRedirectContains('/admin/login'),
        'another site' => enterSupport($this, $token, host: 'b.test')->assertRedirectContains('/admin/login'),
        'no token' => $this->get('http://a.test/admin/support/enter')->assertRedirectContains('/admin/login'),
    };

    if ($case !== 'used twice') {
        $this->assertGuest();
    }
})->with(['used twice', 'too late', 'another browser', 'another site', 'no token']);

it('replaces a session the browser already had on the site host', function (): void {
    $owner = supportMember(1, 'owner');
    $viewer = supportMember(1, 'viewer');
    $staff = platformStaff('support');

    $this->actingAs($viewer);
    enterSupport($this, supportToken($staff, $owner))->assertStatus(303);

    $this->assertAuthenticatedAs($owner);
});

// ── who may issue, for whom ─────────────────────────────────────────────────────────

it('lets support and super_admin issue a link, not billing', function (string $role, bool $allowed): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff($role);

    $issue = fn (): string => supportToken($staff, $owner);

    $allowed ? expect($issue())->toBeString()->toHaveLength(48)
        : expect($issue)->toThrow(AuthorizationException::class);
})->with([
    'support' => ['support', true],
    'super_admin' => ['super_admin', true],
    'billing' => ['billing', false],
]);

it('refuses a member who is the staff member, platform staff, or not on the site', function (string $case): void {
    $staff = platformStaff('support');
    $target = match ($case) {
        'themselves' => $staff,
        'platform staff' => tap(platformStaff('billing'), fn (User $u) => app(Tenancy::class)->enter(1, fn () => grantRole($u, 'owner', PermissionRegistry::TENANT_PERMISSIONS))),
        'another site' => supportMember(2, 'owner'),
        'no role' => User::factory()->create(),
    };
    app(TeamContext::class)->forget();

    expect(fn () => supportToken($staff, $target))->toThrow(ValidationException::class);
})->with(['themselves', 'platform staff', 'another site', 'no role']);

it('asks for a reason and one of the fixed durations', function (string $reason, int $minutes): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff('support');

    expect(fn () => app(SupportSignIn::class)->issue($staff, siteA(), $owner, $reason, $minutes, SUPPORT_UA))
        ->toThrow(ValidationException::class);
})->with([
    'no reason' => ['  ', 30],
    'odd duration' => ['Sửa menu', 45],
]);

it('refuses a link while the owner has turned support sign-in off', function (): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff('support');
    app(Tenancy::class)->enter(1, fn () => app(SupportAccess::class)->set($owner, false));

    expect(fn () => supportToken($staff, $owner))->toThrow(ValidationException::class);
});

it('refuses a link to a site that is not active', function (): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff('support');
    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['status' => 'suspended']);

    expect(fn () => supportToken($staff, $owner))->toThrow(ValidationException::class);
});

// ── inside the session ──────────────────────────────────────────────────────────────

it('ends the session when its time is up, on pages and on Livewire updates', function (string $via): void {
    $owner = supportMember(1, 'owner');
    enterSupport($this, supportToken(platformStaff('support'), $owner, 15))->assertStatus(303);
    $this->get('http://a.test/admin')->assertOk();

    $this->travel(16)->minutes();

    $via === 'page'
        ? $this->get('http://a.test/admin')->assertRedirectContains('/admin/login')
        : $this->withHeader('X-Livewire', '1')->post('http://a.test/livewire/update', [])->assertForbidden();

    $this->assertGuest();
    expect(AuditLog::query()->where('action', 'support.session.ended')->value('meta'))->toBe(['why' => 'expired']);
})->with(['page', 'livewire']);

it('ends the session at the next request once the owner turns support sign-in off', function (): void {
    $owner = supportMember(1, 'owner');
    enterSupport($this, supportToken(platformStaff('support'), $owner))->assertStatus(303);

    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('support_access', 'config', ['enabled' => false]));

    $this->get('http://a.test/admin')->assertRedirectContains('/admin/login');
    $this->assertGuest();
});

it('ends the session at the next request once the staff member loses the right', function (): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff('support');
    enterSupport($this, supportToken($staff, $owner))->assertStatus(303);

    app(PlatformAccess::class)->onPlatform(fn () => $staff->removeRole('support'));
    app(PlatformAccess::class)->forgetMemo();

    $this->get('http://a.test/admin')->assertRedirectContains('/admin/login');
    $this->assertGuest();
});

it('fills the staff member into every audit row of the session, and nowhere else', function (): void {
    $owner = supportMember(1, 'owner');

    asMember($owner, true, fn () => Audit::record('cms.entry.updated'));
    asMember($owner, false, fn () => Audit::record('cms.entry.published'));

    $during = AuditLog::query()->where('action', 'cms.entry.updated')->sole();
    expect($during->actor_id)->toBe($owner->getKey())
        ->and($during->impersonator_label)->toBe('Staff support')
        ->and($during->impersonator_id)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'cms.entry.published')->value('impersonator_id'))->toBeNull();
});

it('keeps auditing where there is no session at all (queued jobs)', function (): void {
    Audit::record('media.variant.built');

    expect(AuditLog::query()->where('action', 'media.variant.built')->exists())->toBeTrue();
});

it('goes back to the console when the staff member leaves', function (): void {
    $owner = supportMember(1, 'owner');
    enterSupport($this, supportToken(platformStaff('support'), $owner))->assertStatus(303);

    $this->post('http://a.test/admin/support/leave')
        ->assertRedirect(route('platform.sites.show', ['ulid' => siteA()->ulid]));

    $this->assertGuest();
    expect(AuditLog::query()->where('action', 'support.session.ended')->value('meta'))->toBe(['why' => 'left']);
});

it('routes Livewire updates through the support-session check', function (): void {
    expect(Route::getRoutes()->getByName('livewire.update')->gatherMiddleware())
        ->toContain(EnforceSupportSession::class);
});

// ── locks ───────────────────────────────────────────────────────────────────────────

/** Run $action as $member of site 1 — inside a support session, or as the member themselves. */
function asMember(User $member, bool $support, Closure $action, int $site = 1): mixed
{
    $session = app('session')->driver();
    $session->flush();
    request()->setLaravelSession($session);
    auth()->login($member);
    if ($support) {
        app(SupportSession::class)->start(platformStaff('support'), $member, $site, 'Kiểm tra', now()->addMinutes(30));
    }

    try {
        return app(Tenancy::class)->enter($site, $action);
    } finally {
        $session->flush();
        app(TeamContext::class)->forget();
    }
}

it('locks membership changes and the support switch during a support session', function (string $what): void {
    $owner = supportMember(1, 'owner');
    $editor = supportMember(1, 'editor');
    [$editorRole, $viewerRole] = app(Tenancy::class)->enter(1, fn (): array => [
        Role::findByName('editor', PermissionRegistry::GUARD),
        Role::findOrCreate('viewer', PermissionRegistry::GUARD),
    ]);

    $run = fn (bool $support): mixed => asMember($owner, $support, fn () => match ($what) {
        'invite a member' => app(InviteMember::class)->handle($owner, 'new@example.com', [$viewerRole]),
        'give a role' => app(AssignRole::class)->handle($owner, $editor, $editorRole),
        'take a role' => app(RevokeRole::class)->handle($owner, $editor, $editorRole),
        'the support switch' => app(SupportAccess::class)->set($owner, false),
    });

    expect(fn () => $run(true))->toThrow(AuthorizationException::class);
    expect(fn () => $run(false))->not->toThrow(Throwable::class);
})->with(['invite a member', 'give a role', 'take a role', 'the support switch']);

it('locks the account, social sign-in and mail sender screens during a support session', function (string $path): void {
    $owner = supportMember(1, 'owner');

    $this->actingAs($owner)->get("http://a.test{$path}")->assertOk();

    enterSupport($this, supportToken(platformStaff('support'), $owner))->assertStatus(303);
    $this->get("http://a.test{$path}")->assertForbidden();
})->with(['/admin/settings/security', '/admin/settings/social', '/admin/settings/email']);

it('locks linking a sign-in account or connecting a sender through OAuth during a support session', function (string $purpose): void {
    $owner = supportMember(1, 'owner');
    enterSupport($this, supportToken(platformStaff('support'), $owner))->assertStatus(303);

    $this->get("http://a.test/admin/oauth/google/start?purpose={$purpose}")->assertForbidden();
})->with(['connect', 'email']);

it('locks accepting an invitation during a support session', function (): void {
    $owner = supportMember(1, 'owner');
    enterSupport($this, supportToken(platformStaff('support'), $owner))->assertStatus(303);

    $this->get('http://a.test/admin/invitations/'.str_repeat('a', 64))->assertForbidden();
});

it('locks custom roles and invitations at the action itself during a support session', function (string $what): void {
    $owner = supportMember(1, 'owner');
    $role = new Role;
    $invitation = new Invitation;

    $call = fn () => asMember($owner, true, fn () => match ($what) {
        'create a custom role' => app(CreateCustomRole::class)->handle($owner, 'Biên tập viên', 40, []),
        'edit a custom role' => app(UpdateCustomRole::class)->handle($owner, $role, 'Biên tập viên', 40, []),
        'delete a custom role' => app(DeleteCustomRole::class)->handle($owner, $role),
        'revoke an invitation' => app(RevokeInvitation::class)->handle($owner, $invitation),
        'accept an invitation' => app(AcceptInvitationAction::class)->asSignedInUser(str_repeat('a', 64), $owner),
        'accept with a new account' => app(AcceptInvitationAction::class)->withNewAccount(str_repeat('a', 64), 'Tên', 'mat-khau-dai-du-12'),
    });

    expect($call)->toThrow(AuthorizationException::class, __('admin.support.locked'));
})->with(['create a custom role', 'edit a custom role', 'delete a custom role', 'revoke an invitation', 'accept an invitation', 'accept with a new account']);

// ── review follow-ups ───────────────────────────────────────────────────────────────

it('keeps the staff member out of the rows of anyone else who signs in on that browser', function (): void {
    $owner = supportMember(1, 'owner');
    $viewer = supportMember(1, 'viewer');

    asMember($owner, true, function () use ($viewer): void {
        auth()->login($viewer);   // e.g. the real login form, which keeps session data across regenerate
        Audit::record('cms.entry.viewed');
    });

    expect(AuditLog::query()->where('action', 'cms.entry.viewed')->value('impersonator_id'))->toBeNull();
});

it('never mixes support and ordinary rows when requests from two sites interleave', function (): void {
    $ownerA = supportMember(1, 'owner');
    $ownerB = supportMember(2, 'owner');

    foreach ([1, 2, 1, 2, 1] as $site) {
        $site === 1
            ? asMember($ownerA, true, fn () => Audit::record('interleave.support'))
            : asMember($ownerB, false, fn () => Audit::record('interleave.plain'), 2);
    }

    expect(AuditLog::query()->where('action', 'interleave.support')->whereNull('impersonator_id')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'interleave.plain')->whereNotNull('impersonator_id')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'interleave.plain')->pluck('site_id')->unique()->all())->toBe([2]);
});

it('ends the session, on record, when the site is paused', function (): void {
    $owner = supportMember(1, 'owner');
    enterSupport($this, supportToken(platformStaff('support'), $owner))->assertStatus(303);

    DB::connection('pgsql_admin')->table('sites')->where('id', 1)->update(['status' => 'suspended']);

    $this->get('http://a.test/admin')->assertRedirectContains('/admin/login');
    $this->assertGuest();
    expect(AuditLog::query()->where('action', 'support.session.ended')->value('meta'))->toBe(['why' => 'site_inactive']);
});

it('records the end of a support session that a new link replaces', function (): void {
    $owner = supportMember(1, 'owner');
    $staff = platformStaff('support');
    enterSupport($this, supportToken($staff, $owner))->assertStatus(303);
    enterSupport($this, supportToken($staff, $owner))->assertStatus(303);

    expect(AuditLog::query()->where('action', 'support.session.ended')->value('meta'))->toBe(['why' => 'replaced']);
});

it('keeps names and reasons as plain text in the owner mail', function (): void {
    $owner = supportMember(1, 'owner');

    $html = (string) app(Tenancy::class)->enter(1, fn () => (new SupportSignInNotification('Staff', '[Bấm đây](https://evil.test)', '**gấp**', '30/09/2026 21:00'))
        ->forCurrentSite()->toMail($owner)->render());

    expect($html)->not->toContain('href="https://evil.test"')->not->toContain('<strong>gấp</strong>');
});

it('shows comparison signs and ampersands in the owner mail as they were typed', function (): void {
    $owner = supportMember(1, 'owner');

    $html = (string) app(Tenancy::class)->enter(1, fn () => (new SupportSignInNotification('Staff', 'Owner', 'price < 5 & stock > 0', '30/09/2026 21:00'))
        ->forCurrentSite()->toMail($owner)->render());

    expect($html)->toContain('price &lt; 5 &amp; stock &gt; 0')->not->toContain('&amp;lt;');
});

it('names a staff member without a name as support staff, never by their email', function (): void {
    $staff = User::factory()->create(['name' => '', 'email' => 'nv@platform.test']);

    expect(SupportSession::staffLabel($staff))->toBe(__('admin.support.staff'));
});

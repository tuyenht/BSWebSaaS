<?php

use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Admin\Support\Members\InvitationDirectory;
use App\Modules\Admin\Support\Members\MemberDirectory;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Invitation;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The "Lời mời" tab of the members screen: the site's invitations by state, and mailing one again,
 * withdrawing it, inviting a withdrawn address again, or opening the member who accepted. An
 * invitation is addressed by its ulid and found through the tenant scope only, so another site's
 * invitation (or a bigint) is never acted on. Mailing and withdrawing need `users.invite`, and only
 * an invitation whose every role the actor may grant (strictly below their level) is theirs to act on.
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

/** A fresh user holding the corporate role template $name in $site's team. */
function invTabMember(string $name, int $site = 1, array $attributes = []): User
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

/** $by invites $email to $site with role $role; returns the plain token. */
function invTabInvite(User $by, string $email, string $role = 'viewer', int $site = 1): string
{
    return app(Tenancy::class)->enter($site, fn (): string => app(InviteMember::class)
        ->handle($by->fresh(), $email, [Role::findByName($role, PermissionRegistry::GUARD)])->token);
}

/** The invitation to $email on $site, as stored. */
function invTabRow(string $email, int $site = 1): ?Invitation
{
    return app(Tenancy::class)->enter($site, fn (): ?Invitation => Invitation::query()->where('email', $email)->first());
}

/** Change the stored invitation to $email on site 1. */
function invTabSet(string $email, array $values): void
{
    app(Tenancy::class)->enter(1, fn () => Invitation::query()->where('email', $email)->update($values));
}

/** The invitations view as $actor sees it on site 1 (state $state), then $then runs in the same site context. */
function invTabPage(User $actor, Closure $then, string $state = 'pending'): mixed
{
    return app(Tenancy::class)->enter(1, fn () => $then(Livewire::actingAs($actor->fresh())->test(Index::class)
        ->set('tab', 'loi-moi')->set('state', $state)));
}

it('lists the invitations of the chosen state with each state counted', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    foreach (['cho@example.test', 'het@example.test', 'huy@example.test', 'nhan@example.test'] as $email) {
        invTabInvite($owner, $email);
    }
    invTabSet('het@example.test', ['expires_at' => now()->subHour()]);
    invTabSet('huy@example.test', ['revoked_at' => now()]);
    invTabSet('nhan@example.test', ['accepted_at' => now(), 'accepted_user_id' => $owner->id]);

    invTabPage($owner, fn (Testable $page) => $page
        ->assertSee('cho@<wbr>example.<wbr>test', false)
        ->assertDontSee('het@<wbr>example', false)
        ->assertViewHas('invCounts', ['pending' => 1, 'expired' => 1, 'revoked' => 1, 'accepted' => 1])
        ->assertSee(__('admin.members.inv_quota_line', ['n' => 4, 'max' => InviteMember::DAILY_LIMIT]))
        ->set('state', 'expired')->assertSee('het@<wbr>example', false)->assertDontSee('cho@<wbr>example', false)
        ->set('state', 'revoked')->assertSee('huy@<wbr>example', false)->assertSee(__('admin.members.inv_reinvite'))
        ->set('state', 'accepted')->assertSee('nhan@<wbr>example', false)->assertSee(__('admin.members.inv_view')));

    // withdrawn means withdrawn and never accepted (the table refuses both stamps; the rule holds without it)
    $both = new Invitation(['email' => 'x@example.test']);
    $both->forceFill(['expires_at' => now()->addDay(), 'accepted_at' => now(), 'revoked_at' => now()]);
    expect(InvitationDirectory::stateOf($both))->toBe('accepted');
});

it('mails an invitation again with a new link, and makes the old link useless', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    $old = invTabInvite($owner, 'tam@example.test');
    $ulid = invTabRow('tam@example.test')->ulid;

    // a double click inside the cooldown spends nothing
    invTabPage($owner, fn (Testable $page) => $page
        ->call('resendInvitation', $ulid)
        ->assertSet('toast', __('rbac.invitation.error_resend_too_soon')));
    expect(invTabRow('tam@example.test')->token_hash)->toBe(Invitation::hashToken($old));

    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();
    invTabPage($owner, fn (Testable $page) => $page
        ->call('resendInvitation', $ulid)
        ->assertSet('toast', __('admin.members.inv_resent', ['email' => 'tam@example.test', 'days' => InviteMember::VALID_DAYS])));

    $fresh = invTabRow('tam@example.test');
    expect($fresh->token_hash)->not->toBe(Invitation::hashToken($old))
        ->and($fresh->ulid)->toBe($ulid)
        ->and($fresh->expires_at->greaterThan(now()->addDays(InviteMember::VALID_DAYS - 1)))->toBeTrue();
    // the first invitation was issued straight through InviteMember (no mail); the screen mailed the second
    Notification::assertSentOnDemandTimes(MemberInvitationNotification::class, 1);
});

it('withdraws an invitation only after the question, and keeps it on "Giữ lời mời"', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    invTabInvite($owner, 'tam@example.test');
    $ulid = invTabRow('tam@example.test')->ulid;

    invTabPage($owner, fn (Testable $page) => $page
        ->call('askRevoke', $ulid)
        ->assertSet('revokeOpen', true)
        ->assertSee(__('admin.members.rv_title', ['email' => 'tam@example.test']))
        ->assertSee(__('admin.members.rv_keep'))
        ->call('cancelRevoke')
        ->assertSet('revokeOpen', false));
    expect(invTabRow('tam@example.test')->revoked_at)->toBeNull();

    invTabPage($owner, fn (Testable $page) => $page
        ->call('askRevoke', $ulid)->call('confirmRevoke')
        ->assertSet('toast', __('admin.members.inv_revoked', ['email' => 'tam@example.test'])));
    expect(invTabRow('tam@example.test')->revoked_at)->not->toBeNull();
});

it('keeps a member from mailing or withdrawing an invitation that carries a role at or above their level', function (): void {
    $owner = invTabMember('owner');
    $admin = invTabMember('admin');
    invTabInvite($owner, 'cao@example.test', 'admin');
    $row = invTabRow('cao@example.test');
    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    invTabPage($admin, fn (Testable $page) => $page
        ->assertSee(__('admin.members.inv_why_level', ['name' => $owner->name]))
        ->call('resendInvitation', $row->ulid)
        ->assertSet('toast', __('admin.members.inv_why_level_short'))
        ->call('askRevoke', $row->ulid)
        ->assertSet('revokeOpen', false)
        // a forged question is checked again when it is answered
        ->set('revokeFor', $row->ulid)->set('revokeOpen', true)->call('confirmRevoke'));

    $after = invTabRow('cao@example.test');
    expect($after->token_hash)->toBe($row->token_hash)->and($after->revoked_at)->toBeNull();
});

it('never acts on another site’s invitation, or on one named by its bigint', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    $other = invTabMember('owner', 2);
    invTabMember('viewer', 2);
    invTabInvite($other, 'khac@example.test', 'viewer', 2);
    $foreign = invTabRow('khac@example.test', 2);
    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    invTabPage($owner, fn (Testable $page) => $page
        ->assertDontSee('khac@<wbr>example', false)
        ->call('resendInvitation', $foreign->ulid)->assertSet('toast', __('admin.members.inv_gone'))
        ->call('askRevoke', $foreign->ulid)->assertSet('revokeOpen', false)
        ->call('resendInvitation', (string) $foreign->id)->assertSet('toast', __('admin.members.inv_gone'))
        ->set('revokeFor', $foreign->ulid)->call('confirmRevoke'));

    $after = invTabRow('khac@example.test', 2);
    expect($after->token_hash)->toBe($foreign->token_hash)->and($after->revoked_at)->toBeNull();
});

it('shows the invitations to a member without "Mời thành viên" but refuses their writes', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    invTabInvite($owner, 'tam@example.test');
    $ulid = invTabRow('tam@example.test')->ulid;
    $keeper = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($keeper): void {
        $role = Role::findOrCreate('member-keeper', PermissionRegistry::GUARD);
        $role->forceFill(['level' => 60])->save();
        $role->syncPermissions(['users.manage']);
        $keeper->assignRole($role);
    });

    invTabPage($keeper, fn (Testable $page) => $page
        ->assertSee('tam@<wbr>example', false)
        ->assertSee(__('admin.members.inv_noperm', ['role' => 'member-keeper']))
        ->assertDontSee(__('admin.members.inv_resend_aria', ['email' => 'tam@example.test']))
        ->call('resendInvitation', $ulid)->assertForbidden());

    invTabPage($keeper, fn (Testable $page) => $page->call('askRevoke', $ulid)->assertForbidden());
    invTabPage($keeper, fn (Testable $page) => $page->call('reinvite', $ulid)->assertForbidden());
});

it('fills the invite dialog from a withdrawn invitation, and opens the member of an accepted one', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    $joined = invTabMember('viewer', 1, ['email' => 'nhan@example.test']);
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'huy@example.test', [Role::findByName('viewer', PermissionRegistry::GUARD)], 'Chào em'));
    invTabSet('huy@example.test', ['revoked_at' => now()]);
    DB::connection('pgsql_admin')->table('invitations')->insert([
        'ulid' => strtoupper((string) Str::ulid()), 'site_id' => 1, 'email' => 'nhan@example.test', 'token_hash' => Invitation::hashToken('x'),
        'invited_by' => $owner->id, 'expires_at' => now()->addDays(3), 'sent_at' => now()->subDays(4),
        'accepted_at' => now()->subDays(3), 'accepted_user_id' => $joined->id, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $revoked = invTabRow('huy@example.test')->ulid;
    $accepted = invTabRow('nhan@example.test')->ulid;
    invTabPage($owner, fn (Testable $page) => $page
        ->call('reinvite', $revoked)
        ->assertSet('inviteOpen', true)
        ->assertSet('inviteEmail', 'huy@example.test')
        ->assertSet('inviteRoles', ['viewer'])
        ->assertSet('inviteMessage', 'Chào em'), 'revoked');

    invTabPage($owner, fn (Testable $page) => $page
        ->call('viewMember', $accepted)
        ->assertSet('tab', '')
        ->assertSet('search', 'nhan@example.test')
        ->assertSee($joined->name), 'accepted');
});

it('pages through the invitations by keyset, newest invitation first', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    $per = MemberDirectory::PER_PAGE[0];
    for ($i = 1; $i <= $per + 3; $i++) {
        invTabInvite($owner, sprintf('m%02d@example.test', $i));
        $this->travel(1)->seconds();
    }
    $newest = sprintf('m%02d@', $per + 3);
    // mailed again just now: it keeps its place (the order is when it was created, which the index serves)
    invTabSet('m01@example.test', ['sent_at' => now()]);

    // the pager's own links carry the cursor (an invitation ulid), as the browser would send it
    $cursor = function (Testable $page, string $way): string {
        preg_match('/wire:click="'.$way.'\(\'([0-9A-Z]{26})\'\)"/', $page->html(), $m);
        expect($m)->toHaveKey(1);

        return $m[1];
    };
    invTabPage($owner, function (Testable $page) use ($per, $newest, $cursor): void {
        $page->set('per', $per)
            ->assertSee($newest.'<wbr>', false)
            ->assertDontSee('m01@<wbr>', false);
        $page->call('older', $cursor($page, 'older'))
            ->assertSee('m01@<wbr>', false)
            ->assertDontSee($newest.'<wbr>', false);
        $page->call('newer', $cursor($page, 'newer'))
            ->assertSee($newest.'<wbr>', false);
    });
});

it('stops inviting once the site has mailed its daily share, and says when it may again', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    app(Tenancy::class)->enter(1, fn () => app(InviteMember::class)->handle($owner->fresh(), 'huy@example.test', [Role::findByName('viewer', PermissionRegistry::GUARD)]));
    invTabSet('huy@example.test', ['revoked_at' => now()]);
    DB::connection('pgsql_admin')->table('audit_logs')->delete();
    $revoked = invTabRow('huy@example.test')->ulid;
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into audit_logs (ulid, action, site_id, meta, created_at)
        select lpad(g::text, 26, '0'), 'rbac.invitation.sent', 1, jsonb_build_object('email', 'cu-' || g || '@example.test'), now() - interval '1 hour'
        from generate_series(1, ?) g
    SQL, [InviteMember::DAILY_LIMIT]);

    invTabPage($owner, fn (Testable $page) => $page
        ->assertSee(__('admin.members.lim_full_title', ['max' => InviteMember::DAILY_LIMIT]))
        ->call('openInvite')
        ->assertSet('inviteOpen', false)
        ->assertSet('toast', __('admin.members.inv_full', ['max' => InviteMember::DAILY_LIMIT]))
        // "Mời lại" waits for the same daily share instead of opening a dialog that would refuse at "Gửi"
        ->set('toast', null)
        ->call('reinvite', $revoked)
        ->assertSet('inviteOpen', false)
        ->assertSet('inviteEmail', '')
        ->assertSet('toast', __('admin.members.inv_full', ['max' => InviteMember::DAILY_LIMIT])), 'revoked');
});

it('says when inviting reopens in the site clock, naming the day when it is not today', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    $fill = function (CarbonImmutable $at): void {
        DB::connection('pgsql_admin')->table('audit_logs')->delete();
        DB::connection('pgsql_admin')->statement(<<<'SQL'
            insert into audit_logs (ulid, action, site_id, meta, created_at)
            select lpad(g::text, 26, '0'), 'rbac.invitation.sent', 1, jsonb_build_object('email', 'cu-' || g || '@example.test'), ?::timestamptz
            from generate_series(1, ?) g
        SQL, [$at->toIso8601String(), InviteMember::DAILY_LIMIT]);
    };

    // 20:00 in Hà Nội; the mails went out an hour ago, so the share comes back at 19:00 tomorrow
    $this->travelTo(CarbonImmutable::parse('2026-11-13 20:00', 'Asia/Ho_Chi_Minh'));
    $fill(now()->toImmutable()->subHour());
    invTabPage($owner, fn (Testable $page) => $page
        ->assertSee(__('admin.members.lim_full_text', ['time' => '19:00 ngày 14/11/2026'])));

    // mailed 23 hours ago: back at 21:00 the same evening, the time alone
    $fill(now()->toImmutable()->subHours(23));
    invTabPage($owner, fn (Testable $page) => $page
        ->assertSee(__('admin.members.lim_full_text', ['time' => '21:00'])));
});

it('treats an invitation whose roles were all deleted as gone when it is mailed again', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    invTabInvite($owner, 'tam@example.test');
    $row = invTabRow('tam@example.test');
    DB::connection('pgsql_admin')->table('invitation_roles')->where('invitation_id', $row->id)->delete();
    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();

    invTabPage($owner, fn (Testable $page) => $page
        ->call('resendInvitation', $row->ulid)
        ->assertSet('toast', __('admin.members.inv_gone')));

    expect(invTabRow('tam@example.test')->token_hash)->toBe($row->token_hash);
    Notification::assertNothingSent();
});

it('shows the waiting count on the "Lời mời" tab from either tab, capped at 99+, and the state counts only on the invitations', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    $viewer = DB::connection('pgsql_admin')->table('roles')->where('site_id', 1)->where('name', 'viewer')->value('id');
    DB::connection('pgsql_admin')->statement(<<<'SQL'
        insert into invitations (ulid, site_id, email, token_hash, invited_by, expires_at, sent_at, created_at, updated_at)
        select lpad(g::text, 26, '0'), 1, 'cho-' || g || '@example.test', md5(g::text) || md5(g::text), ?, now() + interval '3 days', now(), now(), now()
        from generate_series(1, ?) g
    SQL, [$owner->id, InvitationDirectory::TAB_CAP + 5]);
    DB::connection('pgsql_admin')->statement('insert into invitation_roles (site_id, invitation_id, role_id) select 1, id, ? from invitations', [$viewer]);

    // the "Lời mời" tab button, up to its end, holds the capped count
    $label = '/id="members-view-tab-loi-moi"(?:(?!<\/button>).)*>'.preg_quote(InvitationDirectory::TAB_CAP.'+', '/').'<\/span>/s';
    app(Tenancy::class)->enter(1, function () use ($owner, $label): void {
        $page = Livewire::actingAs($owner->fresh())->test(Index::class)
            ->assertViewHas('invPending', InvitationDirectory::TAB_CAP + 1)
            // the members tab reads no per-state count
            ->assertViewHas('invCounts', array_fill_keys(InvitationDirectory::STATES, 0));
        expect($page->html())->toMatch($label);

        $page->set('tab', 'loi-moi')
            ->assertViewHas('invPending', InvitationDirectory::TAB_CAP + 1)
            ->assertViewHas('invCounts', ['pending' => InvitationDirectory::TAB_CAP + 5, 'expired' => 0, 'revoked' => 0, 'accepted' => 0]);
        expect($page->html())->toMatch($label);
    });
});

it('mails and withdraws nothing while support staff are signed in as the member', function (): void {
    $owner = invTabMember('owner');
    invTabMember('viewer');
    invTabInvite($owner, 'tam@example.test');
    $row = invTabRow('tam@example.test');
    $this->travel(InviteMember::RESEND_COOLDOWN_SECONDS + 1)->seconds();

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

    invTabPage($owner, fn (Testable $page) => $page
        ->call('resendInvitation', $row->ulid)->assertSet('toast', __('admin.support.locked'))
        ->call('askRevoke', $row->ulid)->assertSet('revokeOpen', false)
        ->set('revokeFor', $row->ulid)->call('confirmRevoke'));

    $after = invTabRow('tam@example.test');
    expect($after->token_hash)->toBe($row->token_hash)->and($after->revoked_at)->toBeNull();
});

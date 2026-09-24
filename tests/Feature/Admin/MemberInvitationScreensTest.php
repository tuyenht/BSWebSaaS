<?php

use App\Modules\Admin\Livewire\AcceptInvitation;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Admin\Livewire\Users\Index;
use App\Modules\Admin\Notifications\MemberInvitationNotification;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Rbac\Actions\InviteMember;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * The invitation screens. "Invite member" answers with the same sentence whatever the address —
 * it used to say "no account with this email", letting any site admin probe the platform's user
 * list. The invitation page (sign-in frame, owner decision Q9) shows who invites whom to what, and
 * one road: create the account, sign in first (then come back here), join, or sign out of the
 * wrong account.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate invitations, invitation_roles, audit_logs, users, site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    fakePwnedVerifier();
});

/** A fresh user holding the corporate role template $name in site 1. */
function screensMember(string $name, array $attributes = []): User
{
    $tpl = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $perms = $tpl['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $tpl['permissions'];
    $user = User::factory()->create($attributes);

    app(Tenancy::class)->enter(1, function () use ($name, $tpl, $perms, $user): void {
        $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
        $role->forceFill(['level' => $tpl['level'], 'is_system' => true])->save();
        $role->syncPermissions($perms);
        $user->assignRole($role);
    });

    return $user;
}

/** Invite $email to site 1 as a viewer; returns the plain token. */
function screensInvite(string $email): string
{
    $owner = screensMember('owner');
    screensMember('viewer');

    return app(Tenancy::class)->enter(1, fn (): string => app(InviteMember::class)
        ->handle($owner->fresh(), $email, [Role::findByName('viewer', PermissionRegistry::GUARD)])->token);
}

it('answers "invite member" with the same sentence whether or not the address has an account', function (): void {
    Notification::fake();
    $owner = screensMember('owner');
    screensMember('viewer');
    User::factory()->create(['email' => 'da-co@example.test']);

    foreach (['da-co@example.test', 'chua-co@example.test'] as $email) {
        app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())
            ->test(Index::class)
            ->set('inviteEmail', $email)
            ->set('inviteRole', 'viewer')
            ->call('addMember')
            ->assertHasNoErrors()
            // One sentence template for both — nothing tells the admin which address has an account.
            ->assertSee(__('rbac.invitation.sent', ['email' => $email, 'days' => InviteMember::VALID_DAYS])));
    }

    Notification::assertSentOnDemandTimes(MemberInvitationNotification::class, 2);
});

it('mails a link to the invitation page on the site’s own host', function (): void {
    Notification::fake();
    URL::forceRootUrl('http://a.test');   // the admin request's host, as in a real invite
    $owner = screensMember('owner');
    screensMember('viewer');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner->fresh())
        ->test(Index::class)
        ->set('inviteEmail', 'moi@example.test')
        ->set('inviteRole', 'viewer')
        ->call('addMember'));

    Notification::assertSentOnDemand(MemberInvitationNotification::class, function (MemberInvitationNotification $note, array $channels, object $notifiable): bool {
        app()->setLocale((string) $note->locale);   // the sender renders the mail in the notification's locale
        $mail = $note->toMail($notifiable);

        return $notifiable->routes['mail'] === 'moi@example.test'
            && str_starts_with((string) $mail->actionUrl, 'http://a.test/admin/invitations/')
            && str_contains(implode(' ', $mail->introLines), 'Chỉ xem');   // role by its label, never `viewer`
    });
});

it('offers a new address the create-account form', function (): void {
    $token = screensInvite('moi@example.test');

    $this->get("http://a.test/admin/invitations/{$token}")
        ->assertOk()
        ->assertSee(__('rbac.invitation.create_account'))
        ->assertSee('moi@example.test');
});

it('creates the account and sends it to sign in', function (): void {
    $token = screensInvite('moi@example.test');

    app(Tenancy::class)->enter(1, fn () => Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->set('name', 'Nguyễn Văn An')
        ->set('password', 'Mat-khau-rat-manh-2026')
        ->set('password_confirmation', 'Mat-khau-rat-manh-2026')
        ->call('createAccount')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.login')));

    expect(session('status'))->toBe(__('rbac.invitation.account_created'))
        ->and(User::query()->where('email', 'moi@example.test')->exists())->toBeTrue();
});

it('asks an existing account to sign in, then brings it back to the invitation', function (): void {
    User::factory()->create(['email' => 'da-co@example.test']);
    $token = screensInvite('da-co@example.test');

    $this->get("http://a.test/admin/invitations/{$token}")
        ->assertOk()
        ->assertSee(__('rbac.invitation.sign_in'))
        ->assertDontSee(__('rbac.invitation.create_account'));

    app(Tenancy::class)->enter(1, fn () => Livewire::test(AcceptInvitation::class, ['token' => $token])
        ->call('signIn')
        ->assertRedirect(route('admin.login')));

    expect(session('url.intended'))->toBe(route('admin.invitations.accept', ['token' => $token]));
});

it('returns to the page that sent the visitor to sign in, after the second factor', function (): void {
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $user = User::factory()->create();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));
    session(['2fa.user_id' => $user->getAuthIdentifier(), 'url.intended' => 'http://a.test/admin/invitations/'.str_repeat('a', 64)]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', (new Google2FA)->getCurrentOtp($secret))
        ->call('verify')
        ->assertHasNoErrors()
        ->assertRedirect('http://a.test/admin/invitations/'.str_repeat('a', 64));
});

it('joins the signed-in invited account and opens the admin', function (): void {
    $invited = User::factory()->create(['email' => 'da-co@example.test']);
    $token = screensInvite('da-co@example.test');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($invited)
        ->test(AcceptInvitation::class, ['token' => $token])
        ->call('join')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.dashboard')));

    expect(app(Tenancy::class)->enter(1, fn (): array => $invited->fresh()->roles()->pluck('name')->all()))->toBe(['viewer']);
});

it('tells a visitor signed in as another account, and does not join it', function (): void {
    $other = User::factory()->create(['email' => 'nguoi-khac@example.test']);
    $token = screensInvite('moi@example.test');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($other)
        ->test(AcceptInvitation::class, ['token' => $token])
        ->assertSee(__('rbac.invitation.sign_out'))
        ->call('join')
        ->assertHasErrors('invitation'));

    expect(app(Tenancy::class)->enter(1, fn (): array => $other->fresh()->roles()->pluck('name')->all()))->toBe([]);
});

it('explains a used or unknown link in plain words', function (): void {
    $this->get('http://a.test/admin/invitations/'.str_repeat('x', 64))
        ->assertOk()
        ->assertSee(__('rbac.invitation.error_invalid'));
});

it('does not route a malformed invitation link', function (): void {
    $this->get('http://a.test/admin/invitations/ngan-qua')->assertNotFound();
});

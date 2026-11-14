<?php

use App\Modules\Admin\Notifications\OwnerRecoveredNotification;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Actions\RecoverSiteOwner;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Exceptions\RoleChangeRejectedException;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;

/*
 * site:recover-owner — the one way platform staff give a website an owner again when its owners
 * are locked out (they change no member on the website's own screens). A written reason, an audit
 * row in the same transaction, and a mail to every owner the website had and to the new one.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Notification::fake();
    $this->operator = User::factory()->create(['email' => 'van-hanh@example.test']);
    app(PlatformAccess::class)->onPlatform(fn () => $this->operator->assignRole('support'));
});

function recoverMember(string $name, int $site = 1, array $attributes = []): User
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

function recoverRoles(User $user, int $site = 1): array
{
    return app(Tenancy::class)->enter($site, fn (): array => $user->fresh()?->roles()->orderBy('name')->pluck('name')->all() ?? []);
}

function recoverAudit(): array
{
    return app(Tenancy::class)->enter(1, fn (): array => AuditLog::query()->where('action', RecoverSiteOwner::ACTION)->get(['site_id', 'actor_id', 'actor_label', 'auditable_id', 'meta'])->toArray());
}

it('makes the account an owner, audits it with the reason and mails every owner and the new one', function (): void {
    $oldOwner = recoverMember('owner', attributes: ['email' => 'chu-cu@example.test']);
    $newOwner = recoverMember('viewer', attributes: ['email' => 'chu-moi@example.test']);

    $this->artisan('site:recover-owner', ['--operator' => 'van-hanh@example.test', 'host' => 'a.test', 'email' => 'Chu-Moi@example.test', '--reason' => 'Chủ cũ mất hộp thư, đã xác minh qua hợp đồng'])
        ->expectsConfirmation('Make chu-moi@example.test an owner of a.test?', 'yes')
        ->expectsOutputToContain('chu-moi@example.test is now an owner of a.test')
        ->assertSuccessful();

    expect(recoverRoles($newOwner))->toBe(['owner', 'viewer'])
        ->and(recoverRoles($oldOwner))->toBe(['owner']);

    $audit = recoverAudit();
    expect($audit)->toHaveCount(1)
        ->and($audit[0]['site_id'])->toBe(1)
        ->and($audit[0]['actor_id'])->toBe($this->operator->id)
        ->and($audit[0]['actor_label'])->toBe('van-hanh@example.test')
        ->and($audit[0]['auditable_id'])->toBe($newOwner->id)
        ->and($audit[0]['meta'])->toMatchArray(['email' => 'chu-moi@example.test', 'reason' => 'Chủ cũ mất hộp thư, đã xác minh qua hợp đồng', 'owners_before' => 1, 'via' => 'console']);

    foreach ([$oldOwner, $newOwner] as $person) {
        Notification::assertSentTo($person, OwnerRecoveredNotification::class, function (OwnerRecoveredNotification $n) use ($person): bool {
            $mail = $n->toMail($person);

            return $n->tenantSiteId === 1
                && $n->facts()['email'] === 'chu-moi@example.test'
                && $n->facts()['reason'] === 'Chủ cũ mất hộp thư, đã xác minh qua hợp đồng'
                && $mail->subject === __('notifications.mail.owner_recovered_subject');
        });
    }
    Notification::assertCount(2);
});

it('rescues a website that has no owner left who can sign in', function (): void {
    $editor = recoverMember('editor');
    // the owner role is there, but nobody holds it any more
    app(Tenancy::class)->enter(1, function (): void {
        $role = Role::findOrCreate('owner', PermissionRegistry::GUARD);
        $role->forceFill(['level' => PermissionRegistry::tenantRoleTemplates('corporate')['owner']['level'], 'is_system' => true])->save();
    });

    $this->artisan('site:recover-owner', ['--operator' => 'van-hanh@example.test', 'host' => 'a.test', 'email' => $editor->email, '--reason' => 'Không còn chủ website', '--yes' => true])
        ->assertSuccessful();

    expect(recoverRoles($editor))->toBe(['editor', 'owner']);
    Notification::assertSentTo($editor, OwnerRecoveredNotification::class);
    Notification::assertCount(1);
});

it('changes nothing without a reason, an answer of yes, a known website or a known account', function (array $args, ?string $answer): void {
    $owner = recoverMember('owner');
    $member = recoverMember('viewer', attributes: ['email' => 'thanh-vien@example.test']);

    $run = $this->artisan('site:recover-owner', $args + ['email' => 'thanh-vien@example.test', '--operator' => 'van-hanh@example.test']);
    if ($answer !== null) {
        $run->expectsConfirmation('Make thanh-vien@example.test an owner of a.test?', $answer);
    }
    $run->assertFailed();

    expect(recoverRoles($member))->toBe(['viewer'])
        ->and(recoverRoles($owner))->toBe(['owner'])
        ->and(recoverAudit())->toBe([]);
    Notification::assertNothingSent();
})->with([
    'no reason' => [['host' => 'a.test'], null],
    'blank reason' => [['host' => 'a.test', '--reason' => '   '], null],
    'answered no' => [['host' => 'a.test', '--reason' => 'Chủ mất hộp thư'], 'no'],
    'unknown website' => [['host' => 'khong-co.test', '--reason' => 'Chủ mất hộp thư', '--yes' => true], null],
]);

it('refuses an unknown account, an account already owner, and platform staff', function (string $case): void {
    $owner = recoverMember('owner', attributes: ['email' => 'chu@example.test']);
    $staff = User::factory()->create(['email' => 'nhan-vien@example.test']);
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('support'));

    $email = match ($case) {
        'unknown' => 'khong-ai@example.test',
        'already owner' => 'chu@example.test',
        'staff' => 'nhan-vien@example.test',
    };
    $this->artisan('site:recover-owner', ['--operator' => 'van-hanh@example.test', 'host' => 'a.test', 'email' => $email, '--reason' => 'Chủ mất hộp thư', '--yes' => true])
        ->assertFailed();

    expect(recoverRoles($owner))->toBe(['owner'])
        ->and(recoverRoles($staff))->toBe([])
        ->and(recoverAudit())->toBe([]);
    Notification::assertNothingSent();
})->with(['unknown', 'already owner', 'staff']);

it('never gives a website owner role from another website', function (): void {
    recoverMember('owner', 2);
    $member = recoverMember('viewer', 1);

    // site 1 has no owner role of its own: the recovery refuses rather than reach into site 2
    $this->artisan('site:recover-owner', ['--operator' => 'van-hanh@example.test', 'host' => 'a.test', 'email' => $member->email, '--reason' => 'Chủ mất hộp thư', '--yes' => true])
        ->assertFailed();

    expect(recoverRoles($member, 1))->toBe(['viewer'])
        ->and(recoverRoles($member, 2))->toBe([]);
});

it('changes nothing unless a platform staff account runs it, named in --operator', function (?string $operator): void {
    $owner = recoverMember('owner');
    $member = recoverMember('viewer', attributes: ['email' => 'thanh-vien@example.test']);
    recoverMember('editor', attributes: ['email' => 'bien-tap@example.test']);

    $args = ['host' => 'a.test', 'email' => 'thanh-vien@example.test', '--reason' => 'Chủ mất hộp thư', '--yes' => true];
    $this->artisan('site:recover-owner', $operator === null ? $args : $args + ['--operator' => $operator])
        ->assertFailed();

    expect(recoverRoles($member))->toBe(['viewer'])
        ->and(recoverRoles($owner))->toBe(['owner'])
        ->and(recoverAudit())->toBe([]);
    Notification::assertNothingSent();
})->with([
    'no operator' => [null],
    'a website member, not staff' => ['bien-tap@example.test'],
    'no such account' => ['khong-ai@example.test'],
]);

it('refuses a recovery run by someone who is not platform staff, at the action too', function (): void {
    recoverMember('owner');
    $member = recoverMember('viewer');
    $notStaff = recoverMember('editor');

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(RecoverSiteOwner::class)->handle($notStaff->fresh(), $member->fresh(), 'Chủ mất hộp thư')))
        ->toThrow(RoleChangeRejectedException::class, 'not_operator');

    expect(recoverRoles($member))->toBe(['viewer'])
        ->and(recoverAudit())->toBe([]);
});

it('sends the notices only after the change has committed', function (): void {
    recoverMember('owner');
    $member = recoverMember('viewer');
    $fake = new class extends NotificationFake
    {
        /** @var list<int> */
        public array $levels = [];

        public function send($notifiables, $notification)
        {
            $this->levels[] = DB::transactionLevel();

            parent::send($notifiables, $notification);
        }
    };
    Notification::swap($fake);

    $this->artisan('site:recover-owner', ['--operator' => 'van-hanh@example.test', 'host' => 'a.test', 'email' => $member->email, '--reason' => 'Chủ mất hộp thư', '--yes' => true])
        ->assertSuccessful();

    expect($fake->levels)->toBe([0, 0]);
    Notification::assertCount(2);
});

it('still queues the other notices and reports the change when one notice cannot be queued', function (): void {
    recoverMember('owner', attributes: ['email' => 'chu-cu@example.test']);
    $member = recoverMember('viewer', attributes: ['email' => 'chu-moi@example.test']);
    $fake = new class extends NotificationFake
    {
        public function send($notifiables, $notification)
        {
            if ($notifiables instanceof User && $notifiables->email === 'chu-cu@example.test') {
                throw new RuntimeException('queue is down');
            }

            parent::send($notifiables, $notification);
        }
    };
    Notification::swap($fake);

    $this->artisan('site:recover-owner', ['--operator' => 'van-hanh@example.test', 'host' => 'a.test', 'email' => $member->email, '--reason' => 'Chủ mất hộp thư', '--yes' => true])
        ->expectsOutputToContain('chu-moi@example.test is now an owner of a.test. The change is in the audit log; a notice was queued to 1 address(es).')
        ->expectsOutputToContain('The notice to chu-cu@example.test was not queued.')
        ->assertSuccessful();

    expect(recoverRoles($member))->toBe(['owner', 'viewer'])
        ->and(recoverAudit())->toHaveCount(1);
    Notification::assertSentTo($member, OwnerRecoveredNotification::class);
    Notification::assertCount(1);
});

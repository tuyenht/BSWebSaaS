<?php

use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Notifications\TwoFactorDisabledNotification;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Forms\Actions\SubmitForm;
use App\Modules\Forms\Models\Form;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Notifications\Channels\SiteDatabaseChannel;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Registry\NotificationKinds;
use App\Modules\Notifications\Support\NotificationFeed;
use App\Modules\Notifications\Support\NotificationPreferences;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * In-app notifications (design.md §15, decision Q5): stored per person per site (RLS), sent by the
 * events that exist — an entry waiting for review / going live / coming back / handed to someone,
 * a new enquiry, two-step sign-in switched off — never to the person who caused them, filtered by
 * each person's choices (security notices cannot be switched off), read newest-first with an
 * unread badge, and removed after 90 days.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications, users, site_settings, platform_settings, audit_logs restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    config(['services.turnstile.secret_key' => '']);
});

/** A fresh user holding the corporate role template $name in $site. */
function noticeMember(int $site, string $name): User
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

/** @return list<string> the kinds $user has on $site, newest first */
function noticeKinds(int $site, User $user): array
{
    return app(Tenancy::class)->enter($site, fn (): array => SiteNotification::query()
        ->where('user_id', $user->id)->orderByDesc('id')->pluck('kind')->all());
}

it('keeps the kind registry and the database CHECK in step', function (): void {
    $definition = (string) DB::connection('pgsql_admin')->scalar(
        "select pg_get_constraintdef(oid) from pg_constraint where conname = 'site_notifications_kind_check'"
    );

    foreach (array_keys(NotificationKinds::all()) as $kind) {
        expect($definition)->toContain("'{$kind}'");
    }

    $user = User::factory()->create();
    expect(fn () => DB::connection('pgsql_admin')->table('site_notifications')->insert([
        'ulid' => (string) str()->ulid(), 'site_id' => 1, 'user_id' => $user->id, 'kind' => 'made.up', 'data' => '{}',
    ]))->toThrow(QueryException::class, 'site_notifications_kind_check');
});

it('keeps a notice on its own site', function (): void {
    $user = noticeMember(1, 'viewer');

    app(Tenancy::class)->enter(1, fn () => Notify::user($user->id, 'lead.new', ['name' => 'Khách A']));

    expect(noticeKinds(1, $user))->toBe(['lead.new'])
        ->and(noticeKinds(2, $user))->toBe([]);
});

it('never notifies the person who caused the event', function (): void {
    $user = noticeMember(1, 'owner');

    app(Tenancy::class)->enter(1, fn () => Notify::user($user->id, 'workflow.published', [], except: $user));

    expect(noticeKinds(1, $user))->toBe([]);
});

it('respects a person turning a kind off — except security notices', function (): void {
    $user = noticeMember(1, 'owner');
    $user->forceFill(['preferences' => ['notifications' => [
        'lead.new' => ['in_app' => false],
        'security.two_factor_disabled' => ['in_app' => false],
    ]]])->save();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        Notify::user($user->id, 'lead.new', ['name' => 'Khách A']);

        // The security notice is queued in production; deliver its in-app half here directly so
        // the test never depends on which queue the environment points at.
        $notice = new TwoFactorDisabledNotification('24/09/2026 09:00');
        expect($notice->via($user->fresh()))->toContain(SiteDatabaseChannel::class);
        app(SiteDatabaseChannel::class)->send($user->fresh(), $notice);
    });

    expect(noticeKinds(1, $user))->toBe(['security.two_factor_disabled'])
        ->and(NotificationPreferences::wants($user->fresh(), 'security.two_factor_disabled', 'mail'))->toBeTrue();
});

it('reads newest first, pages by ulid, and marks only the reader’s own notices', function (): void {
    $reader = noticeMember(1, 'owner');
    $other = noticeMember(1, 'viewer');

    app(Tenancy::class)->enter(1, function () use ($reader, $other): void {
        foreach (['A', 'B', 'C'] as $name) {
            Notify::user($reader->id, 'lead.new', ['name' => $name]);
        }
        Notify::user($other->id, 'lead.new', ['name' => 'Không phải của bạn']);

        $feed = app(NotificationFeed::class);
        $first = $feed->latest($reader, 2);
        $next = $feed->latest($reader, 2, $first->last()->ulid);

        expect($first->map(fn (SiteNotification $n): string => $n->summary())->all())
            ->toBe([__('notifications.lead.new', ['name' => 'C']), __('notifications.lead.new', ['name' => 'B'])])
            ->and($next->pluck('data.name')->all())->toBe(['A'])
            ->and($feed->unreadCount($reader))->toBe(3);

        $feed->markRead($reader, SiteNotification::query()->where('user_id', $other->id)->value('ulid'));   // not theirs
        expect($feed->unreadCount($other))->toBe(1);

        expect($feed->markAllRead($reader))->toBe(3)
            ->and($feed->unreadCount($reader))->toBe(0);
    });
});

it('tells the publishers when an entry waits for review, and the author when it goes live', function (): void {
    $author = noticeMember(1, 'contributor');
    $publisher = noticeMember(1, 'editor');

    $entry = app(Tenancy::class)->enter(1, function () use ($author): Entry {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu xưởng', 'slug' => 'gioi-thieu-xuong', 'status' => 'draft']);

        return $entry;
    });

    app(Tenancy::class)->enter(1, fn () => app(PublishEntry::class)->handle($entry->fresh(), 'vi', $author->fresh()));

    expect(noticeKinds(1, $publisher))->toBe(['workflow.pending_review'])
        ->and(noticeKinds(1, $author))->toBe([]);   // not the one who submitted

    app(Tenancy::class)->enter(1, fn () => app(PublishEntry::class)->handle($entry->fresh(), 'vi', $publisher->fresh()));

    expect(noticeKinds(1, $author))->toBe(['workflow.published']);
});

it('tells the people who can read leads about a new enquiry', function (): void {
    $owner = noticeMember(1, 'owner');
    $author = noticeMember(1, 'author');   // may not read leads

    app(Tenancy::class)->enter(1, function (): void {
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'notify_emails' => []]);
        app(SubmitForm::class)->handle($form, ['name' => 'Chị Lan', 'phone' => '0900000000', 'message' => 'Báo giá']);
    });

    expect(noticeKinds(1, $owner))->toBe(['lead.new'])
        ->and(noticeKinds(1, $author))->toBe([]);
});

it('warns the account owner, on record, when two-step sign-in is switched off', function (): void {
    Notification::fake();
    $user = noticeMember(1, 'owner');
    $service = app(TwoFactorService::class);
    $secret = $service->generateSecret();
    $service->enable($user, $secret, (new Google2FA)->getCurrentOtp($secret));

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($user->fresh())->test(Settings::class)->call('disableTwoFactor'));

    Notification::assertSentTo($user, TwoFactorDisabledNotification::class, function (TwoFactorDisabledNotification $note, array $channels): bool {
        return in_array('mail', $channels, true) && $note->tenantSiteId === 1;
    });
    expect(DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'auth.2fa.disabled')->count())->toBe(1);
});

it('removes notifications older than 90 days, on every site', function (): void {
    $one = noticeMember(1, 'owner');
    $two = noticeMember(2, 'owner');

    foreach ([1 => $one, 2 => $two] as $site => $user) {
        app(Tenancy::class)->enter($site, function () use ($user): void {
            Notify::user($user->id, 'lead.new', ['name' => 'Cũ']);
            SiteNotification::query()->latest('id')->firstOrFail()->forceFill(['created_at' => now()->subDays(91)])->save();
            Notify::user($user->id, 'lead.new', ['name' => 'Mới']);
        });
    }

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(noticeKinds(1, $one))->toBe(['lead.new'])
        ->and(noticeKinds(2, $two))->toBe(['lead.new'])
        ->and(app(Tenancy::class)->enter(1, fn () => SiteNotification::query()->value('data')))->toBe(['name' => 'Mới']);
});

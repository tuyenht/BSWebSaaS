<?php

use App\Modules\Admin\Livewire\Settings\Redirects;
use App\Modules\Admin\Support\Redirects\RedirectBook;
use App\Modules\Admin\Support\SiteFeatures;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Routing\RedirectRules;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Actions\OverrideSiteFeature;
use App\Modules\Tenancy\Actions\SetPlanFeatures;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Registry\FeatureRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Redirects to another website. Off by default, per website: only a super_admin turns the website's
 * `redirects.external` switch on (no plan carries it). While it is off the screen does not offer
 * another website's address, a tampered request cannot store one, and the storefront ignores the
 * rows that exist — no redirect, no error. While it is on, a member who manages settings may add one;
 * the address must be a plain https address and is stored and followed exactly as checked. An old
 * address under a sign-in or /admin path is refused, read from the route definitions. Every change
 * to such a row is audited (who, from, to). Another website's switch and rows are never reached.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    DB::connection('pgsql_admin')->statement('truncate audit_logs');
});

afterEach(function (): void {
    Livewire::flushState();
});

function rxSwitch(int $site, ?bool $on): void
{
    app(Tenancy::class)->enter($site, fn () => app(OverrideSiteFeature::class)->handle(RedirectRules::EXTERNAL_FEATURE, $on));
}

function rxIn(Closure $step, int $site = 1): mixed
{
    return app(Tenancy::class)->enter($site, $step);
}

/** A row of $site written past the screen, as an older version or a direct write could have left it. */
function rxStore(int $site, string $from, string $to): void
{
    DB::connection('pgsql_admin')->table('redirects')->insert([
        'site_id' => $site, 'locale' => 'vi', 'from_path' => $from, 'to_path' => $to, 'status_code' => 301,
        'source' => 'manual', 'ulid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<string> */
function rxTargets(int $site): array
{
    return DB::connection('pgsql_admin')->table('redirects')->where('site_id', $site)->orderBy('id')->pluck('to_path')->all();
}

/** @return list<array{action: string, actor: int|null, site: int|null, meta: array<string, mixed>}> */
function rxAudit(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('action', 'like', 'cms.redirect.%')->orderBy('created_at')->orderBy('id')->get()
        ->map(fn (object $r): array => [
            'action' => (string) $r->action, 'actor' => $r->actor_id === null ? null : (int) $r->actor_id,
            'site' => $r->site_id === null ? null : (int) $r->site_id, 'meta' => (array) json_decode((string) $r->meta, true),
        ])->all();
}

function rxSuperAdmin(): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

function rxAdd(User $user, string $from, string $to): mixed
{
    return Livewire::actingAs($user)->test(Redirects::class)->call('openAdd')
        ->set('edFrom', $from)->set('edTo', $to)->call('save');
}

it('is off for every website until a super_admin turns it on, and no plan can carry it', function (): void {
    expect(FeatureRegistry::default(RedirectRules::EXTERNAL_FEATURE))->toBeFalse()
        ->and(FeatureRegistry::onPlans())->not->toHaveKey(RedirectRules::EXTERNAL_FEATURE)
        ->and(rxIn(fn (): bool => app(RedirectRules::class)->externalOn()))->toBeFalse();

    // a plan cannot be given it, and a stray plan row does not turn it on
    $plan = Plan::query()->create(['key' => 'pro-x', 'name' => 'Pro X', 'is_active' => true]);
    expect(fn () => app(SetPlanFeatures::class)->handle($plan, [RedirectRules::EXTERNAL_FEATURE => true]))
        ->toThrow(FeatureChangeRejectedException::class);
    DB::connection('pgsql_admin')->table('plan_features')->insert(['plan_id' => $plan->id, 'feature_key' => RedirectRules::EXTERNAL_FEATURE, 'value' => 'true']);
    DB::connection('pgsql_admin')->table('tenants')->where('id', Site::query()->findOrFail(1)->tenant_id)->update(['plan_id' => $plan->id]);
    expect(rxIn(fn (): bool => app(Entitlement::class)->featureEnabled(RedirectRules::EXTERNAL_FEATURE)))->toBeFalse()
        ->and(rxIn(function (): bool {
            [$sql, $bindings] = app(Entitlement::class)->featureValueExpression(RedirectRules::EXTERNAL_FEATURE);

            return app(Entitlement::class)->valueFrom(DB::selectOne("select {$sql} as v", $bindings)->v, RedirectRules::EXTERNAL_FEATURE) === true;
        }))->toBeFalse();

    // platform staff who may grant features are not enough: only a super_admin
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->givePermissionTo('platform.entitlements.manage'));
    app(PlatformAccess::class)->forgetMemo();
    $site = Site::query()->findOrFail(1);
    expect(fn () => app(SiteFeatures::class)->override($staff, $site, RedirectRules::EXTERNAL_FEATURE, true))->toThrow(AuthorizationException::class)
        ->and(rxIn(fn (): bool => app(RedirectRules::class)->externalOn()))->toBeFalse();

    app(SiteFeatures::class)->override(rxSuperAdmin(), $site, RedirectRules::EXTERNAL_FEATURE, true);
    expect(rxIn(fn (): bool => app(RedirectRules::class)->externalOn()))->toBeTrue()
        ->and(rxIn(fn (): bool => app(RedirectRules::class)->externalOn(), 2))->toBeFalse();
});

it('while off, does not offer another website and refuses one however it is sent, a super_admin too', function (string $who): void {
    $user = $who === 'super_admin' ? rxSuperAdmin() : blockLibraryUser(1, 'admin');

    rxIn(function () use ($user): void {
        Livewire::actingAs($user)->test(Redirects::class)->call('openAdd')
            ->assertSee(__('admin.rd.ed_to_hint'))->assertDontSee(__('admin.rd.ed_to_hint_ext'));

        rxAdd($user, '/cu', 'https://doitac.vn/trang')->assertHasErrors(['edTo' => __('admin.rd.err_to_site')]);

        // a payload that skips the dialog: the properties set straight, then save
        Livewire::actingAs($user)->test(Redirects::class)->set('edOpen', true)->set('edFrom', '/cu')
            ->set('edTo', 'https://evil.example/x')->call('save')->assertHasErrors('edTo');
    });

    expect(rxTargets(1))->toBe([])->and(rxAudit())->toBe([]);
})->with(['admin', 'super_admin']);

it('while on, lets a settings manager add one, stores it exactly, shows its host, follows it and audits it', function (): void {
    rxSwitch(1, true);
    seedHomepage(1);
    $admin = blockLibraryUser(1, 'admin');

    rxIn(function () use ($admin): void {
        Livewire::actingAs($admin)->test(Redirects::class)->call('openAdd')->assertSee(__('admin.rd.ed_to_hint_ext'));
        rxAdd($admin, '/khuyen-mai', ' HTTPS://DoiTac.vn/Trang?ma=A1#gia ')->assertHasNoErrors()
            ->assertSee(__('admin.rd.to_external', ['host' => 'doitac.vn']))->assertDontSee(__('admin.rd.chip_external_off'));
    });

    expect(rxTargets(1))->toBe(['https://doitac.vn/Trang?ma=A1#gia']);
    $this->get('http://a.test/khuyen-mai')->assertStatus(301)->assertRedirect('https://doitac.vn/Trang?ma=A1#gia');

    expect(rxAudit())->toHaveCount(1)
        ->and(rxAudit()[0])->toMatchArray(['action' => RedirectBook::AUDIT_ADDED, 'actor' => $admin->id, 'site' => 1])
        ->and(rxAudit()[0]['meta'])->toMatchArray(['name' => '/khuyen-mai', 'from' => '/khuyen-mai', 'to' => 'https://doitac.vn/Trang?ma=A1#gia', 'value' => 'https://doitac.vn/Trang?ma=A1#gia']);

    // a page of this site stays an ordinary redirect, with no audit row
    rxIn(fn () => rxAdd($admin, '/cu', '/moi')->assertHasNoErrors());
    expect(rxAudit())->toHaveCount(1);
});

it('while on, refuses an address that is not plainly another website over https', function (string $to, string $error): void {
    rxSwitch(1, true);
    $admin = blockLibraryUser(1, 'admin');

    $said = rxIn(fn (): string => (string) rxAdd($admin, '/cu', $to)->assertHasErrors('edTo')->errors()->first('edTo'));

    expect($said)->toBe(__($error, ['max' => RedirectBook::PATH_MAX]));

    expect(rxTargets(1))->toBe([]);
})->with([
    'http' => ['http://doitac.vn/', 'admin.rd.err_to_external'],
    'protocol-relative' => ['//doitac.vn/', 'admin.rd.err_to_external'],
    'user name' => ['https://tencongty.vn@evil.example/', 'admin.rd.err_to_external'],
    'backslash' => ['https://evil.example\\@tencongty.vn/', 'admin.rd.err_to_external'],
    'punycode' => ['https://xn--80ak6aa92e.com/', 'admin.rd.err_to_external'],
    'look-alike letter' => ['https://аpple.com/', 'admin.rd.err_to_external'],
    'IP' => ['https://10.0.0.1/', 'admin.rd.err_to_external'],
    'port' => ['https://doitac.vn:8443/', 'admin.rd.err_to_external'],
    'javascript' => ['javascript:alert(1)', 'admin.rd.err_to_external'],
    'too long' => ['https://doitac.vn/'.str_repeat('a', 260), 'admin.rd.err_to_long'],
]);

it('refuses a member who does not manage settings, even with the switch on and a forged call', function (string $role): void {
    rxSwitch(1, true);
    $member = blockLibraryUser(1, $role);

    rxIn(function () use ($member): void {
        Livewire::actingAs($member)->test(Redirects::class)->assertSee('Bạn chưa có quyền sửa cài đặt')
            ->set('edFrom', '/cu')->assertForbidden();
        Livewire::actingAs($member)->test(Redirects::class)->call('save')->assertForbidden();
    });

    expect(rxTargets(1))->toBe([])->and(rxAudit())->toBe([]);
})->with(['editor', 'author', 'viewer']);

it('ignores the rows to another website on the storefront once the switch is off, without an error', function (): void {
    seedHomepage(1);
    rxSwitch(1, true);
    $admin = blockLibraryUser(1, 'admin');
    rxIn(fn () => rxAdd($admin, '/khuyen-mai', 'https://doitac.vn/trang')->assertHasNoErrors());
    $this->get('http://a.test/khuyen-mai')->assertStatus(301)->assertRedirect('https://doitac.vn/trang');

    rxSwitch(1, false);
    $this->get('http://a.test/khuyen-mai')->assertNotFound();
    rxIn(fn () => Livewire::actingAs($admin)->test(Redirects::class)
        ->assertSee(__('admin.rd.to_external', ['host' => 'doitac.vn']))->assertSee(__('admin.rd.chip_external_off')));

    // cleared (back to the default): still off
    rxSwitch(1, null);
    $this->get('http://a.test/khuyen-mai')->assertNotFound();
});

it('never follows a stored address that is not exactly the checked form, even with the switch on', function (string $to): void {
    seedHomepage(1);
    rxSwitch(1, true);
    rxStore(1, '/la', $to);

    $this->get('http://a.test/la')->assertNotFound();
})->with([
    'http' => ['http://doitac.vn/'],
    'upper-case host' => ['https://DoiTac.vn/'],
    'user name' => ['https://a.vn@evil.example/'],
    'protocol-relative' => ['//evil.example/'],
    'backslash' => ['/\\evil.example'],
    'punycode' => ['https://xn--80ak6aa92e.com/'],
]);

it('refuses an old address the sign-in, admin or system routes use, read from the routes', function (string $from): void {
    rxSwitch(1, true);
    $admin = blockLibraryUser(1, 'admin');

    rxIn(fn () => rxAdd($admin, $from, 'https://doitac.vn/')->assertHasErrors(['edFrom' => __('admin.rd.err_from_system')]));
    rxIn(fn () => rxAdd($admin, $from, '/moi')->assertHasErrors(['edFrom' => __('admin.rd.err_from_system')]));

    expect(rxTargets(1))->toBe([]);
})->with(['/admin', '/admin/login', '/Admin/khong-co', '/login', '/admin/forgot-password', '/two-factor/setup', '/oauth/callback', '/livewire/update', '/storage/a.png']);

it('takes the reserved addresses from the routes a website answers, not the platform console’s', function (): void {
    $segments = app(RedirectRules::class)->reservedSegments();

    expect($segments)->toContain('admin', 'login', 'livewire', 'oauth', 'two-factor', 'storage')
        ->not->toContain('plans', 'tenants', 'sites', 'support', 'audit');

    // a website may still send its own old addresses that only the console uses elsewhere
    $admin = blockLibraryUser(1, 'admin');
    rxIn(fn () => rxAdd($admin, '/support', '/lien-he')->assertHasNoErrors());
    expect(rxTargets(1))->toBe(['/lien-he']);
});

it('does not follow a stored row under a sign-in or admin address', function (): void {
    seedHomepage(1);
    rxSwitch(1, true);
    rxStore(1, '/admin/khong-co', 'https://evil.example/login');
    rxStore(1, '/two-factor/khong-co', '/moi');

    $response = $this->get('http://a.test/admin/khong-co');
    expect((string) $response->headers->get('Location'))->not->toContain('evil.example')
        ->and($response->status())->not->toBeIn([301, 302]);
    $this->get('http://a.test/two-factor/khong-co')->assertNotFound();
});

it('audits every change to a row to another website: edit, kind, trash, restore and delete for good', function (): void {
    rxSwitch(1, true);
    $admin = blockLibraryUser(1, 'admin');

    rxIn(function () use ($admin): void {
        rxAdd($admin, '/km', 'https://doitac.vn/a')->assertHasNoErrors();
        $ulid = (string) Redirect::query()->value('ulid');
        Livewire::actingAs($admin)->test(Redirects::class)->call('openEdit', $ulid)->set('edTo', 'https://doitac.vn/b')->call('save')->assertHasNoErrors()
            ->call('switchCode', $ulid)
            ->call('trashOne', $ulid)
            ->call('undoTrash')
            ->call('trashOne', $ulid)
            ->set('tab', 'trash')->call('restoreOne', $ulid)
            ->call('trashOne', $ulid)
            ->call('askPurge', $ulid)->call('purgeNow');
    });

    $log = rxAudit();
    expect(array_column($log, 'action'))->toBe([
        RedirectBook::AUDIT_ADDED, RedirectBook::AUDIT_CHANGED, RedirectBook::AUDIT_CHANGED,
        RedirectBook::AUDIT_TRASHED, RedirectBook::AUDIT_RESTORED, RedirectBook::AUDIT_TRASHED,
        RedirectBook::AUDIT_RESTORED, RedirectBook::AUDIT_TRASHED, RedirectBook::AUDIT_PURGED,
    ])
        ->and(collect($log)->every(fn (array $r): bool => $r['actor'] === $admin->id && $r['site'] === 1 && $r['meta']['from'] === '/km'))->toBeTrue()
        ->and($log[1]['meta'])->toMatchArray(['was' => 'https://doitac.vn/a', 'value' => 'https://doitac.vn/b'])
        ->and($log[2]['meta'])->toMatchArray(['to' => 'https://doitac.vn/b', 'status_code' => 302])
        ->and($log[8]['meta'])->toMatchArray(['was' => 'https://doitac.vn/b'])
        ->and(rxTargets(1))->toBe([]);

    // turning a row to another website into a page of this site is audited too
    rxIn(function () use ($admin): void {
        rxAdd($admin, '/km2', 'https://doitac.vn/c')->assertHasNoErrors();
        $ulid = (string) Redirect::query()->where('from_path', '/km2')->value('ulid');
        Livewire::actingAs($admin)->test(Redirects::class)->call('openEdit', $ulid)->set('edTo', '/lien-he')->call('save')->assertHasNoErrors();
    });
    expect(collect(rxAudit())->last())->toMatchArray(['action' => RedirectBook::AUDIT_CHANGED])
        ->and(collect(rxAudit())->last()['meta'])->toMatchArray(['was' => 'https://doitac.vn/c', 'value' => '/lien-he']);
});

it('never lets the system change or remove a member\'s redirect to another website when content moves', function (): void {
    rxStore(1, '/gioi-thieu', 'https://doitac.vn/a');
    rxStore(1, '/moi', 'https://doitac.vn/b');
    // a second one at /moi, in the Trash
    DB::connection('pgsql_admin')->table('redirects')->insert([
        'site_id' => 1, 'locale' => 'vi', 'from_path' => '/moi', 'to_path' => 'https://doitac.vn/c', 'status_code' => 301,
        'source' => 'manual', 'ulid' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => now(),
    ]);

    rxIn(function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);
        // the page held /gioi-thieu over the member's row; it now moves to /moi, which another member's row holds
        app(RouteSync::class)->sync($entry, 'vi', 'gioi-thieu');
        app(RouteSync::class)->sync($entry, 'vi', 'moi');
    });

    expect(rxTargets(1))->toBe(['https://doitac.vn/a', 'https://doitac.vn/b', 'https://doitac.vn/c'])
        ->and(DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->where('source', 'auto')->count())->toBe(0)
        ->and(rxAudit())->toBe([]);
});

it('forgets the content a system row led to once its destination is edited, not on a kind switch', function (): void {
    rxSwitch(1, true);
    $admin = blockLibraryUser(1, 'admin');

    $ulid = rxIn(function (): string {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($entry, 'vi', 'lien-he');
        app(RouteSync::class)->sync($entry, 'vi', 'lien-he-ngay');

        return (string) Redirect::query()->where('from_path', '/lien-he')->value('ulid');
    });
    $row = fn (): object => DB::connection('pgsql_admin')->table('redirects')->where('ulid', $ulid)->first(['to_path', 'source', 'entry_id', 'status_code']);
    expect($row()->source)->toBe('auto')->and($row()->entry_id)->not->toBeNull();

    rxIn(fn () => Livewire::actingAs($admin)->test(Redirects::class)->call('switchCode', $ulid)->assertHasNoErrors());
    expect($row()->source)->toBe('auto')->and($row()->entry_id)->not->toBeNull()->and((int) $row()->status_code)->toBe(302);

    rxIn(fn () => Livewire::actingAs($admin)->test(Redirects::class)->call('openEdit', $ulid)->set('edTo', 'https://doitac.vn/lien-he')->call('save')->assertHasNoErrors());
    expect($row())->toMatchArray(['to_path' => 'https://doitac.vn/lien-he', 'source' => 'auto', 'entry_id' => null]);
});

it('audits each redirect to another website the nightly purge deletes for good, as the system', function (): void {
    rxStore(1, '/km', 'https://doitac.vn/a');
    rxStore(1, '/cu', '/moi');
    rxStore(2, '/km', 'https://doitac.vn/b');
    DB::connection('pgsql_admin')->table('redirects')->update(['deleted_at' => now()->subDays(Redirect::TRASH_DAYS + 1)]);

    $this->artisan('redirects:purge-trash')->expectsOutputToContain('Purged 3 redirect(s)')->assertSuccessful();

    $log = collect(rxAudit())->sortBy('site')->values()->all();
    expect(array_column($log, 'action'))->toBe([Redirect::AUDIT_EXTERNAL_PURGED, Redirect::AUDIT_EXTERNAL_PURGED])
        ->and(array_column($log, 'site'))->toBe([1, 2])
        ->and(array_column($log, 'actor'))->toBe([null, null])
        ->and($log[0]['meta'])->toMatchArray(['from' => '/km', 'was' => 'https://doitac.vn/a'])
        ->and($log[1]['meta'])->toMatchArray(['from' => '/km', 'was' => 'https://doitac.vn/b'])
        ->and(DB::connection('pgsql_admin')->table('audit_logs')->where('action', Redirect::AUDIT_EXTERNAL_PURGED)->pluck('actor_label')->unique()->all())->toBe(['system'])
        ->and(DB::connection('pgsql_admin')->table('redirects')->count())->toBe(0);
});

it('keeps each website to its own switch and its own rows', function (): void {
    seedHomepage(1);
    seedHomepage(2);
    rxSwitch(1, true);
    $adminB = blockLibraryUser(2, 'admin');

    rxIn(fn () => rxAdd($adminB, '/cu', 'https://doitac.vn/')->assertHasErrors(['edTo' => __('admin.rd.err_to_site')]), 2);
    rxStore(2, '/khuyen-mai', 'https://doitac.vn/');
    rxStore(1, '/khuyen-mai', 'https://doitac.vn/a');

    $this->get('http://b.test/khuyen-mai')->assertNotFound();
    $this->get('http://a.test/khuyen-mai')->assertStatus(301)->assertRedirect('https://doitac.vn/a');
    expect(rxTargets(2))->toBe(['https://doitac.vn/']);

    // site B's admin never sees nor reaches site A's row
    $ulidA = (string) DB::connection('pgsql_admin')->table('redirects')->where('site_id', 1)->value('ulid');
    rxIn(fn () => Livewire::actingAs($adminB)->test(Redirects::class)->assertDontSee('https://doitac.vn/a')
        ->call('trashOne', $ulidA)->assertSet('toast', __('admin.rd.gone')), 2);
    expect(rxTargets(1))->toBe(['https://doitac.vn/a']);
});

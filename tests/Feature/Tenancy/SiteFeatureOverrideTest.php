<?php

use App\Modules\Admin\Support\SiteFeatures;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Actions\OverrideSiteFeature;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Exceptions\FeatureChangeRejectedException;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\Site;
use App\Modules\Tenancy\Models\SiteFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * A feature granted to ONE website on top of (or below) its customer's plan (design.md §15.2):
 * the platform sets it, the website cannot. One write path checks the feature exists, the value is
 * of its kind, and the feature is one a single website can hold; clearing the override hands the
 * website back to its plan. Every change on the console names who made it.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();   // tenant 1 owns sites 1 (a.test) + 2 (b.test)
    DB::connection('pgsql_admin')->statement('truncate users, audit_logs restart identity cascade');
    DB::connection('pgsql_admin')->statement('delete from plans');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    Cache::flush();
});

function sfStaff(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user;
}

/** What site $siteId resolves $key to, and the override row it holds for it (null = none). */
function sfState(int $siteId, string $key): array
{
    return app(Tenancy::class)->enter($siteId, fn (): array => [
        app(Entitlement::class)->featureValue($key),
        SiteFeature::query()->firstWhere('feature_key', $key)?->value,
    ]);
}

it('sets an override, replaces it, and clearing it hands the website back to its plan', function (): void {
    $plan = Plan::query()->create(['key' => 'pro', 'name' => 'Pro']);
    PlanFeature::query()->create(['plan_id' => $plan->id, 'feature_key' => 'locales.max', 'value' => 2]);
    DB::table('tenants')->where('id', 1)->update(['plan_id' => $plan->id]);
    $override = fn (bool|int|null $value) => app(Tenancy::class)->enter(1, fn () => app(OverrideSiteFeature::class)->handle('locales.max', $value));

    $override(5);
    expect(sfState(1, 'locales.max'))->toBe([5, 5]);

    $override(3);
    expect(sfState(1, 'locales.max'))->toBe([3, 3]);

    $override(null);
    expect(sfState(1, 'locales.max'))->toBe([2, null]);

    $override(null);   // clearing twice is not an error
    expect(sfState(1, 'locales.max'))->toBe([2, null]);
});

it('keeps an override on its own website', function (): void {
    app(Tenancy::class)->enter(1, fn () => app(OverrideSiteFeature::class)->handle('rbac.custom_roles', true));

    expect(sfState(1, 'rbac.custom_roles'))->toBe([true, true])
        ->and(sfState(2, 'rbac.custom_roles'))->toBe([false, null]);
});

it('refuses a feature that does not exist, a value of the wrong kind, and a customer-wide feature', function (string $key, bool|int $value, string $reason): void {
    $attempt = fn () => app(Tenancy::class)->enter(1, fn () => app(OverrideSiteFeature::class)->handle($key, $value));

    expect($attempt)->toThrow(fn (FeatureChangeRejectedException $e) => expect($e->reason)->toBe($reason));
    expect(app(Tenancy::class)->enter(1, fn (): int => SiteFeature::query()->count()))->toBe(0);
})->with([
    'unknown feature' => ['nope.nope', true, 'unknown_feature'],
    'a number for a switch' => ['rbac.custom_roles', 3, 'wrong_kind'],
    'a switch for a limit' => ['locales.max', true, 'wrong_kind'],
    'a negative limit' => ['locales.max', -1, 'wrong_kind'],
    // How many websites a customer may have is the customer's, not one website's.
    'customer-wide feature' => ['sites.max', 5, 'customer_wide'],
]);

it('lets platform staff who manage websites set and clear an override, and names them in the trail', function (): void {
    $staff = sfStaff('super_admin');
    $site = Site::query()->findOrFail(1);

    app(SiteFeatures::class)->override($staff, $site, 'locales.max', 4);
    expect(sfState(1, 'locales.max'))->toBe([4, 4]);

    app(SiteFeatures::class)->clear($staff, $site, 'locales.max');
    expect(sfState(1, 'locales.max'))->toBe([1, null]);

    $trail = AuditLog::query()->where('site_id', 1)->orderBy('id')->get();
    expect($trail->pluck('action')->all())->toBe(['tenancy.site.feature_overridden', 'tenancy.site.feature_override_cleared'])
        ->and($trail[0]->actor_id)->toBe($staff->id)
        ->and($trail[0]->meta)->toMatchArray(['feature' => 'locales.max', 'value' => 4, 'was' => 1])
        ->and($trail[1]->meta)->toMatchArray(['feature' => 'locales.max', 'was' => 4]);
});

it('asks for the permission to grant features — managing websites is not enough', function (): void {
    $grant = function (string $permission): User {
        $user = User::factory()->create();
        app(PlatformAccess::class)->onPlatform(fn () => $user->givePermissionTo($permission));
        app(PlatformAccess::class)->forgetMemo();

        return $user;
    };
    $site = Site::query()->findOrFail(1);

    expect(fn () => app(SiteFeatures::class)->override($grant('platform.sites.manage'), $site, 'locales.max', 4))->toThrow(AuthorizationException::class);
    expect(sfState(1, 'locales.max'))->toBe([1, null]);

    app(SiteFeatures::class)->override($grant('platform.entitlements.manage'), $site, 'locales.max', 4);
    expect(sfState(1, 'locales.max'))->toBe([4, 4]);
});

it('refuses staff who may not grant features, and anyone who is not staff', function (string $who): void {
    $actor = $who === 'member' ? User::factory()->create() : sfStaff($who);
    $site = Site::query()->findOrFail(1);

    expect(fn () => app(SiteFeatures::class)->override($actor, $site, 'locales.max', 4))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SiteFeatures::class)->clear($actor, $site, 'locales.max'))->toThrow(AuthorizationException::class);
    expect(sfState(1, 'locales.max'))->toBe([1, null])
        ->and(AuditLog::query()->count())->toBe(0);
})->with(['billing', 'support', 'member']);

it('lets the operator set, list and clear a website\'s own value from the console', function (): void {
    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'rbac.custom_roles', 'value' => 'on'])->assertSuccessful();
    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'custom_domains.max', 'value' => '3'])->assertSuccessful();
    expect(sfState(1, 'rbac.custom_roles'))->toBe([true, true])
        ->and(sfState(1, 'custom_domains.max'))->toBe([3, 3])
        ->and(sfState(2, 'custom_domains.max'))->toBe([1, null]);

    $this->artisan('site:feature', ['host' => 'a.test', 'feature' => 'custom_domains.max', '--clear' => true])->assertSuccessful();
    expect(sfState(1, 'custom_domains.max'))->toBe([1, null]);

    expect(AuditLog::query()->where('site_id', 1)->orderBy('id')->pluck('action')->all())->toBe([
        'provisioning.site.feature_overridden', 'provisioning.site.feature_overridden', 'provisioning.site.feature_override_cleared',
    ]);
});

it('refuses a console change it cannot make, in words the operator can act on', function (array $arguments, string $says): void {
    $this->artisan('site:feature', $arguments)->expectsOutputToContain($says)->assertFailed();

    expect(app(Tenancy::class)->enter(1, fn (): int => SiteFeature::query()->count()))->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
})->with([
    'unknown host' => [['host' => 'nowhere.test', 'feature' => 'locales.max', 'value' => '2'], 'No site found'],
    'unknown feature' => [['host' => 'a.test', 'feature' => 'nope.nope', 'value' => 'on'], 'unknown_feature'],
    'customer-wide feature' => [['host' => 'a.test', 'feature' => 'sites.max', 'value' => '5'], 'customer_wide'],
    'a number for a switch' => [['host' => 'a.test', 'feature' => 'rbac.custom_roles', 'value' => '3'], 'wrong_kind'],
    'not a value' => [['host' => 'a.test', 'feature' => 'locales.max', 'value' => 'many'], 'is not a value'],
    'neither a value nor --clear' => [['host' => 'a.test', 'feature' => 'locales.max'], 'one of the two'],
    'both a value and --clear' => [['host' => 'a.test', 'feature' => 'locales.max', 'value' => '2', '--clear' => true], 'one of the two'],
]);

it('leaves no trail when the change is refused', function (): void {
    $staff = sfStaff('super_admin');

    expect(fn () => app(SiteFeatures::class)->override($staff, Site::query()->findOrFail(1), 'sites.max', 9))
        ->toThrow(FeatureChangeRejectedException::class);
    expect(AuditLog::query()->count())->toBe(0);
});

<?php

use App\Modules\Admin\Support\PlatformSettings;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Dom\HTMLDocument;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The settings menu marks a section that still needs setting up with a small orange dot
 * («Chưa cấu hình»): Email while no mail can leave (no sender of the site's own and none from
 * the platform), Tích hợp & Theo dõi while no service is switched on with a valid code.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, platform_settings restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** @return array<string, mixed> a sender the mailer can use */
function samSender(): array
{
    return ['method' => 'smtp_password', 'smtp' => ['host' => 'smtp.example.test', 'username' => 'u', 'password' => 'stored-secret']];
}

/** @return list<string> the keys of the menu items that carry the dot, read from the menu's links */
function samDots(string $html): array
{
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $keys = [];
    foreach ($dom->querySelectorAll('nav [data-set-dot]') as $dot) {
        $keys[] = (string) $dot->closest('a')?->getAttribute('data-set-item');
    }

    return $keys;
}

function samOwner(): User
{
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($owner, 'owner', ['settings.manage', 'entries.view']));

    return $owner->fresh();
}

it('marks Email and Tích hợp on a site with nothing set up, and says it in words', function (): void {
    $html = (string) $this->actingAs(samOwner())->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect(samDots($html))->toBe(['integrations', 'email'])
        ->and($html)->toContain('Chưa cấu hình');
});

it('clears the dots once mail can leave and a service is on', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('email', 'config', samSender());
        app(SettingsRepository::class)->put('storefront', 'verification', ['google' => ['on' => true, 'token' => str_repeat('a', 43)]]);
    });

    $html = (string) $this->actingAs(samOwner())->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect(samDots($html))->toBe([]);
});

it('counts the platform sender as set up for a site that has none of its own', function (): void {
    app(PlatformSettings::class)->put('email', 'config', samSender());

    $html = (string) $this->actingAs(samOwner())->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect(samDots($html))->toBe(['integrations']);
});

it('keeps the dot for a service switched on without a valid code, or a sender without a password', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        app(SettingsRepository::class)->put('email', 'config', ['method' => 'smtp_password', 'smtp' => ['host' => 'smtp.example.test', 'username' => 'u']]);
        app(SettingsRepository::class)->put('storefront', 'verification', ['google' => ['on' => true, 'token' => '']]);
    });

    $html = (string) $this->actingAs(samOwner())->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect(samDots($html))->toBe(['integrations', 'email']);
});

it('treats a stored value that is not a setting (a bare word or number) as nothing set up', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'email', 'key' => 'config', 'value' => 'oops']);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'verification', 'value' => 7]);
    });

    $html = (string) $this->actingAs(samOwner())->get('http://a.test/admin/settings/general')->assertOk()->getContent();

    expect(samDots($html))->toBe(['integrations', 'email']);
});

it('reads nothing for the dots when the menu shows neither Email nor Tích hợp', function (): void {
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'editor', ['entries.view']));
    $reads = 0;
    DB::listen(function (QueryExecuted $q) use (&$reads): void {
        if (str_contains($q->sql, 'site_settings') && in_array('verification', $q->bindings, true)) {
            $reads++;
        }
    });

    $html = (string) $this->actingAs($member->fresh())->get('http://a.test/admin/account')->assertOk()->getContent();

    expect(samDots($html))->toBe([])
        ->and($html)->not->toContain('data-set-item="email"')
        ->and($reads)->toBe(0);
});

it('marks «Email hệ thống» on the platform console until the platform has a sender', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $before = (string) $this->actingAs($operator->fresh())->get("http://{$host}/settings/email")->assertOk()->getContent();
    app(PlatformSettings::class)->put('email', 'config', samSender());
    $after = (string) $this->actingAs($operator->fresh())->get("http://{$host}/settings/email")->assertOk()->getContent();

    expect(samDots($before))->toBe(['system-email'])
        ->and(samDots($after))->toBe([]);
});

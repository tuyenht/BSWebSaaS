<?php

use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Admin\Livewire\GeneralSettings;
use App\Modules\Admin\Support\FirstHints;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The shared settings frame: the section's title row with its (i) note, and the first-time
 * explanations a member puts away with "Đã hiểu" — remembered per member on the server
 * (users.preferences.hints) through one validated endpoint, then shown inside (i). Also the marker
 * the phone CSS uses to hide a save bar with nothing to save.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    // The endpoint's auth / allow-list / storage, not CSRF (the button sends X-CSRF-TOKEN from the meta tag).
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function hintAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

function hintTwoLanguages(): void
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        ['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

it('remembers a dismissed hint for that member and that key only', function (): void {
    $user = hintAdmin();
    $user->preferences = ['theme' => ['scheme' => 'dark']];
    $user->save();
    $other = hintAdmin();

    $this->actingAs($user->fresh())
        ->postJson('http://a.test/admin/hint-preference', ['key' => FirstHints::LANG_SOURCE])
        ->assertOk()
        ->assertJsonPath('dismissed', FirstHints::LANG_SOURCE);

    // the other keys of the column stay as they were
    expect($user->fresh()->preferences)->toEqualCanonicalizing(['theme' => ['scheme' => 'dark'], 'hints' => [FirstHints::LANG_SOURCE => true]])
        ->and(FirstHints::dismissed(FirstHints::LANG_SOURCE, $user->fresh()))->toBeTrue()
        ->and(FirstHints::dismissed(FirstHints::LANG_SOURCE, $other->fresh()))->toBeFalse()
        ->and($other->fresh()->preferences)->toBe([]);
});

it('refuses a key that is not on the list', function (string|array|null $key): void {
    $user = hintAdmin();

    $this->actingAs($user)
        ->postJson('http://a.test/admin/hint-preference', ['key' => $key])
        ->assertStatus(422)
        ->assertJsonValidationErrors('key');

    expect($user->fresh()->preferences)->toBe([]);
})->with([
    'unknown' => 'anything',
    'a path' => 'theme.scheme',
    'a list' => [['lang-src']],
    'missing' => null,
]);

it('refuses a guest', function (): void {
    // the admin's auth middleware sends a guest to the sign-in page, fetch() or not, before anything is written
    $this->postJson('http://a.test/admin/hint-preference', ['key' => FirstHints::LANG_SOURCE])
        ->assertRedirect();
    $this->post('http://a.test/admin/hint-preference', ['key' => FirstHints::LANG_SOURCE])
        ->assertRedirect();
});

it('works on the platform console too, for the operator’s own preferences', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())
        ->postJson("http://{$host}/hint-preference", ['key' => FirstHints::LANG_SOURCE])
        ->assertOk();

    expect(FirstHints::dismissed(FirstHints::LANG_SOURCE, $operator->fresh()))->toBeTrue();
});

it('shows the section name with an (i) note holding the full description, and one short line under it', function (): void {
    $admin = hintAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(GeneralSettings::class)
            ->assertOk()
            ->assertSeeHtml('aria-controls="set-info"')
            ->assertSeeHtml('aria-expanded="false"')
            ->assertSeeHtml('id="set-info" role="note" aria-labelledby="set-info-t"')
            ->assertSeeHtml('aria-label="Thông tin về mục Thông tin chung"')
            ->assertSee('Về mục Thông tin chung')
            ->assertSee('Tên website, mô tả, múi giờ và thông tin liên hệ khách nhìn thấy.')
            ->assertSee('Tên website, múi giờ, thông tin liên hệ.')
            ->assertDontSeeHtml('data-set-learned');
    });
});

it('shows the source-language explanation with "Đã hiểu" until the member dismisses it, then inside (i)', function (): void {
    hintTwoLanguages();
    $admin = hintAdmin();
    $colleague = hintAdmin();
    $sentence = 'Đây là bản nguồn — nhập đủ và chuẩn các nhãn có chip VI.';

    app(Tenancy::class)->enter(1, function () use ($admin, $sentence): void {
        app(SiteLocale::class)->forget();
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->assertOk()
            ->assertSee($sentence)
            ->assertSeeHtml('data-hint-ok="lang-src"')
            ->assertDontSeeHtml('data-set-learned');
    });

    $this->actingAs($admin)
        ->postJson('http://a.test/admin/hint-preference', ['key' => FirstHints::LANG_SOURCE])
        ->assertOk();

    app(Tenancy::class)->enter(1, function () use ($admin, $colleague, $sentence): void {
        app(SiteLocale::class)->forget();
        $html = Livewire::actingAs($admin->fresh())->test(FloatingContactSettings::class)
            ->assertOk()
            ->assertDontSeeHtml('data-hint-ok')
            ->html();

        // the sentence now lives only in the section's (i) note
        expect(substr_count($html, $sentence))->toBe(1);
        $note = substr($html, (int) strpos($html, 'id="set-info"'));
        expect(strpos($note, 'data-set-learned'))->toBeInt()
            ->and(strpos($note, $sentence))->toBeInt();

        // a colleague on the same site still gets it in full, with the button
        Livewire::actingAs($colleague)->test(FloatingContactSettings::class)
            ->assertSee($sentence)
            ->assertSeeHtml('data-hint-ok="lang-src"')
            ->assertDontSeeHtml('data-set-learned');
    });
});

it('keeps the remaining-languages line once the explanation is dismissed (in the bar row on a phone)', function (): void {
    hintTwoLanguages();
    $admin = hintAdmin();
    $admin->preferences = ['hints' => [FirstHints::LANG_SOURCE => true]];
    $admin->save();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        app(SiteLocale::class)->forget();
        $lw = Livewire::actingAs($admin->fresh())->test(FloatingContactSettings::class);
        $phone = collect($lw->get('channels'))->search(fn (array $c): bool => $c['key'] === 'phone');
        $lw->set("channels.$phone.tips.vi", 'Gọi ngay');

        // English has no label yet: the live line stays, under the bar and (for a phone) inside it
        $html = $lw->html();
        expect(substr_count($html, 'Hiện còn 1 bản chưa đủ nhãn.'))->toBe(2)
            ->and($html)->toContain('data-lang-left');
    });
});

it('marks a settings save bar with nothing to save, so a phone can hide it', function (): void {
    $admin = hintAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(GeneralSettings::class);
        expect($lw->html())->toMatch('/data-set-savebar\s+data-clean/');

        $lw->set('form.phone', '0901 234 567');
        expect($lw->html())->toContain('data-set-savebar')->not->toMatch('/data-set-savebar\s+data-clean/');
    });
});

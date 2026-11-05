<?php

use App\Modules\Admin\Livewire\IntegrationsSettings;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Chrome\SiteVerification;
use Livewire\Attributes\Authorize;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * "Tích hợp & Theo dõi" (site_settings storefront/verification): a switch and a code per provider.
 * A code is checked when its field is left and again on save; only the code is stored, a pasted
 * meta tag is reduced to its content value, and anything else is refused with a plain message.
 * settings.manage-gated (route + boot() + the actions); every value belongs to the current site.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function intAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

/** @return array<string, mixed>|null */
function intStored(int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()
        ->where('group_name', 'storefront')->where('key', 'verification')->first()?->value);
}

const INT_GOOGLE = 'abcDEF1234567890abcdEF-_xyz';
const INT_BING = 'ABCDEF1234567890';
const INT_FACEBOOK = 'abcdef1234567890abcdef12';

it('saves each provider’s valid code and its switch', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->call('toggle', 'google')->call('toggle', 'bing')->call('toggle', 'facebook')
            ->set('tokens.google', '  '.INT_GOOGLE.'  ')
            ->set('tokens.bing', INT_BING)
            ->set('tokens.facebook', INT_FACEBOOK)
            ->assertHasNoErrors()
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Đã lưu cấu hình tích hợp');
    });

    expect(intStored())->toEqual([
        'google' => ['on' => true, 'token' => INT_GOOGLE],
        'bing' => ['on' => true, 'token' => INT_BING],
        'facebook' => ['on' => true, 'token' => INT_FACEBOOK],
    ]);
});

it('takes the content value out of a whole pasted meta tag and stores the code only', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->call('toggle', 'google')
            ->set('tokens.google', '<meta name="google-site-verification" content="'.INT_GOOGLE.'" />')
            ->assertSet('tokens.google', INT_GOOGLE)
            ->assertSet('toast', 'Đã tự nhận diện: '.INT_GOOGLE)
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(intStored()['google'])->toBe(['on' => true, 'token' => INT_GOOGLE]);
});

it('refuses a code in the wrong shape with a plain message and saves nothing', function (string $provider, string $typed): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $provider, $typed): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->call('toggle', $provider)
            ->set('tokens.'.$provider, $typed)
            ->assertHasErrors('tokens.'.$provider)
            ->assertSee('Chưa đúng định dạng — ')
            ->call('save')
            ->assertSee('Chưa lưu được: ô ')
            ->assertSet('tokens.'.$provider, SiteVerification::extract($typed));   // what was typed stays
    });

    expect(intStored())->toBeNull();
})->with([
    'google too short' => ['google', 'abc123'],
    'bing too short' => ['bing', 'ABC123'],
    'facebook with underscore' => ['facebook', 'abcdef1234567890abcdef_2'],
    'html' => ['google', '"><script>alert(1)</script>aaaaaaaaaaaaaaaaaaaa'],
    'quote' => ['bing', 'ABCDEF1234567890"'],
    'a tag whose content is not a code' => ['google', '<meta name="google-site-verification" content="short">'],
]);

it('clears a code that is emptied', function (): void {
    $admin = intAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'verification',
        'value' => ['google' => ['on' => true, 'token' => INT_GOOGLE]]]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->assertSet('tokens.google', INT_GOOGLE)->assertSet('on.google', true)
            ->set('tokens.google', '   ')
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(intStored()['google'])->toBe(['on' => true, 'token' => '']);
});

it('keeps a code only when it is well-formed, even on a provider switched off', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->set('tokens.google', 'not a code')
            ->set('tokens.bing', INT_BING)
            ->call('save')
            ->assertHasNoErrors();
    });

    expect(intStored())->toMatchArray([
        'google' => ['on' => false, 'token' => ''],
        'bing' => ['on' => false, 'token' => INT_BING],
    ]);
});

it('ignores a provider it does not know', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(IntegrationsSettings::class)->call('toggle', 'yandex')->set('tokens.yandex', INT_GOOGLE)->call('save');
        expect($lw->get('on'))->not->toHaveKey('yandex')->and($lw->get('tokens'))->not->toHaveKey('yandex');
    });

    expect(intStored())->not->toHaveKey('yandex');
});

it('shows the Vietnamese page text with its diacritics at both edges', function (): void {
    $admin = intAdmin();

    $this->actingAs($admin)->get('http://a.test/admin/settings/integrations')->assertOk()
        ->assertSee('Tích hợp & Theo dõi')->assertSee('Xác minh sở hữu để xem thứ hạng tìm kiếm.')
        ->assertSee('Chỉ chèn thẻ meta xác minh — không chạy script, không thu dữ liệu khách.', false)
        ->assertSee('Lưu cấu hình');
});

it('gates the page — settings.manage on the route, in boot() and on every action', function (): void {
    $admin = intAdmin();
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'plain-member', ['entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/integrations')->assertOk()->assertSee(__('admin.int.title'));
    $this->actingAs($member->fresh())->get('http://a.test/admin/settings/integrations')->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member->fresh())->test(IntegrationsSettings::class)->assertForbidden();
    });
    expect(intStored())->toBeNull();
});

it('reads and writes only the current site’s setting', function (): void {
    $admin = intAdmin();
    app(Tenancy::class)->enter(2, fn () => SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'verification',
        'value' => ['google' => ['on' => true, 'token' => INT_GOOGLE]]]));

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->assertSet('on.google', false)->assertSet('tokens.google', '')->assertDontSee(INT_GOOGLE)
            ->call('toggle', 'bing')->set('tokens.bing', INT_BING)->call('save');
    });

    expect(intStored(1))->toEqual(['google' => ['on' => false, 'token' => ''], 'bing' => ['on' => true, 'token' => INT_BING], 'facebook' => ['on' => false, 'token' => '']])
        ->and(intStored(2))->toBe(['google' => ['on' => true, 'token' => INT_GOOGLE]]);
});

it('shows the board’s placeholder and icons, not stand-ins', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $html = Livewire::actingAs($admin)->test(IntegrationsSettings::class)->call('toggle', 'google')->call('toggle', 'facebook')->html();
        expect($html)->toContain('placeholder="abc123… (không cần cả thẻ)"')->toContain('placeholder="abc123def456…"')
            ->and($html)->toContain('Cách lấy mã?')->toContain('Xác minh &amp; Công cụ');
    });

    $meta = file_get_contents(base_path('app/Modules/Admin/resources/views/components/icon.blade.php'));
    foreach (['ri-meta-fill', 'ri-verified-badge-line', 'ri-code-line', 'ri-questionnaire-line'] as $icon) {
        expect($meta)->toContain("'".$icon."' =>");
    }
    $view = file_get_contents(base_path('app/Modules/Admin/resources/views/livewire/integrations-settings.blade.php'));
    expect($view)->toContain('ri-meta-fill')->toContain('ri-verified-badge-line')->toContain('ri-code-line')->toContain('ri-questionnaire-line')
        ->not->toContain('ri-global')->not->toContain('ri-code-s-slash')->not->toContain("'abc123…'");
});

it('does not fail when the whole tokens array is replaced or a value is not text', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->set('tokens', ['google' => INT_GOOGLE, 'yandex' => 'x', 'bing' => ['a']])
            ->assertOk()->assertSet('tokens', ['google' => INT_GOOGLE, 'bing' => '', 'facebook' => ''])
            ->set('tokens.google', ['nested'])
            ->assertOk()->assertSet('tokens.google', '');
    });
});

it('does not let the browser set the toast or the saved signature', function (string $property, mixed $value): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $property, $value): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)->set($property, $value);
    });
})->with([
    'toast' => ['toast', 'x'],
    'toastTone' => ['toastTone', 'error'],
    'toastSeq' => ['toastSeq', 9],
    'savedSig' => ['savedSig', 'x'],
    'on' => ['on.google', true],
])->throws(CannotUpdateLockedPropertyException::class);

it('refuses toggle, save, reset and dismiss once the permission is gone after mount', function (string $action, array $args): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $action, $args): void {
        $lw = Livewire::actingAs($admin)->test(IntegrationsSettings::class);
        $admin->roles()->firstOrFail()->revokePermissionTo('settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        auth()->setUser($admin->fresh());
        $lw->call($action, ...$args)->assertForbidden();
    });
    expect(intStored())->toBeNull();
})->with([
    'toggle' => ['toggle', ['google']],
    'save' => ['save', []],
    'resetChanges' => ['resetChanges', []],
    'dismissToast' => ['dismissToast', []],
]);

it('refuses a pasted tag that belongs to another provider', function (string $provider, string $tag): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $provider, $tag): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->call('toggle', $provider)
            ->set('tokens.'.$provider, $tag)
            ->assertHasErrors('tokens.'.$provider)
            ->assertSee('Thẻ này không phải của ')
            ->call('save')
            ->assertSee('Chưa lưu được: ô ');
    });

    expect(intStored())->toBeNull();
})->with([
    'bing tag in google' => ['google', '<meta name="msvalidate.01" content="ABCDEF1234567890ABCDEF" />'],
    'google tag in facebook' => ['facebook', '<meta name="google-site-verification" content="abcdef1234567890abcdef12" />'],
]);

it('re-checks a code typed while the provider was off when it is switched on', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->set('tokens.google', '<meta name="msvalidate.01" content="ABCDEF1234567890ABCDEF" />')
            ->assertHasNoErrors()
            ->call('toggle', 'google')
            ->assertHasErrors('tokens.google')
            ->assertSee('Thẻ này không phải của ');
    });
});

it('resets a nested token key instead of failing', function (): void {
    $admin = intAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(IntegrationsSettings::class)
            ->set('tokens.yandex.x', 'v')
            ->assertOk()->assertSet('tokens', ['google' => '', 'bing' => '', 'facebook' => '']);
    });
});

it('puts settings.manage on every action, not only in boot()', function (string $method): void {
    $attrs = (new ReflectionMethod(IntegrationsSettings::class, $method))->getAttributes(Authorize::class);
    expect($attrs)->toHaveCount(1)->and($attrs[0]->getArguments()[0])->toBe('settings.manage');
})->with(['toggle', 'save', 'resetChanges', 'dismissToast']);

it('accepts a pasted tag that names its own provider', function (): void {
    expect(SiteVerification::foreign('bing', '<meta name="msvalidate.01" content="ABCDEF1234567890" />'))->toBeFalse()
        ->and(SiteVerification::foreign('bing', 'ABCDEF1234567890'))->toBeFalse()
        ->and(SiteVerification::foreign('google', '<meta name="msvalidate.01" content="x">'))->toBeTrue()
        ->and(SiteVerification::foreign('google', '<meta data-name="google-site-verification" name="msvalidate.01" content="x">'))->toBeTrue();
});

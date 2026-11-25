<?php

use App\Modules\Admin\Livewire\AdminUiSettings;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\HTMLDocument;
use Livewire\Livewire;

/*
 * "Giao diện trang quản trị" — the SITE-default tier of the admin theme (site_settings
 * group `admin_theme`, gated `settings.manage`). Only explicitly pinned axes persist;
 * '' means inherit, so saving it drops the key (per-field merge in the resolver).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('is denied without settings.manage (route + component boot)', function (): void {
    $tenancy = app(Tenancy::class);
    $member = User::factory()->create();

    $tenancy->enter(1, function () use ($member): void {
        grantRole($member, 'editor', ['entries.view']);

        Livewire::actingAs($member->fresh())->test(AdminUiSettings::class)->assertForbidden();
    });

    $this->actingAs($member->fresh())
        ->get('http://a.test/admin/settings/admin-ui')
        ->assertForbidden();
});

it('persists only the pinned axes to the admin_theme group', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())
            ->test(AdminUiSettings::class)
            ->set('scheme', 'dark')
            ->set('position', 'scrollable')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('admin-chrome-changed');

        expect(app(SettingsRepository::class)->get('admin_theme'))
            ->toBe(['scheme' => 'dark', 'position' => 'scrollable']);
    });
});

it('rejects a tampered value and stores nothing', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())
            ->test(AdminUiSettings::class)
            ->set('scheme', '"><script>alert(1)</script>')
            ->call('save')
            ->assertHasErrors(['scheme']);

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBeNull();
    });
});

it('loads the stored pins and drops a key set back to inherit', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);
        app(SettingsRepository::class)->put('admin_theme', 'config', ['scheme' => 'dark', 'topbar' => 'dark']);

        Livewire::actingAs($owner->fresh())
            ->test(AdminUiSettings::class)
            ->assertSet('scheme', 'dark')
            ->assertSet('topbar', 'dark')
            ->assertSet('sidebar', '')
            ->set('scheme', '')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBe(['topbar' => 'dark']);
    });
});

it('offers no site-wide contrast: it is each member\'s own choice', function (): void {
    expect(AdminChromeResolver::siteFields())->not->toHaveKey('contrast')->toHaveKey('text_size')
        ->and(property_exists(AdminUiSettings::class, 'contrast'))->toBeFalse();
});

it('appears in the settings nav only for settings.manage holders', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($owner, $member): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);
        grantRole($member, 'editor', ['entries.view']);
    });

    $this->actingAs($owner->fresh())->get('http://a.test/admin/settings/general')
        ->assertOk()
        ->assertSee(route('admin.settings.admin-ui'));

    $this->actingAs($member->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertDontSee(route('admin.settings.admin-ui'));
});

it('persists the new visual axes to the admin_theme group', function (): void {
    $tenancy = app(Tenancy::class);
    $owner = User::factory()->create();

    $tenancy->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())
            ->test(AdminUiSettings::class)
            ->set('layout_width', 'boxed')
            ->set('sidebar_size', 'sm')
            ->set('sidebar_image', 'img-2')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('admin_theme'))
            ->toEqualCanonicalizing([
                'layout_width' => 'boxed',
                'sidebar_size' => 'sm',
                'sidebar_image' => 'img-2',
            ]);
    });
});

it('draws the board\'s two groups as picture cards, the first card naming the default it keeps, the text size among them', function (): void {
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($owner, 'owner', ['settings.manage', 'entries.view']));

    $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/admin-ui')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $groups = array_map(static fn ($b): string => trim((string) $b->textContent), iterator_to_array($dom->querySelectorAll('[data-set-main] form h2 button')));
    $labels = array_map(static fn ($l): string => trim((string) $l->textContent), iterator_to_array($dom->querySelectorAll('[data-set-main] form h3[id^="aui-"]')));

    expect($groups)->toHaveCount(2)
        ->and($groups[0])->toContain('Bố cục')->toContain('3 mục')
        ->and($groups[1])->toContain('Màu & chữ')->toContain('5 mục')
        ->and($labels)->toBe(['Độ rộng trang', 'Khi cuộn trang', 'Cỡ thanh bên', 'Chế độ màu', 'Thanh trên cùng', 'Màu thanh bên', 'Ảnh nền thanh bên', 'Cỡ chữ'])
        ->and(preg_replace('/\s+/u', ' ', trim((string) $dom->querySelector('[aria-labelledby="aui-text_size"] input[value=""]')?->closest('label')?->textContent)))->toEndWith('Theo mặc định (Vừa)')
        ->and(preg_replace('/\s+/u', ' ', trim((string) $dom->querySelector('[aria-labelledby="aui-layout_width"] input[value=""]')?->closest('label')?->textContent)))->toBe('Theo mặc định (Giãn hết màn hình)')
        // the default card and one card per size, each a radio of the same group
        ->and($dom->querySelectorAll('[role="radiogroup"][aria-labelledby="aui-text_size"] input[type="radio"][name="pick-text_size"]')->length)->toBe(5)
        // the frame names the section; the page draws no second title of its own
        ->and($dom->querySelectorAll('h1')->length)->toBe(1)
        ->and($html)->toContain('Màu trang quản trị do nền tảng đặt chung, không đổi ở đây.');
});

it('saves a site-wide text size picked on its card', function (): void {
    $owner = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())->test(AdminUiSettings::class)
            ->set('text_size', 'xl')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBe(['text_size' => 'xl']);
    });
});

it('lets the site follow each device for light or dark, unless a member pinned their own', function (): void {
    $owner = User::factory()->create();
    $pinned = User::factory()->create(['preferences' => ['theme' => ['scheme' => 'dark']]]);
    app(Tenancy::class)->enter(1, function () use ($owner, $pinned): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);
        grantRole($pinned, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())->test(AdminUiSettings::class)
            ->set('scheme', 'auto')
            ->call('save')
            ->assertHasNoErrors()
            // the open page follows the device at once, without a reload
            ->assertDispatched('admin-chrome-changed', fn (string $name, array $params): bool => ($params['attributes']['data-scheme-auto'] ?? null) === 'true');

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBe(['scheme' => 'auto']);
        Livewire::actingAs($owner->fresh())->test(AdminUiSettings::class)->assertSet('scheme', 'auto');
    });

    $html = (string) $this->actingAs($owner->fresh())->get('http://a.test/admin/settings/admin-ui')->assertOk()->getContent();
    $tag = (string) preg_replace('/>.*/s', '>', (string) strstr($html, '<html'));
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    // the device decides; the rendered scheme stays a real one for the first paint
    expect($tag)->toContain('data-scheme-auto="true"')->toContain('data-bs-theme="light"')
        ->and($dom->querySelector('input[name="pick-scheme"][value="auto"]'))->not->toBeNull()
        ->and($dom->querySelectorAll('input[name="pick-scheme"]')->length)->toBe(4);

    $own = (string) $this->actingAs($pinned->fresh())->get('http://a.test/admin/settings/admin-ui')->assertOk()->getContent();
    $ownTag = (string) preg_replace('/>.*/s', '>', (string) strstr($own, '<html'));
    expect($ownTag)->not->toContain('data-scheme-auto')->toContain('data-bs-theme="dark"');
});

it('keeps follow-the-device a light/dark choice only: other axes refuse auto from the site tier', function (): void {
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        grantRole($owner, 'owner', ['settings.manage', 'entries.view']);

        Livewire::actingAs($owner->fresh())->test(AdminUiSettings::class)
            ->set('position', 'auto')
            ->call('save')
            ->assertHasErrors(['position']);

        expect(app(SettingsRepository::class)->get('admin_theme'))->toBeNull();
    });
});

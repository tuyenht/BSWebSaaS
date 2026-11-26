<?php

use App\Modules\Admin\Livewire\EmailSettings;
use App\Modules\Admin\Support\MailBrand;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Chữ ký & thương hiệu» on the Email page: the logo, the button colour and the signature a website
 * sets once for every mail it sends. They are kept in the website's settings (email.brand,
 * email.signature — the signature cleaned like any rich text) and drawn by the shared mail frame:
 * the logo on top, the colour on the button and the heading, the signature at the foot with the
 * website's own details filled in. Without a website the frame is the framework's own.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_settings, site_profiles, media restart identity cascade');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** @param list<string> $permissions */
function esbAdmin(array $permissions = ['settings.manage', 'media.view']): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'site-admin', $permissions));

    return $admin->fresh();
}

function esbLogo(string $slug = 'logo-an-vinh', string $mime = 'image/png'): Media
{
    return app(Tenancy::class)->enter(1, fn (): Media => Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => hash('sha256', $slug),
        'storage_key' => "s/library/{$slug}.png", 'mime' => $mime, 'size' => 1_000, 'width' => 300, 'height' => 100,
    ]));
}

/** @param array<string, mixed> $profile */
function esbIdentity(array $profile): void
{
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create($profile));
}

function esbMail(): string
{
    return (string) app(Tenancy::class)->enter(1, fn () => (new MailMessage)
        ->greeting('Xin chào')->line('Đơn hàng của bạn đã được nhận.')->action('Xem đơn', 'https://example.test/don')->render());
}

it('saves the logo, the colour and a cleaned signature, and opens with them again', function (): void {
    $logo = esbLogo();
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin, $logo): void {
        $c = Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('logoKey', $logo->storage_key)
            ->call('pickBrandColor', '#15803D')
            ->call('setSigLayout', 'top', '<p onclick="x()">Phòng <strong>Kinh doanh</strong></p><script>alert(1)</script>')
            ->assertSet('brandColor', '#15803d')
            ->call('save')
            ->assertHasNoErrors();

        $repo = app(SettingsRepository::class);
        expect($repo->get('email', 'brand'))->toEqual(['logo_media_id' => $logo->id, 'color' => '#15803d'])
            ->and($repo->get('email', 'signature')['layout'])->toBe('top')
            ->and($repo->get('email', 'signature')['html'])->toContain('<strong>Kinh doanh</strong>')
            ->not->toContain('script')->not->toContain('onclick');

        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->assertSet('logoKey', $logo->storage_key)
            ->assertSet('brandColor', '#15803d')
            ->assertSet('sigLayout', 'top');
        expect($c->get('sigHtml'))->not->toBe('');
    });
});

it('saves the signature without checking the outbox, and counts its changes on the save bar', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('setSigLayout', 'text', '<p>Chỉ một dòng</p>')
            ->assertViewHas('changes', 2)
            ->call('save')
            ->assertHasNoErrors()
            ->assertViewHas('changes', 0);

        expect(app(SettingsRepository::class)->get('email', 'signature'))->toEqual(['layout' => 'text', 'html' => '<p>Chỉ một dòng</p>'])
            ->and(app(SettingsRepository::class)->get('email', 'config'))->toBeNull();
    });
});

it('stores the layout default as nothing, so the signature follows «Thông tin chung»', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('setSigLayout', 'left', MailBrand::defaultSignature('left'))
            ->assertViewHas('changes', 0)
            ->call('setSigLayout', 'top', '<p>Riêng</p>')
            ->call('resetSignature')
            ->assertSet('sigHtml', '')
            ->assertSet('toast', __('admin.em_sig_reset_done'))
            ->assertDispatched('email-signature-load')
            ->call('save');

        expect(app(SettingsRepository::class)->get('email', 'signature'))->toEqual(['layout' => 'top', 'html' => null]);
    });
});

it('refuses a colour off the palette, an unknown layout and an over-long signature', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('pickBrandColor', '#ff00ff')
            ->assertHasErrors('brandColor')
            ->assertSet('brandColor', '')
            ->set('brandColor', 'red')
            ->call('save')
            ->assertHasErrors('brandColor')
            ->assertDispatched('email-tab', tab: 'sign');

        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('sigLayout', 'side')
            ->call('save')
            ->assertHasErrors('sigLayout');

        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('sigHtml', '<p>'.str_repeat('a', MailBrand::SIGNATURE_MAX).'</p>')
            ->assertHasErrors('sigHtml')
            ->call('save')
            ->assertHasErrors(['sigHtml' => 'max']);

        expect(app(SettingsRepository::class)->get('email', 'signature'))->toBeNull();
    });
});

it('offers the website colour as a swatch and stores it as following the website', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $site = app(MailBrand::class)->siteColor();
        $c = Livewire::actingAs($admin)->test(EmailSettings::class)->call('pickBrandColor', '#9d174d');
        $c->call('pickBrandColor', $site)->assertSet('brandColor', '')->assertHasNoErrors();
    });
});

it('warns when the colour has too little contrast for small text', function (): void {
    $admin = esbAdmin();

    // The theme's own blue (#3b82f6) reads 3.7:1 under white text at best: only large text.
    expect(MailBrand::contrast('#3b82f6'))->toBeLessThan(MailBrand::AA)
        ->and(MailBrand::contrast('#2050a0'))->toBeGreaterThanOrEqual(MailBrand::AA);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $low = MailBrand::contrast(app(MailBrand::class)->siteColor()) < MailBrand::AA;
        $c = Livewire::actingAs($admin)->test(EmailSettings::class);
        $low ? $c->assertSee(__('admin.em_brand_low')) : $c->assertDontSee(__('admin.em_brand_low'));
        $c->call('pickBrandColor', '#2050a0')->assertDontSee(__('admin.em_brand_low'));
    });
});

it('refuses a logo that left the library and opens the tab that says so', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->set('logoKey', 's/library/khong-co.png')
            ->call('save')
            ->assertHasErrors('logoKey')
            ->assertSet('tab', 'sign');

        expect(app(SettingsRepository::class)->get('email', 'brand'))->toBeNull();
    });
});

it('opens on the tab in the address and draws the signature tab', function (): void {
    $admin = esbAdmin();

    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::withQueryParams(['tab' => 'sign'])->actingAs($admin)->test(EmailSettings::class)
            ->assertSet('tab', 'sign')
            ->assertSee(__('admin.em_brand_title'))
            ->assertSee(__('admin.em_sig_title'))
            ->assertSee('{site_name}')
            ->assertSeeHtml('data-bs-lazy="bsEmailSig"');

        Livewire::withQueryParams(['tab' => 'nope'])->actingAs($admin)->test(EmailSettings::class)->assertSet('tab', 'send');
    });
});

it('keeps the library closed to a member without media.view', function (): void {
    $admin = esbAdmin(['settings.manage']);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('pickLogo')
            ->assertForbidden();
    });
});

it('turns away a member who may not manage settings', function (): void {
    $member = esbAdmin(['media.view']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member)->test(EmailSettings::class)->assertForbidden();
    });
});

it('draws the logo, the colour and the filled signature into every mail', function (): void {
    $logo = esbLogo();
    esbIdentity(['brand_name' => 'Cơ khí <An Vinh>', 'hotline' => '0909 123 456', 'email' => 'lienhe@anvinh.test']);
    app(Tenancy::class)->enter(1, function () use ($logo): void {
        app(SettingsRepository::class)->put('email', 'brand', ['logo_media_id' => $logo->id, 'color' => '#15803d']);
        app(SettingsRepository::class)->put('email', 'signature', ['layout' => 'left', 'html' => null]);
    });

    $html = esbMail();

    expect($html)->toContain('s/library/logo-an-vinh')
        ->toContain('background-color: #15803d')
        ->toContain('color: #15803d')
        ->toContain('0909 123 456 · lienhe@anvinh.test')
        ->toContain('Cơ khí &lt;An Vinh&gt;')
        ->not->toContain('<An Vinh>')
        ->not->toContain('{address}')
        ->not->toContain('{site_tagline}');
});

it('writes the signature into the plain-text part too', function (): void {
    esbIdentity(['brand_name' => 'An Vinh', 'hotline' => '0909 123 456']);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'signature', ['layout' => 'text', 'html' => null]));

    $text = (string) app(Tenancy::class)->enter(1, fn () => app(Markdown::class)
        ->renderText('notifications::email', (new MailMessage)->line('Xin chào')->toArray()));

    expect($text)->toContain('An Vinh · 0909 123 456');
});

it('keeps the framework frame for mail sent without a website', function (): void {
    $html = (string) (new MailMessage)->greeting('Hello')->line('Body')->action('Open', 'https://example.test')->render();

    expect($html)->toContain(config('app.name'))
        ->not->toContain('#fafbfc');
});

it('fills a variable into a link only as a checked address, never as an unchecked scheme', function (): void {
    esbIdentity(['brand_name' => 'javascript:alert(1)', 'email' => 'lienhe@anvinh.test']);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('email', 'signature', [
        'layout' => 'text',
        'html' => '<p><a href="{site_name}">Xem</a> <a href="{contact_email}">Thư</a> <img src="{site_name}" alt="x"></p>',
    ]));

    $html = esbMail();

    expect($html)->not->toMatch('/(?:href|src)="javascript:/i')
        ->toContain('href="mailto:lienhe@anvinh.test"');
});

it('takes a layout with an over-long signature as shown, and refuses it on save', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(EmailSettings::class)
            ->call('setSigLayout', 'top', '<p>'.str_repeat('a', MailBrand::SIGNATURE_MAX).'</p>')
            ->assertSet('sigLayout', 'top')
            ->assertHasErrors('sigHtml')
            ->call('save')
            ->assertHasErrors(['sigHtml' => 'max']);

        expect(app(SettingsRepository::class)->get('email', 'signature'))->toBeNull();
    });
});

it('writes only the part that changed, so a colour from an older page keeps a newer signature', function (): void {
    $admin = esbAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $older = Livewire::actingAs($admin)->test(EmailSettings::class);
        app(SettingsRepository::class)->put('email', 'signature', ['layout' => 'top', 'html' => '<p>Mới hơn</p>']);

        $older->call('pickBrandColor', '#15803d')->call('save')->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('email', 'signature'))->toEqual(['layout' => 'top', 'html' => '<p>Mới hơn</p>'])
            ->and(app(SettingsRepository::class)->get('email', 'brand')['color'])->toBe('#15803d');
    });
});

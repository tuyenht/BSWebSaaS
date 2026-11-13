<?php

use App\Modules\Admin\Livewire\FloatingContactSettings;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Admin\Support\Settings\FloatingContactInput;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Services\MediaUrlResolver;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * A floating-contact channel's own icon picture: the browser uploads the file to the site's media
 * library through the library's own upload (magic-byte sniffing, the dangerous-file block), then the
 * page keeps the picture's ulid in the channel — only a PNG or WebP of at most 50 KB of this site's
 * library — and the storefront draws it as an <img> covering the disc in the round buttons, the bar
 * and the "Thêm" sheet. A picture removed from the library later leaves the channel's glyph.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    seedHomepage(1, 'An Vinh');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    Storage::fake('r2');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function fciAdmin(int $siteId = 1): User
{
    $admin = User::factory()->create();
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage', 'media.view', 'media.upload']));

    return $admin->fresh();
}

/** Small real pictures by kind (finfo sniffs each by its content). */
function fciBytes(string $kind): string
{
    return match ($kind) {
        'png' => (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true),
        'webp' => (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true),
        'gif' => (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true),
        'jpg' => (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true),
        // A PNG padded past 50 KB (52 000 bytes): still a PNG by its content.
        'png51' => str_pad((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true), 52_000, "\0"),
        // Exactly 50 KB (51 200 bytes), and one byte more.
        'png50k' => str_pad((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true), 51_200, "\0"),
        'png50k1' => str_pad((string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true), 51_201, "\0"),
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><script>alert(1)</script></svg>',
        default => '<?php echo "x";',
    };
}

/**
 * Upload through the library's own endpoint, as the screen's script does; the response. A real temp
 * file (not UploadedFile::fake(), which reports a type from the name) so the library sniffs the content.
 */
function fciUpload(User $admin, string $name, string $kind): TestResponse
{
    $path = (string) tempnam(sys_get_temp_dir(), 'fci');
    file_put_contents($path, fciBytes($kind));

    return test()->actingAs($admin)->post('http://a.test/admin/media/upload', ['file' => new UploadedFile($path, $name, null, null, true)], ['Accept' => 'application/json']);
}

/** A library picture of the site, made directly (no upload). */
function fciMedia(int $siteId, string $slug, string $mime = 'image/png', int $size = 2_000): Media
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug, $mime, $size): Media {
        $hash = hash('sha256', $slug.bin2hex(random_bytes(8)));

        return Media::query()->create([
            'collection' => 'library', 'slug' => $slug, 'hash' => $hash, 'storage_key' => "s/library/{$slug}-".substr($hash, 0, 12).'.png',
            'mime' => $mime, 'size' => $size, 'width' => 64, 'height' => 64,
        ]);
    });
}

/** The floating contact switched on (combo) with the call, Zalo, Messenger and email channels; $img per channel key. */
function fciSetting(int $siteId, array $img): void
{
    $channels = [];
    foreach (['phone' => '0912345678', 'zalo' => '0912345678', 'messenger' => 'mau.page', 'email' => 'lienhe@a.test', 'sms' => ''] as $key => $value) {
        $channels[] = ['key' => $key, 'on' => $value !== '', 'value' => $value, 'tips' => []] + (isset($img[$key]) ? ['img' => $img[$key]] : []);
    }
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'floating_contact'],
        ['value' => ['enabled' => true, 'layout' => 'combo', 'theme' => 'horizontal', 'position' => 'right', 'channels' => $channels]],
    ));
}

/** @return array<string, mixed>|null */
function fciStored(int $siteId = 1): ?array
{
    return app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()
        ->where('group_name', 'storefront')->where('key', 'floating_contact')->first()?->value);
}

function fciIndex(array $channels, string $key): int
{
    foreach ($channels as $i => $c) {
        if ($c['key'] === $key) {
            return $i;
        }
    }
    throw new RuntimeException("no channel $key");
}

it('draws Zalo’s speech bubble with its Z in the channel colour on the channel disc and in its icon group, and a picture replaces it', function (): void {
    $admin = fciAdmin();
    $png = fciUpload($admin, 'logo-zalo.png', 'png')->assertOk()->json('ulid');
    $bubble = 'M12 2.5C6.2 2.5 1.5 6.3 1.5 11';

    app(Tenancy::class)->enter(1, function () use ($admin, $png, $bubble): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('toggleEnabled')->set('channels.1.value', '0912345678');
        $html = (string) $lw->html();
        // Once on the disc, once in the open group's preview, each with a Z painted in the channel colour; the word is gone.
        expect(substr_count($html, $bubble))->toBeGreaterThanOrEqual(2)
            ->and($html)->toContain('style="fill: #0084ff" d="M7.7 6.6h8.6')
            ->and($html)->not->toContain('font-extrabold" aria-hidden="true">Zalo</span>');

        $lw->call('useIcon', 'zalo', $png);
        $disc = (string) $lw->html();
        expect(substr_count($disc, $bubble))->toBe(0);
    });
});

it('takes a PNG and a WebP under 50 KB uploaded to the library as channel icons, shown on the website as pictures', function (): void {
    $admin = fciAdmin();
    $png = fciUpload($admin, 'logo-zalo.png', 'png')->assertOk()->json('ulid');
    $webp = fciUpload($admin, 'logo-call.webp', 'webp')->assertOk()->json('ulid');

    app(Tenancy::class)->enter(1, function () use ($admin, $png, $webp): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('toggleEnabled')
            ->set('channels.0.value', '0912345678')->set('channels.1.value', '0912345678');
        $lw->call('useIcon', 'zalo', $png)->assertSet('toast', 'Đã dùng biểu tượng riêng')->assertSet('toastTone', 'success');
        $lw->call('useIcon', 'phone', $webp)
            ->assertSee('Đổi ảnh khác')->assertSee('Dùng mặc định')
            ->assertSee('PNG hoặc WebP · ảnh vuông · tối đa 50 KB. Ảnh riêng phủ kín nút tròn, ví dụ logo chính thức của kênh.');
        // The preview frame prints the site's own partial with the picture before anything is saved.
        expect($lw->html())->toContain('sf-fc__img');
        $lw->call('save')->assertHasNoErrors();
    });

    $stored = collect(fciStored()['channels'])->keyBy('key');
    expect($stored['zalo']['img'])->toBe($png)->and($stored['phone']['img'])->toBe($webp)
        ->and($stored['email'])->not->toHaveKey('img');

    $keys = app(Tenancy::class)->enter(1, fn () => Media::query()->whereIn('ulid', [$png, $webp])->pluck('storage_key')->all());
    $html = $this->get('http://a.test/')->assertOk()->getContent();
    foreach ($keys as $key) {
        expect($html)->toContain(e(app(MediaUrlResolver::class)->url($key)));
    }
    expect($html)->toMatch('/<img class="sf-fc__img" src="[^"]+" alt="" width="1" height="1" loading="lazy" decoding="async">/');
});

it('draws the picture in the round buttons, the bottom bar and the “Thêm” sheet alike', function (): void {
    $call = fciMedia(1, 'goi');
    $chat = fciMedia(1, 'nhan-tin', 'image/webp');
    fciSetting(1, ['phone' => $call->ulid, 'messenger' => $chat->ulid]);

    $html = $this->get('http://a.test/')->assertOk()->getContent();
    $img = static fn (Media $m): string => '<img class="sf-fc__img" src="'.e(app(MediaUrlResolver::class)->url($m->storage_key)).'" alt="" width="64" height="64" loading="lazy" decoding="async">';

    // The call: a round button (floating) and a bar cell; Messenger: a round button and a row of the sheet.
    expect(substr_count($html, $img($call)))->toBe(2)
        ->and(substr_count($html, $img($chat)))->toBe(2)
        ->and($html)->toMatch('/class="sf-fc__btn"[^>]*>'.preg_quote($img($call), '/').'/')
        ->and($html)->toMatch('/class="sf-fc-bar__link"[^>]*><span class="sf-fc-bar__dot"[^>]*>'.preg_quote($img($call), '/').'/')
        ->and($html)->toMatch('/class="sf-fc-sheet__row"[^>]*><span class="sf-fc-bar__dot"[^>]*>'.preg_quote($img($chat), '/').'/');
});

it('refuses a picture larger than 50 KB with the plain message', function (): void {
    $admin = fciAdmin();
    $big = fciUpload($admin, 'to.png', 'png51')->assertOk()->json('ulid');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('useIcon', 'zalo', $big)
        ->assertSet('toast', 'Ảnh lớn hơn 50 KB — hãy nén ảnh')->assertSet('toastTone', 'error')
        ->assertSet('channels.1.img', ''));
});

it('takes a picture of exactly 50 KB and refuses one byte more, on the server', function (): void {
    $admin = fciAdmin();
    $edge = fciUpload($admin, 'vua-du.png', 'png50k')->assertOk()->json('ulid');
    $over = fciUpload($admin, 'qua-1-byte.png', 'png50k1')->assertOk()->json('ulid');
    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->whereIn('ulid', [$edge, $over])->orderBy('size')->pluck('size')->all()))->toBe([51_200, 51_201]);

    app(Tenancy::class)->enter(1, function () use ($admin, $edge, $over): void {
        Livewire::actingAs($admin)->test(FloatingContactSettings::class)
            ->call('useIcon', 'zalo', $edge)->assertSet('toastTone', 'success')->assertSet('channels.1.img', $edge)
            ->call('useIcon', 'phone', $over)->assertSet('toast', 'Ảnh lớn hơn 50 KB — hãy nén ảnh')->assertSet('toastTone', 'error')
            ->assertSet('channels.0.img', '');
    });
});

it('refuses a picture that sits in the library’s trash', function (): void {
    $admin = fciAdmin();
    $pic = fciMedia(1, 'trong-thung-rac');
    app(Tenancy::class)->enter(1, fn () => $pic->delete());

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('useIcon', 'zalo', $pic->ulid)
        ->assertSet('toast', 'Ảnh biểu tượng không còn trong thư viện ảnh của website — hãy tải ảnh lại hoặc bấm “Dùng mặc định”.')
        ->assertSet('toastTone', 'error')->assertSet('channels.1.img', ''));
});

it('prints no picture on the website when the setting names another site’s picture', function (): void {
    $foreign = fciMedia(2, 'cua-site-khac');
    fciSetting(1, ['phone' => $foreign->ulid]);

    $html = $this->get('http://a.test/')->assertOk()->getContent();
    expect($html)->toContain('class="sf-fc__icon"')->not->toContain('sf-fc__img')
        ->not->toContain(e(app(MediaUrlResolver::class)->url($foreign->storage_key)));
});

it('lists a channel’s icon picture as used in the library, under the floating contact screen, and site B sees nothing', function (): void {
    $admin = fciAdmin();
    $pic = fciMedia(1, 'logo-zalo');
    $loose = fciMedia(1, 'chua-dung');
    $otherPic = fciMedia(2, 'logo-site-b');
    fciSetting(1, ['zalo' => $pic->ulid]);
    fciSetting(2, ['zalo' => $otherPic->ulid]);   // written directly: site B never saved through the screen

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('save')->assertHasNoErrors());

    $read = static fn (int $siteId, Media $media): array => app(Tenancy::class)->enter($siteId, function () use ($media): array {
        $locator = app(MediaUsageLocator::class);
        $used = Media::query();
        $locator->constrain($used, true);
        $unused = Media::query();
        $locator->constrain($unused, false);

        return [$used->orderBy('id')->pluck('id')->all(), $unused->orderBy('id')->pluck('id')->all(), $locator->usagesFor($media, 'vi'), Mediable::query()->count()];
    });

    [$used, $unused, $usages] = $read(1, $pic);
    expect($used)->toBe([$pic->id])->and($unused)->toBe([$loose->id])
        ->and($usages)->toBe([['kind' => 'site', 'role' => 'content', 'label' => 'Nút liên hệ nổi', 'url' => route('admin.settings.floating-contact')]]);

    // Site B's rows are its own: its picture was never saved through the screen, so nothing marks it.
    [$used, $unused, $usages, $rows] = $read(2, $otherPic);
    expect($used)->toBe([])->and($unused)->toBe([$otherPic->id])->and($usages)->toBe([])->and($rows)->toBe(0);
});

it('drops the library’s “in use” row when the icon is replaced, cleared or the setting removed', function (): void {
    $admin = fciAdmin();
    $first = fciMedia(1, 'logo-1');
    $second = fciMedia(1, 'logo-2', 'image/webp');
    fciSetting(1, ['zalo' => $first->ulid]);
    // Read inside the site's context (a nested Tenancy::enter would end it for the rest of the closure).
    $rows = static fn (): array => Mediable::query()
        ->where('collection', FloatingContactInput::ICON_COLLECTION)->where('mediable_type', 'site_setting')->orderBy('media_id')->pluck('media_id')->all();

    app(Tenancy::class)->enter(1, function () use ($admin, $first, $second, $rows): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('save');
        expect($rows())->toBe([$first->id]);
        $lw->call('useIcon', 'zalo', $second->ulid)->call('useIcon', 'phone', $first->ulid)->call('save')->assertHasNoErrors();
        expect($rows())->toBe([$first->id, $second->id]);
        $lw->call('clearIcon', 'phone')->call('save');
        expect($rows())->toBe([$second->id]);
        $lw->call('clearIcon', 'zalo')->call('save');
        expect($rows())->toBe([]);
        $lw->call('useIcon', 'zalo', $second->ulid)->call('save');
        expect($rows())->toBe([$second->id]);
    });

    app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'storefront')->where('key', 'floating_contact')->first()?->delete());
    expect(app(Tenancy::class)->enter(1, $rows))->toBe([]);
});

it('opens a channel’s “Tùy chỉnh nút” group from its icon disc: the colour, the words on the button and its own icon', function (): void {
    $admin = fciAdmin();

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->call('addCustom');
        $read = static function (string $html): DOMXPath {
            $doc = new DOMDocument;
            @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);

            return new DOMXPath($doc);
        };
        $xp = $read($lw->html());

        // The disc is a button naming the channel, closed at first, that controls the group.
        $disc = $xp->query('//button[@id="fc-ico-t-zalo"]')->item(0);
        expect($disc)->not->toBeNull()
            ->and($disc->getAttribute('aria-expanded'))->toBe('false')
            ->and($disc->getAttribute('aria-controls'))->toBe('fc-ico-p-zalo')
            ->and($disc->getAttribute('aria-label'))->toBe('Tùy chỉnh nút · Zalo')
            ->and($disc->getAttribute('title'))->toBe('Tùy chỉnh nút')
            ->and($disc->getAttribute('class'))->toContain('hit-area');

        $group = $xp->query('//*[@id="fc-ico-p-zalo"]')->item(0);
        expect($group->getAttribute('role'))->toBe('group')
            ->and($group->getAttribute('aria-label'))->toBe('Tùy chỉnh nút · Zalo')
            ->and($group->hasAttribute('x-cloak'))->toBeTrue()
            ->and(preg_replace('/\s+/u', ' ', $group->textContent))->toContain(
                'Màu nút', 'Màu quá nhạt tự đậm lên để biểu tượng luôn rõ',
                'Chữ trên nút', 'Hiện khi rê chuột (máy tính) và dưới nút ở thanh dưới · để trống dùng chữ mặc định',
                'Biểu tượng', 'Tải biểu tượng riêng', 'PNG hoặc WebP · ảnh vuông · tối đa 50 KB.', 'Xong',
            );
        // The channel's default colour (chosen), seven colours, a colour of one's own; the hover label; the upload.
        expect($xp->query('.//button[@aria-pressed]', $group)->length)->toBe(8)
            ->and($xp->query('.//button[@aria-pressed="true"]/@aria-label', $group)->item(0)?->nodeValue)->toBe('Màu mặc định của kênh')
            ->and($xp->query('.//input[@type="color"][@aria-label="Chọn màu tùy chọn"]', $group)->length)->toBe(1)
            ->and($xp->query('.//input[@aria-label="Nhãn khi di chuột của Zalo"][@placeholder="Mặc định: Zalo"]', $group)->length)->toBe(1)
            ->and($xp->query('.//input[@type="file"][@accept="image/png,image/webp"]', $group)->length)->toBe(1);

        // A custom channel keeps its icon grid and the "mã Remix Icon" field in its row, outside its group.
        $own = $xp->query('//*[@id="fc-ico-p-c1"]')->item(0);
        expect($own)->not->toBeNull()
            ->and($xp->query('.//*[@role="group"][starts-with(@aria-label, "Biểu tượng của")]', $own)->length)->toBe(0)
            ->and($xp->query('//details[summary[contains(., "Dùng biểu tượng khác (mã Remix Icon)")]]//input[@placeholder="vd: ri-telegram-line"]')->length)->toBe(1);

        // A swatch sets the channel's colour; the chosen one shows pressed.
        $lw->set('channels.1.color', '#16a34a');
        $group = $read($lw->html());
        expect($group->query('//*[@id="fc-ico-p-zalo"]//button[@aria-pressed="true"]/@aria-label')->item(0)?->nodeValue)->toBe('Màu #16a34a');
    });
});

it('refuses a JPEG and a GIF as an icon, whatever the file is called', function (string $name, string $kind): void {
    $admin = fciAdmin();
    $ulid = fciUpload($admin, $name, $kind)->assertOk()->json('ulid');

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('useIcon', 'zalo', $ulid)
        ->assertSet('toast', 'Chỉ nhận ảnh PNG hoặc WebP')->assertSet('toastTone', 'error')
        ->assertSet('channels.1.img', ''));
})->with([['logo.jpg', 'jpg'], ['logo.gif', 'gif'], ['logo-doi-ten.png', 'jpg']]);

it('never lets a script renamed .png or an SVG into the library', function (string $name, string $kind): void {
    $admin = fciAdmin();
    fciUpload($admin, $name, $kind)->assertStatus(422);

    expect(app(Tenancy::class)->enter(1, fn () => Media::query()->count()))->toBe(0);
})->with([['logo.png', 'php'], ['logo.svg', 'svg'], ['logo-svg.png', 'svg']]);

it('refuses another site’s picture, when chosen and again on save', function (): void {
    $admin = fciAdmin();
    $foreign = fciMedia(2, 'cua-site-khac');

    app(Tenancy::class)->enter(1, function () use ($admin, $foreign): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $lw->call('useIcon', 'zalo', $foreign->ulid)
            ->assertSet('toast', 'Ảnh biểu tượng không còn trong thư viện ảnh của website — hãy tải ảnh lại hoặc bấm “Dùng mặc định”.')
            ->assertSet('channels.1.img', '');
        // A tampered draft naming it (or anything that is not a picture's ulid) is refused by save().
        $lw->set('channels.1.img', $foreign->ulid)->call('save')->assertHasErrors(['channels.1.img']);
        $lw->set('channels.1.img', 'https://evil.test/x.png')->call('save')->assertHasErrors(['channels.1.img']);
        $lw->set('channels.1.img', ['x'])->call('save')->assertHasErrors(['channels']);
    });

    expect(fciStored())->toBeNull();
});

it('goes back to the channel’s glyph with “Dùng mặc định”', function (): void {
    $admin = fciAdmin();
    $pic = fciMedia(1, 'zalo-logo');
    fciSetting(1, ['zalo' => $pic->ulid]);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class)->assertSee('Dùng mặc định');
        $lw->call('clearIcon', 'zalo')->assertDontSee('Dùng mặc định')->assertSee('Tải biểu tượng riêng')->call('save')->assertHasNoErrors();
    });

    expect(collect(fciStored()['channels'])->firstWhere('key', 'zalo'))->not->toHaveKey('img');
    expect($this->get('http://a.test/')->assertOk()->getContent())->not->toContain('sf-fc__img');
});

it('falls back to the glyph when the picture was removed from the library, with no broken image and no error', function (): void {
    $admin = fciAdmin();
    $pic = fciMedia(1, 'da-xoa');
    fciSetting(1, ['phone' => $pic->ulid]);
    expect($this->get('http://a.test/')->assertOk()->getContent())->toContain('sf-fc__img');

    app(Tenancy::class)->enter(1, fn () => $pic->delete());   // to the trash
    $html = $this->get('http://a.test/')->assertOk()->getContent();
    expect($html)->not->toContain('sf-fc__img')->not->toContain(e(app(MediaUrlResolver::class)->url($pic->storage_key)))->toContain('class="sf-fc__icon"');

    // The screen opens with the glyph and nothing unsaved; saving another change does not stumble on it.
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->assertSet('channels.0.img', '')->assertSee('Đã lưu')->assertDontSee('Dùng mặc định')
        ->call('setPosition', 'left')->call('save')->assertHasNoErrors());
    expect(collect(fciStored()['channels'])->firstWhere('key', 'phone'))->not->toHaveKey('img');
});

it('tells the member in plain words when the browser refused the file or the upload failed', function (string $reason, string $message): void {
    $admin = fciAdmin();

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($admin)->test(FloatingContactSettings::class)
        ->call('iconRefused', $reason)->assertSet('toast', $message)->assertSet('toastTone', 'error'));
})->with([
    ['type', 'Chỉ nhận ảnh PNG hoặc WebP'],
    ['size', 'Ảnh lớn hơn 50 KB — hãy nén ảnh'],
    ['denied', 'Tài khoản của bạn chưa được thêm ảnh vào thư viện ảnh — hãy nhờ quản trị website cấp quyền.'],
    ['rejected', 'Thư viện ảnh không nhận tệp này — hãy chọn ảnh PNG hoặc WebP khác.'],
    ['in_trash', 'Tệp này đang nằm trong Thùng rác — nhờ người có quyền xóa (Quản trị) khôi phục.'],
    ['unsupported_type', 'Chỉ nhận ảnh PNG hoặc WebP'],
    ['image_too_large', 'Ảnh lớn hơn 50 KB — hãy nén ảnh'],
    ['upload', 'Chưa tải được ảnh lên — hãy kiểm tra mạng rồi thử lại.'],
    ['<b>bất kỳ</b>', 'Chưa tải được ảnh lên — hãy kiểm tra mạng rồi thử lại.'],
]);

it('refuses the icon actions to a user who lost the settings permission', function (string $method, array $args): void {
    $admin = fciAdmin();
    $pic = fciMedia(1, 'quyen');
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'plain-member', ['media.view', 'media.upload']));

    app(Tenancy::class)->enter(1, function () use ($admin, $member, $method, $args, $pic): void {
        $lw = Livewire::actingAs($admin)->test(FloatingContactSettings::class);
        $this->actingAs($member->fresh());
        app(TeamContext::class)->forget();
        $lw->call($method, ...array_map(fn ($a) => $a === ':pic' ? $pic->ulid : $a, $args))->assertForbidden();
    });
    expect(fciStored())->toBeNull();
})->with([['useIcon', ['zalo', ':pic']], ['clearIcon', ['zalo']], ['iconRefused', ['type']]]);

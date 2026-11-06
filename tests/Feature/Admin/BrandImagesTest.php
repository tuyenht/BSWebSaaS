<?php

use App\Modules\Admin\Support\GeneralSettingsStore;
use App\Modules\Admin\Support\MediaUsageLocator;
use App\Modules\Admin\Support\Settings\ResetSettingsSection;
use App\Modules\Admin\Support\Settings\SetBrandImages;
use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsStamp;
use App\Modules\Admin\Support\SettingsRepository;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Storefront\Support\BrandImages;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * A site's brand images (logo, logo on a dark background, favicon, share image) are media ids on
 * its profile, set by media key through the site's scope. Until an image is first saved, the URL
 * the older general.brand setting holds is what the storefront shows, so no live page changes;
 * the fill command records the ids without changing a page or a "last saved" stamp. Every settings
 * and profile save records who made it; the stamp reads it; "Khôi phục mặc định" resets a section
 * and its undo payload brings it back.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    config(['media.driver' => 'cloudflare', 'media.cloudflare.base' => 'https://files.test']);
    seedHomepage(1, 'An Vinh');
});

function brandAdmin(int $siteId = 1, string $name = 'Chị Lan'): User
{
    $admin = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter($siteId, fn () => grantRole($admin, 'site-admin', ['settings.manage']));

    return $admin->fresh();
}

function brandMedia(int $siteId, string $slug, string $mime = 'image/png'): Media
{
    return app(Tenancy::class)->enter($siteId, function () use ($slug, $mime): Media {
        $hash = hash('sha256', $slug);

        return Media::query()->create([
            'collection' => 'brand', 'slug' => $slug, 'hash' => $hash,
            'storage_key' => 's/brand/'.$slug.'-'.substr($hash, 0, 12).'.png',
            'mime' => $mime, 'size' => 1_000, 'width' => 320, 'height' => 80,
        ]);
    });
}

/** @param array<string, mixed> $value */
function brandSetting(int $siteId, array $value): void
{
    app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'general', 'key' => 'brand'], ['value' => $value]));
}

/** @return array<string, mixed> */
function brandProfile(int $siteId = 1): array
{
    return (array) DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', $siteId)->first();
}

function brandHome(): string
{
    return (string) test()->get('http://a.test/')->assertOk()->getContent();
}

/**
 * The queries a call runs that name the general.brand row, in the order they ran.
 *
 * @return list<string>
 */
function brandRowQueries(Closure $call): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $call();
        $log = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $named = array_filter($log, fn (array $query): bool => str_contains($query['query'], 'site_settings') && in_array('brand', $query['bindings'], true));

    return array_values(array_map(fn (array $query): string => $query['query'], $named));
}

it('keeps every page byte for byte when the fill command records the ids of the old brand URLs', function (): void {
    $logo = brandMedia(1, 'logo');
    $about = brandMedia(1, 'about');
    brandSetting(1, [
        'name' => 'An Vinh',
        'logo' => 'https://files.test/'.$logo->storage_key,
        'favicon' => 'https://files.test/'.$logo->storage_key.'?w=64',
        'og_image' => 'https://files.test/'.$about->storage_key.'?w=1280',
        'description' => 'Cơ khí chính xác',
    ]);
    $before = brandHome();
    expect($before)->toContain('src="https://files.test/'.$logo->storage_key.'"')
        ->toContain('<link rel="icon" href="https://files.test/'.$logo->storage_key.'?w=64"')
        ->toContain('https://files.test/'.$about->storage_key.'?w=1280');

    $this->artisan('site-profiles:fill-brand-images')->expectsOutputToContain('would fill')->assertSuccessful();
    expect(brandProfile())->toBe([]);   // report only: nothing written

    $this->artisan('site-profiles:fill-brand-images --apply')->expectsOutputToContain('filled')->assertSuccessful();
    $profile = brandProfile();
    expect($profile['logo_media_id'])->toBe($logo->id)
        ->and($profile['favicon_media_id'])->toBe($logo->id)
        ->and($profile['og_image_media_id'])->toBe($about->id)
        ->and($profile['updated_at'])->toBeNull()
        ->and($profile['updated_by'])->toBeNull()
        ->and(brandHome())->toBe($before);

    // A second run finds nothing left to fill.
    $this->artisan('site-profiles:fill-brand-images --apply')->expectsOutputToContain('nothing to do')->assertSuccessful();
});

it('fills only empty columns, never moves a saved stamp, and reports a URL that names no picture of the site', function (): void {
    $mine = brandMedia(1, 'logo');
    $theirs = brandMedia(2, 'theirs');
    $admin = brandAdmin();
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create(['brand_name' => 'An Vinh']));
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->first()->update(['brand_name' => 'An Vinh 2']));
    auth()->logout();
    $stamped = brandProfile();
    brandSetting(1, ['logo' => 'https://files.test/'.$mine->storage_key, 'favicon' => 'https://files.test/'.$theirs->storage_key, 'og_image' => 'https://elsewhere.test/a.png']);

    $this->artisan('site-profiles:fill-brand-images --apply')->expectsOutputToContain('not found')->assertSuccessful();

    $profile = brandProfile();
    expect($profile['logo_media_id'])->toBe($mine->id)
        ->and($profile['favicon_media_id'])->toBeNull()
        ->and($profile['og_image_media_id'])->toBeNull()
        ->and($profile['updated_at'])->toBe($stamped['updated_at'])
        ->and($profile['updated_by'])->toBe($admin->id);
});

it('shows the saved images once the old URLs are gone, the dark logo in the footer falling back to the logo', function (): void {
    $logo = brandMedia(1, 'logo');
    $dark = brandMedia(1, 'logo-dark');
    $admin = brandAdmin();
    brandSetting(1, ['name' => 'An Vinh', 'logo' => 'https://old.test/logo.png']);
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'header', 'value' => []]);
        SiteSetting::query()->create(['group_name' => 'storefront', 'key' => 'footer', 'value' => []]);
    });

    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]));
    auth()->logout();

    $html = brandHome();
    expect($html)->not->toContain('https://old.test/logo.png')
        ->and(substr_count($html, 'src="https://files.test/'.$logo->storage_key.'"'))->toBe(2)   // header + footer
        ->and($html)->toContain('og:image" content="https://files.test/'.$logo->storage_key.'"');   // a site without a share image shares its logo

    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo_dark' => $dark->storage_key]));
    auth()->logout();
    $html = brandHome();
    expect($html)->toContain('sf-bar__logo" src="https://files.test/'.$logo->storage_key.'"')
        ->toContain('sf-footer__logo" src="https://files.test/'.$dark->storage_key.'"');

    // A picture moved to the trash is no longer shown.
    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($logo->id)->first()->delete());
    expect(brandHome())->not->toContain('sf-bar__logo');
});

it('sets an image only by a media key of the site, removes its old URL, and stamps who saved', function (): void {
    $mine = brandMedia(1, 'logo');
    $theirs = brandMedia(2, 'theirs');
    $video = brandMedia(1, 'clip', 'video/mp4');
    $admin = brandAdmin();
    brandSetting(1, ['name' => 'An Vinh', 'logo' => 'https://old.test/logo.png', 'favicon' => 'https://old.test/fav.png']);
    $this->actingAs($admin);

    foreach ([$theirs->storage_key, $video->storage_key, 'no/such/key.png'] as $key) {
        expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $key])))
            ->toThrow(SettingsChangeRejected::class, 'image_invalid: logo');
    }
    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['banner' => $mine->storage_key])))
        ->toThrow(SettingsChangeRejected::class, 'slot_invalid: banner');
    expect(brandProfile())->toBe([]);

    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $mine->storage_key]));
    $brand = app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'brand')->first());
    expect(brandProfile()['logo_media_id'])->toBe($mine->id)
        ->and(brandProfile()['updated_by'])->toBe($admin->id)
        ->and($brand->value)->toBe(['name' => 'An Vinh', 'favicon' => 'https://old.test/fav.png'])   // the favicon was not saved: it stays
        ->and($brand->updated_by)->toBe($admin->id);

    // An empty key removes the image; it stays removed (no old URL comes back).
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => '']));
    expect(brandProfile()['logo_media_id'])->toBeNull()->and(brandHome())->not->toContain('sf-bar__logo');
});

it('refuses brand image changes without the settings permission', function (): void {
    $mine = brandMedia(1, 'logo');
    $this->actingAs(User::factory()->create());

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $mine->storage_key])))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ResetSettingsSection::class)->reset('seo')))
        ->toThrow(AuthorizationException::class);
});

it('counts the four brand images as in use in the media library', function (): void {
    $logo = brandMedia(1, 'logo');
    $share = brandMedia(1, 'share');
    $loose = brandMedia(1, 'loose');
    $admin = brandAdmin();
    $this->actingAs($admin);
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key, 'og_image' => $share->storage_key]));

    [$used, $unused, $usages] = app(Tenancy::class)->enter(1, function () use ($logo): array {
        $locator = app(MediaUsageLocator::class);
        $used = Media::query();
        $locator->constrain($used, true);
        $unused = Media::query();
        $locator->constrain($unused, false);

        return [$used->orderBy('id')->pluck('id')->all(), $unused->pluck('id')->all(), $locator->usagesFor($logo, 'vi')];
    });

    expect($used)->toBe([$logo->id, $share->id])
        ->and($unused)->toBe([$loose->id])
        ->and($usages)->toHaveCount(1)
        ->and($usages[0]['role'])->toBe('brand')
        ->and($usages[0]['kind'])->toBe('site');
});

it('records who saved a setting, and the stamp names the newest save of a section', function (): void {
    $lan = brandAdmin(1, 'Chị Lan');
    $minh = brandAdmin(1, 'Anh Minh');

    $this->travelTo(now()->subHour());
    $this->actingAs($lan);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('general', 'timezone', ['tz' => 'Asia/Ho_Chi_Minh']));
    $this->travelBack();
    $this->actingAs($minh);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('general', 'date_format', ['format' => 'auto']));
    // Saving the same value again writes nothing: the stamp stays Anh Minh's.
    $this->actingAs($lan);
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('general', 'date_format', ['format' => 'auto']));

    $stamp = app(Tenancy::class)->enter(1, fn () => app(SettingsStamp::class)->forSection('general'));
    expect($stamp['by'])->toBe('Anh Minh')
        ->and(app(Tenancy::class)->enter(1, fn () => app(SettingsStamp::class)->forSection('seo')))->toBeNull()
        ->and(app(Tenancy::class)->enter(2, fn () => app(SettingsStamp::class)->forSection('general')))->toBeNull();

    // The rows a page already loaded: one users lookup, nothing else.
    $rows = app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'general')->get());
    $queries = countQueries(fn () => app(SettingsStamp::class)->of($rows));
    expect($queries)->toBe(1);

    // A save with no one signed in (a command) leaves no name.
    auth()->logout();
    $this->travel(1)->minutes();
    app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->put('general', 'timezone', ['tz' => 'UTC']));
    expect(app(Tenancy::class)->enter(1, fn () => app(SettingsStamp::class)->forSection('general'))['by'])->toBeNull();
});

it('resets a section to its defaults and brings everything back with the undo payload', function (): void {
    $logo = brandMedia(1, 'logo');
    $admin = brandAdmin();
    $this->actingAs($admin);
    brandSetting(1, ['name' => 'An Vinh', 'favicon' => 'https://old.test/fav.png']);
    app(Tenancy::class)->enter(1, function () use ($logo): void {
        app(SettingsRepository::class)->put('seo', 'config', ['indexable' => false]);
        app(SetBrandImages::class)->handle(['logo' => $logo->storage_key]);
    });

    $undoSeo = app(Tenancy::class)->enter(1, fn () => app(ResetSettingsSection::class)->reset('seo'));
    expect(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'seo')->exists()))->toBeFalse();

    $undoImages = app(Tenancy::class)->enter(1, fn () => app(ResetSettingsSection::class)->reset('brand_images'));
    expect(brandProfile()['logo_media_id'])->toBeNull()
        ->and($undoImages['profile']['logo_media_id'])->toBe($logo->storage_key)   // a media key, never the row id
        ->and(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('key', 'brand')->first()->value))->toBe(['name' => 'An Vinh'])
        ->and(brandHome())->not->toContain('https://old.test/fav.png');

    app(Tenancy::class)->enter(1, function () use ($undoSeo, $undoImages): void {
        app(ResetSettingsSection::class)->undo($undoSeo);
        app(ResetSettingsSection::class)->undo($undoImages);
    });
    expect(app(Tenancy::class)->enter(1, fn () => app(SettingsRepository::class)->get('seo')))->toBe(['indexable' => false])
        ->and(brandProfile()['logo_media_id'])->toBe($logo->id)
        ->and(brandHome())->toContain('https://old.test/fav.png');

    // A payload writes back nothing outside its section, and a media key of another site stays empty.
    $theirs = brandMedia(2, 'theirs');
    app(Tenancy::class)->enter(1, fn () => app(ResetSettingsSection::class)->undo([
        'section' => 'brand_images', 'settings' => ['email.config' => ['host' => 'evil']],
        'profile' => ['logo_media_id' => $theirs->storage_key, 'brand_name' => 'Khác'], 'translations' => [], 'brand' => [],
    ]));
    expect(brandProfile()['logo_media_id'])->toBeNull()
        ->and(brandProfile()['brand_name'])->toBeNull()
        ->and(app(Tenancy::class)->enter(1, fn () => SiteSetting::query()->where('group_name', 'email')->exists()))->toBeFalse();

    expect(fn () => app(Tenancy::class)->enter(1, fn () => app(ResetSettingsSection::class)->reset('email')))
        ->toThrow(SettingsChangeRejected::class, 'section_invalid: email');
});

it('clears an image when its picture is purged, through the foreign key', function (): void {
    $logo = brandMedia(1, 'logo');
    $this->actingAs(brandAdmin());
    app(Tenancy::class)->enter(1, fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key, 'logo_dark' => $logo->storage_key]));

    app(Tenancy::class)->enter(1, fn () => Media::query()->whereKey($logo->id)->first()->forceDelete());

    expect(brandProfile()['logo_media_id'])->toBeNull()->and(brandProfile()['logo_dark_media_id'])->toBeNull();
});

it('shows nothing for a profile image that names another site\'s picture', function (): void {
    $mine = brandMedia(1, 'logo');
    $theirs = brandMedia(2, 'theirs');
    app(Tenancy::class)->enter(1, fn () => SiteProfile::query()->create(['brand_name' => 'Ở xưởng Bảo Hà']));
    // The FK cannot tell sites apart: an id written past the settings screen may name any media row.
    DB::connection('pgsql_admin')->table('site_profiles')->where('site_id', 1)
        ->update(['logo_media_id' => $theirs->id, 'favicon_media_id' => $mine->id]);

    $images = app(Tenancy::class)->enter(1, fn () => app(BrandImages::class)->for(['media_ids' => ['logo' => $theirs->id, 'favicon' => $mine->id]]));
    expect($images['logo'])->toBeNull()
        ->and($images['logo_dark'])->toBeNull()
        ->and($images['favicon'])->toContain($mine->storage_key);

    $html = brandHome();
    expect($html)->not->toContain($theirs->storage_key)
        ->not->toContain('sf-bar__logo')
        ->toContain($mine->storage_key);
});

it('shows the old brand URLs on a page in another language too', function (): void {
    brandSetting(1, ['name' => 'Ở xưởng Bảo Hà', 'logo' => 'https://old.test/logo.png', 'favicon' => 'https://old.test/fav.png']);
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $body = fn (string $heading): array => ['blocks' => [['type' => 'hero', 'props' => ['heading' => $heading]]]];
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Ở đây có cà phê', 'slug' => 'o-day', 'status' => 'published', 'body' => $body('Ở đây có cà phê')]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => $body('About')]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/o-day', 'entry_id' => $entry->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about', 'entry_id' => $entry->id]);
    });

    expect($this->get('http://a.test/en/about')->assertOk()->getContent())
        ->toContain('src="https://old.test/logo.png"')
        ->toContain('<link rel="icon" href="https://old.test/fav.png"');
});

it('locks the brand row before reading it, in every save that writes it back', function (): void {
    $logo = brandMedia(1, 'logo');
    $this->actingAs(brandAdmin());
    brandSetting(1, ['name' => 'Ở xưởng Bảo Hà', 'logo' => 'https://old.test/logo.png', 'favicon' => 'https://old.test/fav.png']);
    // The row is written back whole: a plain read first would let a save running at the same time
    // be undone by this one. Postgres takes the row lock with the FOR UPDATE read itself.
    $locksFirst = fn (array $queries) => expect($queries)->not->toBeEmpty()
        ->and($queries[0])->toEndWith('for update');

    app(Tenancy::class)->enter(1, function () use ($logo, $locksFirst): void {
        $locksFirst(brandRowQueries(fn () => app(SetBrandImages::class)->handle(['logo' => $logo->storage_key])));

        $store = app(GeneralSettingsStore::class);
        ['i18n' => $i18n, 'shared' => $shared] = $store->load();
        $locksFirst(brandRowQueries(fn () => $store->save($i18n, $shared)));

        $undo = [];
        $locksFirst(brandRowQueries(function () use (&$undo): void {
            $undo = app(ResetSettingsSection::class)->reset('brand_images');
        }));
        expect($undo['brand'])->toBe(['favicon' => 'https://old.test/fav.png']);   // so undo writes the row back too
        $locksFirst(brandRowQueries(fn () => app(ResetSettingsSection::class)->undo($undo)));
    });
});

<?php

use App\Modules\Admin\Livewire\LanguageSettings;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Settings › Ngôn ngữ website: every platform content language is a row with «Đang soạn»
 * (editors write in it), «Khách thấy» (visitors see it) and the star (the default). Changes are a
 * draft until «Lưu»; turning a public language off, opening one that is off and changing the default
 * ask first. The default is always on and public; the plan's `locales.max` (default included)
 * locks the rest once it is reached. Writes are settings.manage-gated like every site-wide tab.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');   // the screen's words are checked in Vietnamese, the admin's own language
});

/** A member of site 1 holding one of the corporate roles with its template permissions. */
function lngMember(string $role): User
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate')[$role];
    $permissions = $template['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $template['permissions'];
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));

    return $user->fresh() ?? $user;
}

/** Site 1's languages as code → [enabled, public, default], in id order. */
function lngRows(int $site = 1): array
{
    return app(Tenancy::class)->enter($site, fn (): array => SiteLocale::query()->orderBy('id')->get()
        ->mapWithKeys(fn (SiteLocale $row): array => [$row->locale => [(bool) $row->enabled, (bool) $row->is_public, (bool) $row->is_default]])->all());
}

function lngPlan(int $max, int $site = 1): void
{
    app(Tenancy::class)->enter($site, fn () => SiteFeature::query()->updateOrCreate(['feature_key' => 'locales.max'], ['value' => $max]));
}

/** @return list<string> the actions audited on site 1, oldest first */
function lngAudits(): array
{
    return DB::connection('pgsql_admin')->table('audit_logs')->where('site_id', 1)->orderBy('id')->pluck('action')->all();
}

it('opens for the roles that manage settings and refuses the others, on the page and on every call', function (string $role, bool $allowed): void {
    $member = lngMember($role);
    lngPlan(3);

    $page = $this->actingAs($member)->get('http://a.test/admin/settings/languages');
    $allowed ? $page->assertOk()->assertSee('Ngôn ngữ website') : $page->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($member, $allowed): void {
        $screen = Livewire::actingAs($member)->test(LanguageSettings::class);
        if (! $allowed) {
            $screen->assertForbidden();

            return;
        }
        $screen->call('toggleEnabled', 'en')->call('save')->assertSet('toast', 'Đã lưu ngôn ngữ website');
    });

    expect(lngRows())->toBe($allowed ? ['vi' => [true, true, true], 'en' => [true, false, false]] : ['vi' => [true, true, true]]);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'editor' => ['editor', false],
    'author' => ['author', false],
    'contributor' => ['contributor', false],
    'viewer' => ['viewer', false],
]);

it('lists every platform content language with the saved state, the default first in the list order', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => false, 'is_default' => false]));

    $html = $this->actingAs($owner)->get('http://a.test/admin/settings/languages')->assertOk()->getContent();

    foreach (config('locales.content') as $code) {
        expect($html)->toContain('data-lng-row="'.$code.'"');
    }
    expect($html)->toContain('Gói của bạn: tối đa 3 ngôn ngữ (tính cả ngôn ngữ mặc định) · 2 đang soạn · 1 hiện cho khách.')
        ->toContain('Ngôn ngữ mặc định — khách vào tên miền gốc thấy bản này')
        ->toContain('Chỉ soạn nội bộ — khách mở /en/… không thấy')
        ->toContain('Luôn bật', 'Luôn hiện');
});

it('keeps changes as a draft until Lưu and puts them back with Bỏ thay đổi', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('toggleEnabled', 'en')
            ->assertSet('enabled', ['vi', 'en'])
            ->assertViewHas('changes', 1)
            ->call('resetChanges')
            ->assertSet('enabled', ['vi'])
            ->assertViewHas('changes', 0);
    });

    expect(lngRows())->toBe(['vi' => [true, true, true]]);
});

it('asks before showing a language that is off, then switches both on', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('togglePublic', 'ja')
            ->assertSet('asking', true)->assertSet('askKind', 'pub')
            ->assertSee('Bật 日本語 để soạn và hiện cho khách?')
            ->call('confirmAsk')
            ->assertSet('asking', false)
            ->assertSet('enabled', ['vi', 'ja'])->assertSet('public', ['vi', 'ja'])
            ->call('save');
    });

    expect(lngRows())->toBe(['vi' => [true, true, true], 'ja' => [true, true, false]])
        ->and(lngAudits())->toBe(['settings.locales.changed']);
});

it('asks before turning a public language off, keeps it on when cancelled, and keeps its translations when confirmed', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => true, 'is_default' => false]);
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $entry->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => ['blocks' => []]]);
    });

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('toggleEnabled', 'en')
            ->assertSet('askKind', 'off')->assertSet('enabled', ['vi', 'en'])
            ->assertSee('địa chỉ /en/… không mở được nữa')
            ->call('cancelAsk')
            ->assertSet('asking', false)->assertSet('enabled', ['vi', 'en'])
            ->call('toggleEnabled', 'en')->call('confirmAsk')
            ->assertSet('enabled', ['vi'])->assertSet('public', ['vi'])
            ->call('save');
    });

    expect(lngRows())->toBe(['vi' => [true, true, true], 'en' => [false, false, false]])
        ->and(DB::connection('pgsql_admin')->table('entry_translations')->where('site_id', 1)->where('locale', 'en')->count())->toBe(1);
});

it('hides a language from visitors without asking and keeps it for editors', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => true, 'is_default' => false]));

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('togglePublic', 'en')->assertSet('asking', false)->assertSet('public', ['vi'])
            ->call('save');
    });

    expect(lngRows()['en'])->toBe([true, false, false]);
});

it('never lets the default language be switched off or hidden', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('toggleEnabled', 'vi')
            ->assertSet('enabled', ['vi'])->assertSet('toast', 'Ngôn ngữ mặc định luôn bật — hãy đổi ngôn ngữ mặc định trước')
            ->call('togglePublic', 'vi')
            ->assertSet('public', ['vi'])->assertSet('toast', 'Ngôn ngữ mặc định luôn hiện cho khách')
            ->call('chooseDefault', 'vi')->assertSet('asking', false)
            ->call('toggleEnabled', 'xx')->call('chooseDefault', 'xx')   // not a language the screen offers
            ->assertSet('enabled', ['vi'])->assertSet('asking', false)
            ->assertViewHas('changes', 0);
    });
});

it('changes the default after asking: the new one is on and public, the old one stays on', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => false, 'is_default' => false]));

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('chooseDefault', 'en')
            ->assertSet('askKind', 'def')->assertSee('Đổi ngôn ngữ mặc định sang English?')
            ->call('confirmAsk')
            ->assertSet('default', 'en')->assertSet('public', ['vi', 'en'])
            ->call('save')
            ->assertSet('default', 'en')->assertViewHas('changes', 0);

        app(SiteLocaleContext::class)->forget();
        expect(app(SiteLocaleContext::class)->default())->toBe('en');
    });

    expect(lngRows())->toBe(['vi' => [true, true, false], 'en' => [true, true, true]])
        ->and(lngAudits())->toBe(['settings.locales.default_changed', 'settings.locales.changed']);
});

it('makes a language that was off the default and hides the old default in one save', function (): void {
    $owner = lngMember('owner');
    lngPlan(2);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('chooseDefault', 'en')->call('confirmAsk')
            ->call('togglePublic', 'vi')
            ->assertSet('public', ['en'])
            ->call('save')->assertSet('toast', 'Đã lưu ngôn ngữ website');
    });

    expect(lngRows())->toBe(['vi' => [true, false, false], 'en' => [true, true, true]]);
});

it('locks the languages left once the plan limit is reached and frees a place switched off in the same save', function (): void {
    $owner = lngMember('owner');
    lngPlan(2);
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => true, 'is_default' => false]));

    $html = $this->actingAs($owner)->get('http://a.test/admin/settings/languages')->assertOk()->getContent();
    expect($html)->toContain('data-lng-limit', 'Website đã dùng đủ 2 ngôn ngữ được cấp', 'Chưa bật cho website này — liên hệ hỗ trợ');

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('toggleEnabled', 'ja')
            ->assertSet('enabled', ['vi', 'en'])->assertSet('toast', 'Website đã dùng đủ 2 ngôn ngữ được cấp — liên hệ hỗ trợ để thêm')
            ->call('togglePublic', 'ja')->assertSet('asking', false)
            ->call('chooseDefault', 'ja')->assertSet('asking', false)
            // en off first frees its place for ja, all in one save
            ->call('toggleEnabled', 'en')->call('confirmAsk')
            ->call('toggleEnabled', 'ja')
            ->assertSet('enabled', ['vi', 'ja'])
            ->call('save')->assertSet('toast', 'Đã lưu ngôn ngữ website');
    });

    expect(lngRows())->toBe(['vi' => [true, true, true], 'en' => [false, false, false], 'ja' => [true, false, false]]);
});

it('frees the old default\'s place before taking one, whatever their order in the list', function (): void {
    $owner = lngMember('owner');
    lngPlan(2);
    // Saved: Japanese the default and English on; Vietnamese (listed first) off.
    app(Tenancy::class)->enter(1, function (): void {
        SiteLocale::query()->where('locale', 'vi')->update(['is_default' => false]);
        SiteLocale::query()->where('locale', 'vi')->update(['enabled' => false, 'is_public' => false]);
        SiteLocale::query()->create(['locale' => 'ja', 'enabled' => true, 'is_public' => true, 'is_default' => true]);
        SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => true, 'is_default' => false]);
    });

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('chooseDefault', 'en')->call('confirmAsk')
            ->call('toggleEnabled', 'ja')->call('confirmAsk')
            ->call('toggleEnabled', 'vi')
            ->assertSet('enabled', ['vi', 'en'])
            ->call('save')->assertSet('toast', 'Đã lưu ngôn ngữ website');
    });

    expect(lngRows())->toBe(['vi' => [true, false, false], 'ja' => [false, false, false], 'en' => [true, true, true]]);
});

it('swaps the default for a language that was off at the plan limit, the old default going off in the same save', function (): void {
    $owner = lngMember('owner');
    lngPlan(2);
    app(Tenancy::class)->enter(1, fn () => SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => true, 'is_default' => false]));

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            // en off frees a place for ja, ja becomes the default, then vi off gives en its place back
            ->call('toggleEnabled', 'en')->call('confirmAsk')
            ->call('chooseDefault', 'ja')->call('confirmAsk')
            ->call('toggleEnabled', 'vi')->call('confirmAsk')
            ->call('togglePublic', 'en')->call('confirmAsk')
            ->assertSet('default', 'ja')
            ->call('save')->assertSet('toast', 'Đã lưu ngôn ngữ website');
    });

    expect(lngRows())->toBe(['vi' => [false, false, false], 'en' => [true, true, false], 'ja' => [true, true, true]]);
});

it('records nothing when Lưu is pressed with nothing changed', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(LanguageSettings::class)->call('save')->assertSet('toast', 'Đã lưu ngôn ngữ website'));

    expect(lngAudits())->toBe([]);
});

it('shows no question when the client opens the dialog without one', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($owner)->test(LanguageSettings::class)
        ->set('asking', true)
        ->assertViewHas('askTitle', '')->assertDontSee('admin.lng.ask_'));
});

it('leaves every language as it was when the plan refuses the save', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $screen = Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->call('toggleEnabled', 'en')->call('toggleEnabled', 'ja')
            ->assertSet('enabled', ['vi', 'en', 'ja']);
        SiteFeature::query()->where('feature_key', 'locales.max')->update(['value' => 2]);   // the plan shrinks while the draft is open
        $screen->call('save')
            ->assertSet('toastTone', 'error')
            ->assertSet('toast', 'Website đã dùng đủ 2 ngôn ngữ — tắt bớt một ngôn ngữ rồi bấm Lưu lại.')
            ->assertSet('enabled', ['vi', 'en', 'ja']);   // the draft stays to fix
    });

    expect(lngRows())->toBe(['vi' => [true, true, true]])
        ->and(lngAudits())->toBe([]);
});

it('changes only the current site\'s languages', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);
    lngPlan(3, site: 2);
    app(Tenancy::class)->enter(2, fn () => SiteLocale::query()->create(['locale' => 'ja', 'enabled' => true, 'is_public' => false, 'is_default' => false]));

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner)->test(LanguageSettings::class)
            ->assertSet('enabled', ['vi'])          // site 2's Japanese is not on this screen
            ->call('chooseDefault', 'en')->call('confirmAsk')->call('save');
    });

    expect(lngRows(2))->toBe(['vi' => [true, true, true], 'ja' => [true, false, false]])
        ->and(lngRows(1))->toBe(['vi' => [true, true, false], 'en' => [true, true, true]]);

    // A member of site 1 only cannot open site 2's screen.
    $this->actingAs($owner)->get('http://b.test/admin/settings/languages')->assertForbidden();
});

it('offers a preview of a hidden language\'s home page', function (): void {
    $owner = lngMember('owner');
    lngPlan(3);
    $ulid = app(Tenancy::class)->enter(1, function (): string {
        SiteLocale::query()->create(['locale' => 'en', 'enabled' => true, 'is_public' => false, 'is_default' => false]);
        $home = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $home->translations()->create(['locale' => 'en', 'title' => 'Home', 'slug' => 'home', 'status' => 'draft', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/', 'entry_id' => $home->id]);

        return $home->ulid;
    });

    $html = $this->actingAs($owner)->get('http://a.test/admin/settings/languages')->assertOk()->getContent();

    expect($html)->toContain('/admin/content/'.$ulid.'/preview?locale=en', 'Xem trước');
});

it('renders on a flat query budget whatever the number of languages', function (): void {
    $owner = lngMember('owner');
    lngPlan(8);
    $load = fn (): int => countQueries(fn () => $this->actingAs($owner)->get('http://a.test/admin/settings/languages')->assertOk());

    $this->actingAs($owner)->get('http://a.test/admin/settings/languages')->assertOk();   // the permission and settings caches fill first
    $one = $load();

    app(Tenancy::class)->enter(1, function (): void {
        foreach (['en', 'zh', 'ja', 'ko', 'fr'] as $code) {
            SiteLocale::query()->create(['locale' => $code, 'enabled' => true, 'is_public' => false, 'is_default' => false]);
            $home = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
            $home->translations()->create(['locale' => $code, 'title' => 'Home '.$code, 'slug' => 'home-'.$code, 'status' => 'draft', 'body' => ['blocks' => []]]);
            SiteRoute::query()->create(['locale' => $code, 'path' => '/', 'entry_id' => $home->id]);
        }
    });
    $many = $load();

    expect($many)->toBeLessThanOrEqual($one + 2)   // the two preview lookups appear once hidden languages exist
        ->and($many)->toBeLessThanOrEqual(30);
});

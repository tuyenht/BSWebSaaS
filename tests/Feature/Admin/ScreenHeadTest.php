<?php

use App\Modules\Admin\Support\Guide\ScreenGuide;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\DB;

/*
 * The one screen head every admin screen draws (x-bs-admin::screen-head), as the admin shell does: the
 * trail «⌂ › … › screen», the name in the screen title size, the (?) «Hướng dẫn», the screen's "⋮", the
 * main button, an editing screen's unsaved cluster «Chưa lưu · Bỏ thay đổi · Lưu thay đổi», then one line
 * about the screen with its short phone wording and an optional link. Each screen's guide (ScreenGuide)
 * is the member's own: their role and what it lets them do there, the steps of the screen pointing at its
 * marks, and the screen's common questions, every word in the four admin languages.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
    app()->setLocale('vi');
    // the forms and leads screens open only on a plan with the contacts module
    DB::connection('pgsql_admin')->table('site_modules')->insert(['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
});

function shOwner(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS));

    return $user->fresh();
}

function shDom(string $html): HTMLDocument
{
    return HTMLDocument::createFromString('<!doctype html><body>'.$html.'</body>', LIBXML_NOERROR);
}

it('draws the trail, the name in the title size, (?), ⋮, the main button and the line with its phone wording and link', function (): void {
    $html = (string) $this->blade(<<<'BLADE'
        <x-bs-admin::screen-head title="Khách liên hệ" title-id="h1x" top :crumb="[['label' => 'Khách liên hệ']]"
            desc="Yêu cầu khách gửi từ biểu mẫu." mdesc="Tin khách gửi qua biểu mẫu."
            :desc-link="['label' => 'Biểu mẫu', 'url' => 'http://a.test/admin/form-builder']">
            <x-slot:more><button data-more>⋮</button></x-slot:more>
            <a data-main>+</a>
        </x-bs-admin::screen-head>
        BLADE);
    $dom = shDom($html);
    $head = $dom->querySelector('[data-screen-head]');
    $h1 = $head?->querySelector('h1');
    $help = $head?->querySelector('[data-title-help]');

    expect($head?->tagName)->toBe('HEADER')
        // ⌂ › screen, stepping aside below 600 on a top-level screen
        ->and($head?->querySelector('nav[data-guide="crumb"]')?->getAttribute('class'))->toContain('max-[599.98px]:hidden')
        ->and(trim((string) $head?->querySelector('nav [aria-current="page"]')?->textContent))->toBe('Khách liên hệ')
        // the name in the shell's title size
        ->and((string) $h1?->getAttribute('class'))->toContain('text-title')->toContain('leading-[1.4]')
        ->and($h1?->getAttribute('id'))->toBe('h1x')
        // (?) then ⋮ beside the name, the main button after them; the name leaves room for both on a phone
        ->and($help?->querySelector('[data-guide-open]'))->not->toBeNull()
        ->and($help?->querySelector('[data-more]'))->not->toBeNull()
        ->and($help?->nextElementSibling?->hasAttribute('data-main'))->toBeTrue()
        ->and((string) $h1?->getAttribute('class'))->toContain('max-md:max-w-[calc(100%-2*var(--spacing-hit)-1rem)]')
        // one line, its short wording on phones, and the link at its end
        ->and(trim((string) $head?->querySelector('[data-desc="full"]')?->textContent))->toBe('Yêu cầu khách gửi từ biểu mẫu.')
        ->and((string) $head?->querySelector('[data-desc="full"]')?->getAttribute('class'))->toContain('max-[599.98px]:hidden')
        ->and(trim((string) $head?->querySelector('[data-desc="short"]')?->textContent))->toBe('Tin khách gửi qua biểu mẫu.')
        ->and($head?->querySelector('[data-screen-desc] a[data-guide="desc-link"]')?->getAttribute('href'))->toBe('http://a.test/admin/form-builder');

    // no guide, no trail, no line: none of their boxes
    $bare = shDom((string) $this->blade('<x-bs-admin::screen-head title="Tổng quan" :guide="false" />'));
    expect($bare->querySelector('[data-guide-open]'))->toBeNull()
        ->and($bare->querySelector('nav'))->toBeNull()
        ->and($bare->querySelector('[data-screen-desc]'))->toBeNull()
        ->and($bare->querySelector('[data-title-act]'))->toBeNull();
});

it('draws the unsaved cluster: «Chưa lưu» while dirty, the kept link, «Bỏ thay đổi» and «Lưu thay đổi» off until a change', function (): void {
    $html = (string) $this->blade(<<<'BLADE'
        <x-bs-admin::head-save dirty="open && dirty().n > 0" busy="saveBusy" save="save()" reset="reset()" saving="Đang lưu…">
            <a data-site href="/">Xem trên website</a>
        </x-bs-admin::head-save>
        BLADE);
    $box = shDom($html)->querySelector('[data-head-save]');
    $buttons = $box === null ? [] : iterator_to_array($box->querySelectorAll('button'));

    expect($box?->querySelector('[role="status"]')?->getAttribute('x-show'))->toBe('open && dirty().n > 0')
        ->and(trim((string) $box?->querySelector('[role="status"]')?->textContent))->toBe(__('admin.head.unsaved'))
        ->and($box?->querySelector('[data-head-save-keep] [data-site]'))->not->toBeNull()
        // below 600 only the kept link stays in the head
        ->and((string) $box?->getAttribute('class'))->toContain('max-[599.98px]:[&>:not([data-head-save-keep])]:hidden')
        ->and(array_map(fn ($b): string => trim((string) $b->textContent), $buttons))->toBe([__('admin.head.discard'), __('admin.head.save')])
        ->and(array_map(fn ($b): bool => $b->hasAttribute('disabled'), $buttons))->toBe([true, true])
        ->and($buttons[0]->getAttribute('x-on:click'))->toBe('reset()')
        ->and($buttons[1]->getAttribute('x-on:click'))->toBe('save()')
        ->and((string) $buttons[1]->getAttribute('x-bind:disabled'))->toContain('!(open && dirty().n > 0)')->toContain('(saveBusy)')
        // the expressions reach Alpine as written: escaped once, never twice ("&amp;amp;" would stop the page's script)
        ->and($html)->not->toContain('&amp;amp;');
});

it('gives every screen guide its words in the four admin languages, none left as a key', function (string $locale): void {
    app()->setLocale($locale);
    $owner = shOwner();

    $guides = app(Tenancy::class)->enter(1, function () use ($owner): array {
        $all = [];
        foreach (ScreenGuide::screens() as $screen) {
            $all[$screen] = ScreenGuide::for($screen, $owner, 'X')->toArray($owner, 1);
        }
        foreach (['general', ...ScreenGuide::sections()] as $section) {
            $all['settings.'.$section] = ScreenGuide::section($section, $owner, 'X', true)->toArray($owner, 1);
        }

        return $all;
    });

    foreach ($guides as $screen => $g) {
        $words = [$g['purpose'], $g['role']['may'] ?? '', $g['role']['note'] ?? ''];
        foreach ($g['parts'] as $part) {
            $words[] = $part['title'];
            foreach ($part['steps'] as $step) {
                foreach ($step['v'] as $v) {
                    $words[] = $v['title'];
                    $words[] = $v['text'];
                }
            }
        }
        foreach ($g['faq'] as $pair) {
            $words[] = $pair['q'];
            $words[] = $pair['a'];
        }

        expect($g['parts'][0]['steps'])->not->toBeEmpty($screen)
            ->and($g['faq'])->not->toBeEmpty($screen)
            ->and($g['ui']['faq'])->toBe(trans('guide.ui.faq', [], $locale));
        foreach ($words as $w) {
            expect($w)->toBeString()->not->toBe('')->not->toStartWith('guide.', $screen.' '.$w);
        }
    }
})->with(['vi', 'en', 'ja', 'zh']);

it('tells the member what their role lets them do on the screen, and prints the guide on the first draw only', function (): void {
    $owner = shOwner();

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $this->actingAs($owner);
        $g = ScreenGuide::print('members', __('admin.members.title'));

        expect($g)->toBeArray()
            ->and($g['role']['may'])->toContain(__('guide.members.p1'))->toContain(__('guide.members.p2'))
            ->and($g['faq'][0])->toBe(['q' => __('guide.members.q1'), 'a' => __('guide.members.a1')])
            ->and(array_column($g['parts'][0]['steps'], 'id'))->toContain('add', 'edit');

        // a Livewire update keeps what the first draw printed (the guide box is wire:ignore)
        request()->headers->set('X-Livewire', '1');
        expect(ScreenGuide::print('members', 'X'))->toBeNull()
            ->and(ScreenGuide::printSection('general', 'X', true))->toBeNull();
    });
});

it('lets a role without the screen\'s permissions read what it may do there in plain words', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'viewer', []));
    $user = $user->fresh();

    $g = app(Tenancy::class)->enter(1, fn (): array => ScreenGuide::for('menus', $user, 'X')->toArray($user, 1));

    expect($g['role']['may'])->toBe(__('guide.menus.none'));
});

it('draws the shared head with «Hướng dẫn» on the website screens moved onto it', function (string $path, string $title): void {
    $html = (string) $this->actingAs(shOwner())->get('http://a.test/admin'.$path)->assertOk()->getContent();
    $head = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-screen-head]');

    expect($head)->not->toBeNull($path)
        ->and(trim((string) $head?->querySelector('h1')?->textContent))->toBe(__($title))
        ->and((string) $head?->querySelector('h1')?->getAttribute('class'))->toContain('text-title')
        ->and($head?->querySelector('[data-guide-open]'))->not->toBeNull($path)
        ->and($html)->toContain('data-guide-ctx')
        // one trail on the page, the head's own (none on the dashboard)
        ->and(HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('nav[aria-label="'.__('admin.breadcrumb').'"]'))->toHaveCount($path === '' ? 0 : 1, $path);
})->with([
    'Tổng quan' => ['', 'admin.dashboard'],
    'Trình đơn' => ['/menus', 'menus.title'],
    'Thư viện ảnh & tệp' => ['/media', 'media.lib.title'],
    'Biểu mẫu' => ['/form-builder', 'form_builder.title'],
    'Khách liên hệ' => ['/forms', 'leads.title'],
    'Thành viên' => ['/users', 'admin.members.title'],
]);

it('brings Trình đơn the unsaved cluster and its board sentence', function (): void {
    $html = (string) $this->actingAs(shOwner())->get('http://a.test/admin/menus')->assertOk()->getContent();
    $head = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-screen-head]');

    expect($head?->querySelector('[data-head-save] [data-guide="site"]'))->not->toBeNull()
        ->and($head?->querySelector('[data-head-save] [data-guide="save"]'))->not->toBeNull()
        ->and($head?->querySelector('[data-head-save] [data-guide="reset"]'))->not->toBeNull()
        ->and(trim((string) $head?->querySelector('[data-desc="full"]')?->textContent))->toBe(__('menus.ui_intro'))
        ->and(__('menus.ui_intro'))->toBe('Sắp xếp liên kết ở đầu và chân website.')
        // its Alpine expressions are escaped once: a doubled "&amp;" would stop the screen's script
        ->and($html)->not->toContain('&amp;amp;')->not->toContain('&amp;#039;');
});

it('puts each Settings section\'s own guide in its title row, and leaves a section with its own help alone', function (): void {
    $owner = shOwner();
    $general = (string) $this->actingAs($owner)->get('http://a.test/admin/settings/general')->assertOk()->getContent();
    $row = HTMLDocument::createFromString($general, LIBXML_NOERROR)->querySelector('[data-set-act]');

    expect($row?->querySelector('[data-guide-open]'))->not->toBeNull()
        ->and($general)->toContain('data-guide="set-info"')->toContain('data-guide="set-search"');

    $advanced = (string) $this->actingAs($owner)->get('http://a.test/admin/settings/advanced')->assertOk()->getContent();
    expect($advanced)->toContain('data-adv-help')->not->toContain('data-guide-open');
});

it('draws the shared head with «Hướng dẫn» on the console screens moved onto it', function (string $path, string $title): void {
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();
    $host = (string) config('tenancy.platform_host');

    $html = (string) $this->actingAs($user->fresh())->get("http://{$host}{$path}")->assertOk()->getContent();
    $head = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('[data-screen-head]');

    expect($head)->not->toBeNull($path)
        ->and(trim((string) $head?->querySelector('h1')?->textContent))->toBe(__($title))
        ->and($head?->querySelector('[data-guide-open]'))->not->toBeNull($path)
        ->and($head?->querySelector('[data-screen-desc]'))->not->toBeNull($path);
})->with([
    'Khách hàng' => ['/tenants', 'admin.platform_tenants'],
    'Website' => ['/sites', 'admin.platform_sites'],
    'Nhật ký hệ thống' => ['/audit', 'admin.platform_audit'],
    'Gói & tính năng' => ['/plans', 'admin.platform_plans'],
]);

it('leaves no screen on the retired page header', function (): void {
    expect(file_exists(base_path('app/Modules/Admin/resources/views/components/page-header.blade.php')))->toBeFalse();
});

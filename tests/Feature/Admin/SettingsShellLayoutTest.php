<?php

use App\Modules\Admin\Navigation\SettingsMenu;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\HTMLDocument;

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lists the sections in the board groups, each with its icon, and names the open one as the page title', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $html = (string) $this->actingAs($admin)->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/email')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $menu = $dom->querySelector('[data-set-shell] nav[data-set-nav]');
    $heads = array_map(static fn ($p): string => trim((string) $p->textContent), iterator_to_array($menu->querySelectorAll('[data-set-ghead]')));

    // the member's own «Bảo mật» first, then the board's groups; «Nâng cao» is the owner's, so an admin has none
    expect($heads)->toBe(['Tài khoản của tôi', 'Chung', 'Nội dung', 'Tiếp thị & Kết nối', 'Hệ thống'])
        ->and($menu->querySelector('input[type="search"]')?->getAttribute('aria-label'))->toBe('Tìm trong cài đặt')
        // the section's name alone: an Email section not set up yet also says so to screen readers
        ->and(trim((string) $menu->querySelector('a[aria-current="page"] > span.flex-1')?->textContent))->toBe(__('admin.email_settings'))
        ->and(trim((string) $menu->querySelector('a[aria-current="page"] > span.sr-only')?->textContent))->toBe('('.__('admin.set.unconfigured').')')
        ->and($menu->querySelector('a[aria-current="page"] svg'))->not->toBeNull()
        ->and($menu->querySelector('li[data-find*="Tên website"] a')?->getAttribute('href'))->toBe(route('admin.settings.general'));

    // one h1: the section's name, reading «Cài đặt» only over the phone list of sections
    $h1 = $dom->querySelectorAll('h1');
    expect($h1)->toHaveCount(1)
        ->and(trim((string) $h1->item(0)?->querySelector('span:not([data-set-root])')?->textContent))->toBe(__('admin.email_settings'))
        ->and(trim((string) $h1->item(0)?->querySelector('[data-set-root]')?->textContent))->toBe('Cài đặt')
        ->and(explode(' ', (string) $h1->item(0)?->querySelector('[data-set-root]')?->getAttribute('class')))->toContain('hidden')
        ->and($dom->querySelector('a[data-set-back]')?->getAttribute('href'))->toBe('#settings-menu');
});

it('folds the «Nâng cao» group for the owner and opens it on the section inside', function (): void {
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($owner, 'owner', ['settings.manage', 'entries.view']));

    foreach (['general' => 'false', 'advanced' => 'true'] as $section => $expanded) {
        $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/'.$section)->assertOk()->getContent();
        $button = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('nav[data-set-nav] button[aria-controls="set-g-advanced"]');

        expect(trim((string) $button?->textContent))->toBe('Nâng cao')
            ->and($button?->getAttribute('aria-expanded'))->toBe($expanded);
    }
});

it('opens /admin/settings on the first section a manager may change, as the list on phones', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/settings')->assertRedirect(route('admin.settings.general').'#settings-menu');
});

it('leads a settings section with the trail home › settings › section from 768px', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $html = (string) $this->actingAs($admin)->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/email')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $nav = $dom->querySelector('[data-set-shell] > nav[aria-label="'.__('admin.breadcrumb').'"]');

    expect($nav)->not->toBeNull()
        ->and(explode(' ', (string) $nav->getAttribute('class')))->toContain('max-md:hidden')
        ->and($nav->querySelector('a[aria-label="'.__('admin.dashboard').'"]')?->getAttribute('href'))->toBe(route('admin.dashboard'))
        ->and(trim((string) $nav->querySelector('a.underline')?->textContent))->toBe('Cài đặt')
        ->and($nav->querySelector('a.underline')?->getAttribute('href'))->toBe(route('admin.settings.general').'#settings-menu')
        ->and(trim((string) $nav->querySelector('[aria-current="page"]')?->textContent))->toBe(__('admin.email_settings'));
});

it('sends a member who changes no site-wide setting to Tên miền when theirs to read, else to their own Bảo mật', function (): void {
    expect(SettingsMenu::entryUrl(true, true))->toBe(route('admin.settings.general').'#settings-menu')
        ->and(SettingsMenu::entryUrl(false, true))->toBe(route('admin.settings.domains'))
        ->and(SettingsMenu::entryUrl(false, false))->toBe(route('admin.account', ['tab' => 'security']));
});

it('keeps the open section in the menu from the page, not from the route of a Livewire round trip', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));
    $this->actingAs($admin);

    // «Bảo mật & Nâng cao» is the owner's in the menu; an admin who opens it still sees it named
    $keys = static fn (string $active): array => app(Tenancy::class)->enter(1, static fn (): array => array_merge([], ...array_map(
        static fn (array $group): array => array_column($group['items'], 'key'),
        SettingsMenu::groups($active),
    )));

    expect($keys('advanced'))->toContain('advanced')
        ->and($keys('email'))->not->toContain('advanced');
});

it('shows the owner no approval-workflow switchboard: that one is for platform operators', function (): void {
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($owner, 'owner', ['settings.manage', 'entries.view']));

    $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/general')->assertOk()->getContent();
    $menu = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('nav[data-set-nav]');

    expect($menu?->querySelector('a[href="'.route('admin.settings.advanced').'"]'))->not->toBeNull()
        ->and($menu?->querySelector('a[href="'.route('admin.settings.workflows').'"]'))->toBeNull();
});

it('turns the open section into a same-page row: no wire:navigate, so the phone list goes back without a leave check', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $html = (string) $this->actingAs($admin)->get('http://a.test/admin/settings/email')->assertOk()->getContent();
    $menu = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('nav[data-set-nav]');
    $on = $menu?->querySelector('a[aria-current="page"]');

    // the menu is drawn once per page: a Livewire round trip leaves it (search, fold, fitted hint) alone
    expect($menu?->querySelector('[x-data="bsSetNav"]')?->hasAttribute('wire:ignore'))->toBeTrue()
        ->and($on?->hasAttribute('wire:navigate'))->toBeFalse()
        ->and($on?->getAttribute('x-on:click'))->toBe('openCurrent($event)')
        ->and($menu?->querySelector('a[href="'.route('admin.settings.general').'"]')?->hasAttribute('wire:navigate'))->toBeTrue();
});

it('puts the section in one card, framed from 768px and frameless below; «Bảo mật & Nâng cao» keeps its own cards', function (): void {
    $owner = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($owner, 'owner', ['settings.manage', 'entries.view']));
    $page = function (string $section) use ($owner): HTMLDocument {
        $html = (string) $this->actingAs($owner)->get('http://a.test/admin/settings/'.$section)->assertOk()->getContent();

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    };

    $card = $page('general')->querySelector('[data-set-shell] [data-set-main] > [data-set-card]');
    $classes = explode(' ', (string) $card?->getAttribute('class'));
    expect($card)->not->toBeNull()
        ->and($classes)->toContain('rounded-lg', 'border', 'border-border', 'bg-surface', 'shadow-card', 'p-[1.375rem]')
        ->and($classes)->toContain('max-md:border-0', 'max-md:bg-transparent', 'max-md:shadow-none', 'max-md:p-0', 'max-md:rounded-none')
        // the section's groups sit inside it, and the save bar stays outside
        ->and($card?->querySelector('[data-set-form]'))->not->toBeNull()
        ->and($card?->querySelector('[data-set-save]'))->toBeNull();

    $advanced = $page('advanced');
    expect($advanced->querySelector('[data-set-main] [data-adv]'))->not->toBeNull()
        ->and($advanced->querySelector('[data-set-card]'))->toBeNull();
});

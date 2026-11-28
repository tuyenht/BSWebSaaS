<?php

use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * AdminNav (design.md §15.2): a NESTED menu. Content sections are expandable groups of
 * {List · Add new}; feature modules are leaves; Settings is a group of tabs. Three-ring
 * gate: ModuleCatalog ∩ site entitlement ∩ RBAC. Nav hiding is UX; routes carry the real
 * `can:` gate. Fail-closed for a guest.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.platform.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

it('returns an empty menu for a guest (fail-closed)', function (): void {
    expect((new AdminNav)->items(null))->toBe([]);
});

it('renders Dashboard and Settings as leaves for the owner, Settings opening the first section', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    $byKey = collect($items)->keyBy('key');

    expect($byKey->get('dashboard')?->url)->toContain('/admin')
        ->and($byKey->get('dashboard')?->hasChildren())->toBeFalse()
        ->and($byKey->get('settings')?->icon)->toBe('ri-settings-4')
        ->and($byKey->get('settings')?->hasChildren())->toBeFalse()
        ->and($byKey->get('settings')?->url)->toBe(route('admin.settings.general').'#settings-menu');
});

it('nests each content section as a group of List + Add new for a creator', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));

    // A content section is a parent whose children include an Add-new (…​.create) leaf.
    $content = collect($items)->first(
        fn (NavItem $i): bool => $i->hasChildren()
            && collect($i->children)->contains(fn (NavItem $c): bool => str_ends_with($c->key, '.create')),
    );

    expect($content)->not->toBeNull()
        ->and($content->url)->toBeNull();

    $childKeys = array_map(fn (NavItem $c): string => $c->key, $content->children);
    expect($childKeys)->toContain("{$content->key}.list")->toContain("{$content->key}.create");
});

it('hides the Add-new child from a viewer who lacks entries.create', function (): void {
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'viewer-test', ['entries.view']));

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($viewer));

    $content = collect($items)->first(
        fn (NavItem $i): bool => $i->hasChildren()
            && collect($i->children)->contains(fn (NavItem $c): bool => str_ends_with($c->key, '.list')),
    );

    expect($content)->not->toBeNull();
    $childKeys = array_map(fn (NavItem $c): string => $c->key, $content->children);
    expect($childKeys)->toContain("{$content->key}.list")->not->toContain("{$content->key}.create");
});

it('nests categories under each content type instead of a standalone item', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));

    // The old standalone "taxonomies"/Categories top-level item is gone.
    $topKeys = array_map(fn (NavItem $i): string => $i->key, $items);
    expect($topKeys)->not->toContain('taxonomies');

    // Any Categories child is nested under a content group and section-scoped (…​.taxonomy);
    // Pages (no taxonomy) never gets one — so types never share/mix categories.
    collect($items)
        ->filter(fn (NavItem $i): bool => $i->hasChildren())
        ->flatMap(fn (NavItem $i): array => $i->children)
        ->filter(fn (NavItem $c): bool => str_ends_with($c->key, '.taxonomy'))
        ->each(function (NavItem $c): void {
            expect($c->url)->toContain('section=')
                ->and($c->key)->not->toStartWith('pages.');
        });
});

it('excludes a feature module whose nav_permission the user lacks (entitled ∩ RBAC)', function (): void {
    // A member with only media.view: media surfaces, users (needs users.manage) does not.
    $member = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($member, 'media-only', ['media.view']));

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($member));
    $keys = array_map(fn (NavItem $i): string => $i->key, $items);

    expect($keys)->toContain('media')->not->toContain('users');
});

it('offers «Nhóm quyền» only to a member who may manage roles', function (): void {
    $members = User::factory()->create();
    $roles = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($members, $roles): void {
        grantRole($members, 'members-only', ['users.manage']);
        grantRole($roles, 'roles-keeper', ['roles.manage']);
    });

    $keys = fn (User $u): array => array_map(fn (NavItem $i): string => $i->key, app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($u->fresh())));

    expect($keys($members))->toContain('users')->not->toContain('roles')
        ->and($keys($roles))->toContain('roles')->not->toContain('users');
});

it('orders each content group Add new before List (action-forward)', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));

    $posts = collect($items)->firstWhere('key', 'posts');
    $childKeys = array_map(fn (NavItem $c): string => $c->key, $posts->children);

    expect($childKeys[0])->toBe('posts.create')
        ->and($childKeys[1])->toBe('posts.list');
});

it('localizes the section label to the UI locale, falling back to the stored label', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    app()->setLocale('en');
    $en = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    expect(collect($en)->firstWhere('key', 'posts')?->label)->toBe('News');

    app()->setLocale('vi');
    $vi = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    expect(collect($vi)->firstWhere('key', 'posts')?->label)->toBe('Tin tức');
});

it('gives each content section a distinct default icon instead of one shared glyph', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    $byKey = collect($items)->keyBy('key');

    // Each type reads apart in the sidebar (Pages and News were both provisioned above).
    expect($byKey->get('pages')?->icon)->toBe('ri-file-text')
        ->and($byKey->get('posts')?->icon)->toBe('ri-newspaper');
});

it('offers the review inbox only where an approval flow is actually running', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $owner = User::query()->firstOrFail();   // provisioned owner: holds review + approve

        // Workflows are off by default, so the entry would only ever open an empty inbox.
        $keys = collect((new AdminNav)->items($owner))->pluck('key');
        expect($keys)->not->toContain('review-inbox');

        ContentSection::query()->firstOrCreate(['key' => 'posts'], ['label' => 'Tin tức', 'entry_type' => 'post']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Cache::flush();   // the count is cached for a minute

        $item = collect((new AdminNav)->items($owner))->firstWhere('key', 'review-inbox');
        expect($item)->toBeInstanceOf(NavItem::class)
            ->and($item->badge)->toBeNull();   // nothing waiting yet → no red dot

        $entry = Entry::query()->create(['type' => 'post', 'status' => 'pending', 'workflow_state' => 'pending']);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Chờ duyệt', 'slug' => 'cho-duyet', 'status' => 'pending', 'body' => ['blocks' => []]]);
        Cache::flush();

        expect(collect((new AdminNav)->items($owner))->firstWhere('key', 'review-inbox')->badge)->toBe(1);
    });
});

it('cuts the menu into the untitled top, then the content, tools and system groups in the design order', function (): void {
    app()->setLocale('vi');
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $sections = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->sections($owner));
    $byKey = collect($sections)->keyBy('key');

    expect(array_map(fn ($s): string => $s->key, $sections))->toBe(['top', 'content', 'tools', 'system'])
        ->and($byKey['top']->label)->toBeNull()
        ->and(array_map(fn (NavItem $i): string => $i->key, $byKey['top']->items))->toBe(['dashboard'])
        ->and($byKey['content']->label)->toBe(__('admin.nav_group.content'))
        ->and(array_map(fn (NavItem $i): string => $i->key, $byKey['content']->items))->toContain('pages', 'posts')
        ->and($byKey['tools']->label)->toBe(__('admin.nav_group.tools'))
        ->and(array_map(fn (NavItem $i): string => $i->key, $byKey['tools']->items))->toBe(['media', 'contacts', 'forms', 'menus', 'blocks'])
        ->and($byKey['system']->label)->toBe(__('admin.nav_group.system'))
        ->and(array_map(fn (NavItem $i): string => $i->key, $byKey['system']->items))->toBe(['users', 'roles', 'settings']);
});

it('names the menu entries with the design wording and its icons', function (): void {
    app()->setLocale('vi');
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $byKey = collect(app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner)))->keyBy('key');

    expect($byKey['dashboard']->icon)->toBe('ri-dashboard-2')
        ->and($byKey['media']->label)->toBe('Thư viện ảnh & tệp')
        ->and($byKey['media']->icon)->toBe('ri-image-2')
        ->and($byKey['users']->label)->toBe('Thành viên')
        ->and($byKey['users']->icon)->toBe('ri-team')
        ->and($byKey['roles']->label)->toBe('Nhóm quyền')
        ->and($byKey['roles']->icon)->toBe('ri-shield-user')
        ->and($byKey['roles']->url)->toBe(route('admin.roles.index'));
});

it('leaves a group out when none of its entries is visible, so no heading stands alone', function (): void {
    // Only entries.view: content and settings (Bảo mật) show, but nothing under Công cụ.
    $viewer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($viewer, 'viewer-test', ['entries.view']));

    $sections = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->sections($viewer));

    expect(array_map(fn ($s): string => $s->key, $sections))->toContain('content')->not->toContain('tools');
});

it('heads the sidebar groups in the page, each list named by its heading', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test/admin/content')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $headings = iterator_to_array($dom->querySelectorAll('#admin-sidebar nav p.sidebar-heading'));
    expect(array_map(fn (Element $p): string => trim((string) $p->textContent), $headings))->toBe([__('admin.nav_group.content'), __('admin.nav_group.tools'), __('admin.nav_group.system')])
        ->and($headings[0]->hasAttribute('data-first'))->toBeTrue()   // the collapsed rail draws no divider above the first group
        ->and($headings[1]->hasAttribute('data-first'))->toBeFalse();
    foreach ($headings as $heading) {
        $list = $heading->nextElementSibling;
        expect($list?->tagName)->toBe('UL')
            ->and($list?->getAttribute('aria-labelledby'))->toBe($heading->getAttribute('id'));
    }
});

it('draws no Tools heading for a member who may use none of its entries', function (): void {
    // entries.view only: no media.view, forms.view or menus.manage, so nothing sits under Công cụ.
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($writer, 'writer-test', ['entries.view']));

    $html = (string) $this->actingAs($writer->fresh())->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test/admin/content')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $headings = array_map(fn (Element $p): string => trim((string) $p->textContent), iterator_to_array($dom->querySelectorAll('#admin-sidebar nav p.sidebar-heading')));
    expect($headings)->toContain(__('admin.nav_group.content'))->not->toContain(__('admin.nav_group.tools'))
        ->and($dom->getElementById('admin-nav-tools'))->toBeNull()
        ->and($dom->querySelector('#admin-sidebar a[href="'.route('admin.media.index').'"]'))->toBeNull();
});

/** How many trails (navs named "Đường dẫn") the page draws. */
function shellTrailCount(string $html): int
{
    return count(HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('nav[aria-label="'.__('admin.breadcrumb').'"]'));
}

it('leads a menu page with the shell trail from the active menu entry and its child', function (): void {
    app()->setLocale('vi');
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $posts = app(Tenancy::class)->enter(1, fn () => ContentSection::query()->where('key', 'posts')->firstOrFail());
    expect($posts->taxonomy_id)->not->toBeNull();

    // a leaf: ⌂ › Thư viện ảnh & tệp
    $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test/admin/media')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $trail = $dom->querySelector('main > nav[aria-label="'.__('admin.breadcrumb').'"]');
    expect(shellTrailCount($html))->toBe(1)
        ->and($trail?->querySelector('a[aria-label="'.__('admin.dashboard').'"]')?->getAttribute('href'))->toBe(route('admin.dashboard'))
        ->and(trim((string) $trail?->querySelector('[aria-current="page"]')?->textContent))->toBe('Thư viện ảnh & tệp');

    // a child: ⌂ › Tin tức › Chuyên mục, the entry linking to its own list
    $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test/admin/taxonomies?section=posts')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $trail = $dom->querySelector('main > nav[aria-label="'.__('admin.breadcrumb').'"]');
    $label = app(Tenancy::class)->enter(1, fn (): string => $posts->localizedLabel());
    expect(shellTrailCount($html))->toBe(1)
        ->and(trim((string) $trail?->querySelector('a.underline')?->textContent))->toBe($label)
        ->and($trail?->querySelector('a.underline')?->getAttribute('href'))->toBe(route('admin.entries.index', ['section' => 'posts']))
        ->and(trim((string) $trail?->querySelector('[aria-current="page"]')?->textContent))->toBe(__('admin.categories'))
        ->and($dom->querySelectorAll('h1'))->toHaveCount(1);
});

it('draws no shell trail on the dashboard, and one trail only where the screen draws its own', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $get = fn (string $path): string => (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test'.$path)->assertOk()->getContent();

    expect(shellTrailCount($get('/admin')))->toBe(0);

    foreach (['/admin/settings/email', '/admin/content?section=posts'] as $path) {
        $dom = HTMLDocument::createFromString($get($path), LIBXML_NOERROR);
        expect($dom->querySelectorAll('nav[aria-label="'.__('admin.breadcrumb').'"]'))->toHaveCount(1, $path)
            ->and($dom->querySelector('main > nav[aria-label="'.__('admin.breadcrumb').'"]'))->toBeNull()
            ->and($dom->querySelectorAll('h1'))->toHaveCount(1);
    }
});

it('hides the shell trail on phones on a top-level screen and keeps it elsewhere', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');
    $classes = function (string $path) use ($owner): array {
        $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test'.$path)->assertOk()->getContent();
        $trail = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('main > nav[aria-label="'.__('admin.breadcrumb').'"]');
        expect($trail)->not->toBeNull($path);

        return explode(' ', (string) $trail?->getAttribute('class'));
    };

    // the board's top-level screens (lvl1 or a short main button): the way back on a phone is the menu
    foreach (['/admin/media', '/admin/users', '/admin/taxonomies?section=posts'] as $path) {
        expect($classes($path))->toContain('max-[599.98px]:hidden', '[@media(max-height:500px)]:hidden');
    }
    // the others keep it at every width
    foreach (['/admin/notifications', '/admin/roles'] as $path) {
        expect($classes($path))->not->toContain('max-[599.98px]:hidden');
    }
});

it('names a page outside the menu by its title, and the account by its own title', function (): void {
    app()->setLocale('vi');
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    foreach (['/admin/notifications' => __('admin.notifications'), '/admin/account' => __('admin.account.title')] as $path => $title) {
        $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test'.$path)->assertOk()->getContent();
        $trail = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector('main > nav[aria-label="'.__('admin.breadcrumb').'"]');
        expect(trim((string) $trail?->querySelector('[aria-current="page"]')?->textContent))->toBe($title);
    }
});

it('draws the open group on the server as Alpine will, so the sidebar does not move when Alpine starts', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $html = (string) $this->actingAs($owner)->withSession(['locale' => 'vi'])->get('http://anvinh.platform.test/admin/content?section=posts')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $nav = $dom->querySelector('#admin-sidebar nav');
    $groups = iterator_to_array($dom->querySelectorAll('#admin-sidebar nav button[aria-expanded]'));
    $open = array_values(array_filter($groups, fn (Element $b): bool => $b->getAttribute('aria-expanded') === 'true'));

    // Exactly one group is open: the current page's, named by the nav's data-active-group.
    expect($nav?->getAttribute('data-active-group'))->not->toBe('')
        ->and($open)->toHaveCount(1)
        ->and(count($groups))->toBeGreaterThan(1);

    foreach ($groups as $button) {
        $isOpen = $button === $open[0];
        $list = $button->nextElementSibling;
        $arrow = $button->querySelector('svg:last-of-type');

        expect($list?->tagName)->toBe('UL')
            ->and($list?->hasAttribute('x-cloak'))->toBe(! $isOpen)
            ->and(str_contains((string) $arrow?->getAttribute('class'), 'rotate-90'))->toBe($isOpen);
    }

    // The navigation listeners live on the element (Alpine drops them with it), not on the document.
    expect((string) $nav?->getAttribute('x-init'))->not->toContain('addEventListener')
        ->and((string) $nav?->getAttribute('x-data'))->toStartWith('bsAdminNav(')
        ->and($nav?->hasAttribute('x-on:livewire:navigated.document'))->toBeTrue()
        ->and($nav?->getAttribute('x-on:livewire:navigating.document'))->toBe('leaving()')
        ->and($nav?->getAttribute('x-on:pagehide.window'))->toBe('leaving()');
});

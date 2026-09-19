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
        tenantName: 'An Vinh', siteName: 'An Vinh', hostname: 'anvinh.test',
        ownerEmail: 'owner@anvinh.test', ownerName: 'An Vinh Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

it('returns an empty menu for a guest (fail-closed)', function (): void {
    expect((new AdminNav)->items(null))->toBe([]);
});

it('renders Dashboard as a leaf and Settings as an expandable group of tabs for the owner', function (): void {
    $owner = User::query()->firstWhere('email', 'owner@anvinh.test');

    $items = app(Tenancy::class)->enter(1, fn (): array => (new AdminNav)->items($owner));
    $byKey = collect($items)->keyBy('key');

    expect($byKey->get('dashboard')?->url)->toContain('/admin')
        ->and($byKey->get('dashboard')?->hasChildren())->toBeFalse()
        ->and($byKey->get('settings')?->icon)->toBe('settings')
        ->and($byKey->get('settings')?->hasChildren())->toBeTrue()
        ->and($byKey->get('settings')?->url)->toBeNull();

    $security = collect($byKey->get('settings')->children)->firstWhere('key', 'settings.security');
    expect($security?->url)->toContain('/admin/settings/security');
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
    expect($byKey->get('pages')?->icon)->toBe('file-text')
        ->and($byKey->get('posts')?->icon)->toBe('newspaper');
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

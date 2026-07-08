<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * P6 — nested page paths + the parent-rename cascade. A child page's URL is prefixed by its
 * parent's path (design §3a tree); renaming a parent moves every published descendant's route
 * and leaves a 301, so old deep links keep resolving. Flat (parentless) pages are unchanged.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function publishNestedPage(User $editor, string $slug, string $title, ?int $parentId = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id, 'parent_id' => $parentId]);
    $page->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => $title]]]],
    ]);
    app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

    return $page->fresh();
}

it('composes a nested page path from its parent chain', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $parent = publishNestedPage($editor, 'dich-vu', 'Dịch vụ');
        $child = publishNestedPage($editor, 'thiet-ke-web', 'Thiết kế web', $parent->id);

        expect(app(PathBuilder::class)->forEntry($child->fresh(), 'vi', 'thiet-ke-web'))->toBe('/dich-vu/thiet-ke-web')
            ->and(SiteRoute::query()->where('entry_id', $child->id)->value('path'))->toBe('/dich-vu/thiet-ke-web');
    });
});

it('resolves a nested page on the storefront', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $parent = publishNestedPage($editor, 'dich-vu', 'Dịch vụ');
        publishNestedPage($editor, 'thiet-ke-web', 'Thiết kế web', $parent->id);
    });

    $this->get('http://a.test/dich-vu/thiet-ke-web')->assertOk();
});

it('cascades a 301 to descendants when a parent slug changes', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $parent = publishNestedPage($editor, 'dich-vu', 'Dịch vụ');
        $child = publishNestedPage($editor, 'thiet-ke-web', 'Thiết kế web', $parent->id);

        $parent->translationFor('vi')->update(['slug' => 'dich-vu-moi']);
        app(RouteSync::class)->sync($parent->fresh(), 'vi', 'dich-vu-moi');
        app(RouteSync::class)->syncDescendants($parent->fresh(), 'vi');

        expect(SiteRoute::query()->where('entry_id', $child->id)->value('path'))->toBe('/dich-vu-moi/thiet-ke-web')
            ->and(Redirect::query()->where('from_path', '/dich-vu/thiet-ke-web')->where('to_path', '/dich-vu-moi/thiet-ke-web')->exists())->toBeTrue();
    });

    $this->get('http://a.test/dich-vu/thiet-ke-web')->assertRedirect('http://a.test/dich-vu-moi/thiet-ke-web');
    $this->get('http://a.test/dich-vu-moi/thiet-ke-web')->assertOk();
});

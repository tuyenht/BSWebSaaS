<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * W6 — Publish workflow Tầng-1 + route sync + 301 (design.md §15.4, §213). An actor
 * with entries.publish publishes straight through (route + published_at); without it
 * the entry parks as pending. Changing a published page's slug moves its route and
 * leaves a 301 the storefront serves; a revert must not create a redirect loop.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * Create a draft `page` + its vi translation in the current site context.
 *
 * @param  array<string, mixed>  $body
 */
function makeDraftPage(User $author, string $slug, string $title = 'Trang', array $body = ['blocks' => []]): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => $body,
    ]);

    return $entry;
}

it('publishes straight through for an actor with entries.publish', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish', 'entries.submit']);
        $page = makeDraftPage($editor, 'gioi-thieu');

        $status = app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        expect($status)->toBe('published')
            ->and($page->fresh()->status)->toBe('published')
            ->and($page->fresh()->published_at)->not->toBeNull()
            ->and($page->translationFor('vi')->status)->toBe('published')
            ->and(SiteRoute::query()->where('path', '/gioi-thieu')->where('entry_id', $page->id)->exists())->toBeTrue();
    });
});

it('parks as pending (no route, no published_at) for an actor without publish', function () {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();

    $tenancy->enter(1, function () use ($author) {
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
        $page = makeDraftPage($author, 'tin-tuc');

        $status = app(PublishEntry::class)->handle($page->fresh(), 'vi', $author->fresh());

        expect($status)->toBe('pending')
            ->and($page->fresh()->status)->toBe('pending')
            ->and($page->fresh()->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $page->id)->exists())->toBeFalse();
    });
});

it('rewrites the route and records a 301 when a published slug changes', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'gioi-thieu');
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 've-chung-toi']);
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/ve-chung-toi')
            ->and(SiteRoute::query()->where('path', '/gioi-thieu')->exists())->toBeFalse()
            ->and(Redirect::query()->where('from_path', '/gioi-thieu')->where('to_path', '/ve-chung-toi')->where('status_code', 301)->exists())->toBeTrue();
    });
});

it('does not create a redirect loop when a slug reverts (A→B→A)', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'a');
        $publish = app(PublishEntry::class);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 'b']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 'a']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/a')
            ->and(Redirect::query()->where('from_path', '/a')->exists())->toBeFalse()
            ->and(Redirect::query()->where('from_path', '/b')->where('to_path', '/a')->exists())->toBeTrue();
    });
});

it('serves the 301 from the storefront and 404s an unmapped path', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = makeDraftPage($editor, 'gioi-thieu', 'Giới thiệu', ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Giới thiệu']]]]);
        $publish = app(PublishEntry::class);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());

        $page->translationFor('vi')->update(['slug' => 've-chung-toi']);
        $publish->handle($page->fresh(), 'vi', $editor->fresh());
    });

    $this->get('http://a.test/gioi-thieu')->assertStatus(301)->assertRedirect('http://a.test/ve-chung-toi');
    $this->get('http://a.test/ve-chung-toi')->assertOk();
    $this->get('http://a.test/khong-ton-tai')->assertNotFound();
});

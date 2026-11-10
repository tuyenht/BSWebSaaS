<?php

use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * P2 — "Nhân bản" (design §3a). A copy is a fresh single-node DRAFT: parent_id cleared, every
 * translation copied with a "(copy)" title marker + a unique slug, both in that translation's own
 * language, categories carried over, and NO route (a draft has no public URL until it is published).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('copies an entry as a draft single node with a suffixed title and unique slug', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        $parent = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'parent_id' => $parent->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Dịch vụ', 'slug' => 'dich-vu', 'status' => 'published', 'body' => ['blocks' => []]]);

        $copy = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());

        expect($copy->status)->toBe('draft')
            ->and($copy->parent_id)->toBeNull()
            ->and($copy->author_id)->toBe($editor->id)
            ->and($copy->id)->not->toBe($page->id)
            ->and($copy->translationFor('vi')->title)->toBe('Dịch vụ (bản sao)')
            ->and($copy->translationFor('vi')->slug)->toBe('dich-vu-ban-sao')
            ->and(SiteRoute::query()->where('entry_id', $copy->id)->exists())->toBeFalse();
    });
});

it('marks each copied translation in its own language', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Dịch vụ', 'slug' => 'dich-vu', 'status' => 'published', 'body' => ['blocks' => []]]);
        $page->translations()->create(['locale' => 'en', 'title' => 'Services', 'slug' => 'services', 'status' => 'published', 'body' => ['blocks' => []]]);

        $copy = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());

        expect($copy->translationFor('vi')->title)->toBe('Dịch vụ (bản sao)')
            ->and($copy->translationFor('vi')->slug)->toBe('dich-vu-ban-sao')
            ->and($copy->translationFor('en')->title)->toBe('Services (copy)')
            ->and($copy->translationFor('en')->slug)->toBe('services-copy');
    });
});

it('suffixes the slug again when the copy slug is already taken', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang', 'status' => 'published', 'body' => ['blocks' => []]]);

        $first = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());
        $second = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());

        expect($first->translationFor('vi')->slug)->toBe('trang-ban-sao')
            ->and($second->translationFor('vi')->slug)->toBe('trang-ban-sao-2');
    });
});

it('gives the copy of a link stored with capitals a free lower-case link', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create']);
        // A row from before links were held to lower case.
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Bảng giá', 'slug' => 'Bang-Gia', 'status' => 'published', 'body' => ['blocks' => []]]);

        $first = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());
        $second = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());

        expect($first->translationFor('vi')->slug)->toBe('bang-gia-ban-sao')
            ->and($second->translationFor('vi')->slug)->toBe('bang-gia-ban-sao-2');
    });
});

it('carries over the categories', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create']);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

        $post = Entry::query()->create(['type' => 'post', 'status' => 'published']);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => 'bai', 'status' => 'published', 'body' => ['blocks' => []]]);
        $post->assignCategories([$category->id]);

        $copy = app(DuplicateEntry::class)->handle($post->fresh(), $editor->fresh());

        expect($copy->taxonomies()->get()->pluck('id')->all())->toBe([$category->id]);
    });
});

it('denies duplication without the create ability', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'viewer', ['entries.view']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang', 'status' => 'published', 'body' => ['blocks' => []]]);

        expect(fn () => app(DuplicateEntry::class)->handle($page->fresh(), $viewer->fresh()))
            ->toThrow(AuthorizationException::class);
    });
});

it('refuses to copy an entry that is in the Trash', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang', 'status' => 'published', 'body' => ['blocks' => []]]);
        $page->delete();

        expect(fn () => app(DuplicateEntry::class)->handle(Entry::withTrashed()->findOrFail($page->id), $editor->fresh()))
            ->toThrow(AuthorizationException::class, __('entries.err_duplicate_trashed'));

        expect(Entry::withTrashed()->where('type', 'page')->count())->toBe(1);
    });
});

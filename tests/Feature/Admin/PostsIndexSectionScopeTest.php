<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Livewire\Livewire;

/*
 * A post-collection list (Tin tức / Dịch vụ / Dự án…) must show ONLY its own posts. Every
 * collection shares entry_type=post and is distinguished by its bound taxonomy, so the list is
 * scoped to entries whose PRIMARY category (pivot position 0) is that section's taxonomy or one
 * below it (down to a sub-category another section is built on) — never every post of the type.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Create a post filed under $categoryId as its primary category, with a default-locale title. */
function makeSectionPost(User $author, string $title, string $slug, int $categoryId): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$categoryId]);

    return $entry;
}

/** A root category + the ContentSection bound to it (mirrors ProvisionSite/SeedAnVinhContent). */
function makeSection(string $key, string $label, string $categoryName, string $categorySlug): ContentSection
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $categoryName, 'slug' => $categorySlug]);

    return ContentSection::query()->create([
        'key' => $key,
        'label' => $label,
        'entry_type' => 'post',
        'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries',
        'sort_order' => 1,
    ]);
}

it('lists only the posts whose primary category belongs to the requested section', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);

        $news = makeSection('posts', 'Tin tức', 'Tin tức', 'tin-tuc');
        $services = makeSection('services', 'Dịch vụ', 'Dịch vụ', 'dich-vu');

        makeSectionPost($user, 'An Vinh team building 2024', 'team-building', (int) $news->taxonomy_id);
        makeSectionPost($user, 'Gia công Phay Tiện CNC', 'phay-tien-cnc', (int) $services->taxonomy_id);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'posts'])
            ->assertOk()
            ->assertSee('An Vinh team building 2024')
            ->assertDontSee('Gia công Phay Tiện CNC');

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'services'])
            ->assertOk()
            ->assertSee('Gia công Phay Tiện CNC')
            ->assertDontSee('An Vinh team building 2024');
    });
});

it('counts only the section own posts in the status tabs', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);

        $news = makeSection('posts', 'Tin tức', 'Tin tức', 'tin-tuc');
        $services = makeSection('services', 'Dịch vụ', 'Dịch vụ', 'dich-vu');

        makeSectionPost($user, 'Tin một', 'tin-1', (int) $news->taxonomy_id);
        makeSectionPost($user, 'Tin hai', 'tin-2', (int) $news->taxonomy_id);
        makeSectionPost($user, 'Dịch vụ một', 'dv-1', (int) $services->taxonomy_id);

        $component = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);

        expect($component->viewData('counts')['all'])->toBe(2)
            ->and($component->viewData('counts')['published'])->toBe(2);
    });
});

/** A category below $parentId (a sub-category of a section), named for the list's chips. */
function makeSubCategory(int $parentId, string $name, string $slug): Taxonomy
{
    $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parentId, 'position' => 1]);
    $child->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $child;
}

it('lists and counts the posts filed under the section sub-categories, and none of another section tree', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);

        $news = makeSection('posts', 'Tin tức', 'Tin tức', 'tin-tuc');
        $services = makeSection('services', 'Dịch vụ', 'Dịch vụ', 'dich-vu');
        $industry = makeSubCategory((int) $news->taxonomy_id, 'Tin tức ngành', 'tin-tuc-nganh');
        $deeper = makeSubCategory($industry->id, 'Tin ngành cơ khí', 'tin-nganh-co-khi');
        $machining = makeSubCategory((int) $services->taxonomy_id, 'Gia công', 'gia-cong');

        makeSectionPost($user, 'Tin gốc của mục', 'tin-goc', (int) $news->taxonomy_id);
        makeSectionPost($user, 'Tin trong mục con', 'tin-muc-con', $industry->id);
        makeSectionPost($user, 'Tin hai tầng dưới', 'tin-hai-tang', $deeper->id);
        makeSectionPost($user, 'Dịch vụ trong mục con', 'dv-muc-con', $machining->id);
        makeSectionPost($user, 'Tin đã bỏ', 'tin-da-bo', $industry->id)->delete();

        $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'posts'])
            ->assertOk()
            ->assertSee('Tin gốc của mục')
            ->assertSee('Tin trong mục con')
            ->assertSee('Tin hai tầng dưới')
            ->assertDontSee('Dịch vụ trong mục con')
            ->assertDontSee('Tin đã bỏ');

        expect($list->viewData('counts')['all'])->toBe(3)
            ->and($list->viewData('counts')['published'])->toBe(3)
            ->and($list->viewData('counts')['trash'])->toBe(1);

        // the trashed post is in Thùng rác only, and nothing else is
        $list->set('tab', 'trash')
            ->assertSee('Tin đã bỏ')
            ->assertDontSee('Tin trong mục con')
            ->assertDontSee('Dịch vụ trong mục con');

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'services'])
            ->assertSee('Dịch vụ trong mục con')
            ->assertDontSee('Tin trong mục con');
    });
});

it('leaves a sub-category another section is built on to that section', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);

        $news = makeSection('posts', 'Tin tức', 'Tin tức', 'tin-tuc');
        $events = makeSubCategory((int) $news->taxonomy_id, 'Sự kiện', 'su-kien');
        ContentSection::query()->create(['key' => 'events', 'label' => 'Sự kiện', 'entry_type' => 'post', 'taxonomy_id' => $events->id,
            'permission_prefix' => 'entries', 'sort_order' => 2]);

        makeSectionPost($user, 'Tin của mục cha', 'tin-cha', (int) $news->taxonomy_id);
        makeSectionPost($user, 'Bài của mục sự kiện', 'bai-su-kien', $events->id);

        $news = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'posts'])
            ->assertSee('Tin của mục cha')
            ->assertDontSee('Bài của mục sự kiện');
        expect($news->viewData('counts')['all'])->toBe(1);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'events'])
            ->assertSee('Bài của mục sự kiện')
            ->assertDontSee('Tin của mục cha');
    });
});

it('counts "posts" moved to the trash on a posts list, never "pages"', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.delete.any']);

        $news = makeSection('posts', 'Tin tức', 'Tin tức', 'tin-tuc');
        $one = makeSectionPost($user, 'Tin để bỏ', 'tin-de-bo', (int) $news->taxonomy_id);
        $two = makeSectionPost($user, 'Tin cũ', 'tin-cu', (int) $news->taxonomy_id);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'posts'])
            ->call('trash', $one->ulid)->call('trash', $two->ulid)
            ->assertSet('toastMsg', __('entries.trashed_many', ['count' => 2, 'noun' => __('entries.noun_post')]));
        expect(__('entries.trashed_many', ['count' => 2, 'noun' => __('entries.noun_post', [], 'vi')], 'vi'))->toBe('Đã chuyển 2 bài viết vào Thùng rác');
    });
});

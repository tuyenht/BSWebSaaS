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
 * scoped to entries whose PRIMARY category (pivot position 0) is that section's taxonomy — never
 * every post of the type.
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

<?php

use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ReflowSitePermalinks;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;

/*
 * The site search's address (/tim-kiem) is reserved for new content, but content that already stood
 * there before it was reserved keeps it: it can still be published again, change status, move along
 * its workflow and be re-synced. Only a target that is not at the address yet is refused.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** An entry of the current site whose 'vi' route already is /tim-kiem (written straight to the table, as before the address was reserved). */
function reservedEntry(string $type, string $status = 'published', ?int $author = null): Entry
{
    $entry = Entry::query()->create(['type' => $type, 'status' => $status, 'published_at' => now()->subDay(), 'author_id' => $author]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Trang cũ', 'slug' => 'tim-kiem', 'status' => $status, 'body' => ['blocks' => []]]);
    SiteRoute::query()->create(['locale' => 'vi', 'path' => '/tim-kiem', 'entry_id' => $entry->id]);

    return $entry->fresh();
}

it('lets a page and a product that already stand at /tim-kiem be saved, published and change status again', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);

        $page = reservedEntry('page', 'draft');
        expect(app(PublishEntry::class)->handle($page, 'vi', $editor->fresh()))->toBe('published')
            ->and(app(RouteSync::class)->sync($page->fresh(), 'vi', 'tim-kiem'))->toBe('/tim-kiem');

        // the product in the other language: one address holds one item per language
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()->subDay()]);
        $product->translations()->create(['locale' => 'en', 'name' => 'Old product', 'slug' => 'tim-kiem']);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/tim-kiem', 'product_id' => $product->id]);
        $status = app(ChangeProductStatus::class);
        $status->handle($product->fresh(), 'archived');
        $status->handle($product->fresh(), 'published');

        expect($product->fresh()->status)->toBe('published')
            ->and(SiteRoute::query()->where('path', '/tim-kiem')->where('product_id', $product->id)->count())->toBe(1);
    });
});

it('lets a post at /tim-kiem move along its workflow back to live', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.submit', 'entries.approve', 'entries.review']);
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin', 'slug' => 'tin']);
        ContentSection::query()->create(['key' => 'tin', 'label' => 'Tin', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        $workflow = Workflow::query()->create(['key' => 'duyet', 'name' => 'Duyệt', 'applies_to_type' => 'tin', 'template' => 'editorial', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Nháp', 'is_initial' => true], ['key' => 'pending', 'label' => 'Chờ'], ['key' => 'published', 'label' => 'Đăng', 'is_published' => true]] as $state) {
            $workflow->states()->create($state);
        }
        foreach ([
            ['key' => 'submit', 'label' => 'Gửi', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
            ['key' => 'approve', 'label' => 'Duyệt', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
        ] as $transition) {
            $workflow->transitions()->create($transition);
        }

        $post = reservedEntry('post', 'draft', $editor->id);
        $post->assignCategories([$category->id]);
        $move = app(ApplyEntryWorkflowTransition::class);
        $move->handle($post->fresh(), 'vi', $editor->fresh(), 'submit');

        expect($move->handle($post->fresh(), 'vi', $editor->fresh(), 'approve'))->toBe('published')
            ->and($post->fresh()->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tim-kiem');
    });
});

it('leaves content already at /tim-kiem unchanged in a permalink reflow', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $post = reservedEntry('post');
        $product = Product::query()->create(['status' => 'published', 'published_at' => now()->subDay()]);
        $product->translations()->create(['locale' => 'en', 'name' => 'Old product', 'slug' => 'tim-kiem']);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/tim-kiem', 'product_id' => $product->id]);

        $summary = collect(app(ReflowSitePermalinks::class)->handle(dryRun: false));

        expect($summary->firstWhere('type', 'post')['status'] ?? null)->toBe('unchanged')
            ->and($summary->firstWhere('type', 'product')['status'] ?? null)->toBe('unchanged')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tim-kiem');
    });
});

it('still refuses /tim-kiem to a new item and to a slug change onto it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $sync = app(RouteSync::class);
        $new = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        expect(fn () => $sync->sync($new, 'vi', 'tim-kiem'))->toThrow(RouteConflictException::class);

        $moved = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        $sync->sync($moved, 'vi', 'gioi-thieu');
        expect(fn () => $sync->sync($moved->fresh(), 'vi', 'tim-kiem'))->toThrow(RouteConflictException::class)
            ->and(fn () => $sync->syncProduct(Product::query()->create(['status' => 'published'])->id, null, 'vi', 'tim-kiem'))->toThrow(RouteConflictException::class)
            // standing at the address in one language is no claim on it in another
            ->and(fn () => $sync->sync(reservedEntry('page'), 'en', 'tim-kiem'))->toThrow(RouteConflictException::class)
            ->and(SiteRoute::query()->where('path', '/tim-kiem')->where('locale', 'vi')->count())->toBe(1)
            ->and(SiteRoute::query()->where('entry_id', $moved->id)->value('path'))->toBe('/gioi-thieu');
    });
});

it('lets a category already at /tim-kiem be saved again, and refuses the address to a new category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $sync = app(RouteSync::class);
        $old = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $old->translations()->create(['locale' => 'vi', 'name' => 'Tìm kiếm', 'slug' => 'tim-kiem']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/tim-kiem', 'taxonomy_id' => $old->id]);

        expect($sync->syncTaxonomy($old->fresh(), 'vi', 'tim-kiem'))->toBe('/tim-kiem');

        $new = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
        expect(fn () => $sync->syncTaxonomy($new, 'vi', 'tim-kiem'))->toThrow(RouteConflictException::class)
            ->and(SiteRoute::query()->where('path', '/tim-kiem')->where('locale', 'vi')->pluck('taxonomy_id')->all())->toBe([$old->id]);
    });
});

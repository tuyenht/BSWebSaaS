<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\Menus\SaveMenu;
use App\Modules\Cms\Actions\Menus\SaveMenuItem;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\MenuItemData;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\PermalinkConfig;
use App\Modules\Tenancy\Context\SiteLocale as SiteLocaleContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Support\Facades\DB;

/*
 * The category write path behind the Categories screen (design.md §9): create, rename, move and
 * delete categories. The rules hold whoever calls: at most three levels, no loops, one vocabulary per
 * tree, one slug per language, the ltree path mirrors parent_id, nothing filed under a category is lost
 * without a decision, and every address that stops existing answers with a 301 — the category's own
 * page and, where a permalink preset carries categories, every post filed under it.
 */

beforeEach(function (): void {
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

/** Run inside site 1. */
function twSiteOne(Closure $callback): mixed
{
    return app(Tenancy::class)->enter(1, $callback);
}

/** Why a category change was refused, or null when it went through. */
function twRefusal(Closure $change): ?string
{
    try {
        $change();
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    }

    return null;
}

function twCreate(string $name, ?Taxonomy $parent = null, string $vocabulary = 'post_cat', ?string $slug = null): Taxonomy
{
    return app(CreateTaxonomy::class)->handle($vocabulary, $parent, new TaxonomyData(
        names: ['vi' => $name],
        slugs: $slug === null ? [] : ['vi' => $slug],
    ));
}

function twMove(Taxonomy $taxonomy, ?Taxonomy $parent, int $position = 0): void
{
    app(MoveTaxonomy::class)->handle($taxonomy, $parent, $position);
}

function twPath(Taxonomy $taxonomy): ?string
{
    return Taxonomy::query()->whereKey($taxonomy->id)->value('path');
}

function twPage(Taxonomy $taxonomy): ?string
{
    return SiteRoute::query()->where('taxonomy_id', $taxonomy->id)->where('locale', 'vi')->value('path');
}

/** Bind a posts section to a root category and pin its permalink preset. */
function twSection(Taxonomy $root, string $pattern, string $key = 'posts'): void
{
    ContentSection::query()->create([
        'key' => $key, 'label' => $key, 'entry_type' => 'post',
        'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => $key, 'value' => ['pattern' => $pattern]]);
    app(PermalinkConfig::class)->flush();
}

/**
 * A published post filed under $categories (index 0 = primary), with its route synced.
 *
 * @param  list<Taxonomy>  $categories
 */
function twPost(string $slug, array $categories): Entry
{
    $post = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
    $post->translations()->create(['locale' => 'vi', 'title' => $slug, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
    $post->assignCategories(array_map(fn (Taxonomy $category): int => $category->id, $categories));
    app(RouteSync::class)->sync($post->fresh() ?? $post, 'vi', $slug);

    return $post;
}

function twRoute(Entry $post): ?string
{
    return SiteRoute::query()->where('entry_id', $post->id)->where('locale', 'vi')->value('path');
}

/** @return array<int, int> taxonomy id → pivot position, in rank order */
function twFiled(Entry $post): array
{
    return DB::table('entry_taxonomies')->where('entry_id', $post->id)->orderBy('position')->pluck('position', 'taxonomy_id')->all();
}

function twRedirect(string $from): ?string
{
    return Redirect::query()->where('locale', 'vi')->where('from_path', $from)->where('status_code', 301)->value('to_path');
}

it('creates a category with its page, its ltree path and its place among its siblings', function (): void {
    twSiteOne(function (): void {
        $news = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức'], listTemplate: 'category', detailTemplate: 'post'));
        $events = twCreate('Sự kiện', $news);
        $talks = twCreate('Hội thảo', $news, slug: 'hoi-thao-chuyen-de');

        expect($news->translationFor('vi')?->slug)->toBe('tin-tuc')
            ->and($news->list_template)->toBe('category')
            ->and($news->detail_template)->toBe('post')
            ->and(twPage($news))->toBe('/tin-tuc')
            ->and(twPage($talks))->toBe('/hoi-thao-chuyen-de')
            ->and(twPath($news))->toBe((string) $news->id)
            ->and(twPath($events))->toBe("{$news->id}.{$events->id}")
            ->and($events->parent_id)->toBe($news->id)
            ->and([$events->position, $talks->position])->toBe([0, 1]);
    });
});

it('refuses a category that is badly named, outside the site languages, or at an address in use', function (): void {
    twSiteOne(function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $page->id]);
        twCreate('Tin tức');

        $create = fn (TaxonomyData $data, string $vocabulary = 'post_cat') => app(CreateTaxonomy::class)->handle($vocabulary, null, $data);

        expect(twRefusal(fn () => twCreate('   ')))->toBe('name_invalid')
            ->and(twRefusal(fn () => twCreate(str_repeat('a', 121))))->toBe('name_invalid')
            ->and(twRefusal(fn () => twCreate('Tin mới', slug: 'Tin Mới')))->toBe('slug_invalid')
            ->and(twRefusal(fn () => $create(new TaxonomyData(names: ['en' => 'News']))))->toBe('locale_invalid')
            ->and(twRefusal(fn () => $create(new TaxonomyData(names: ['vi' => 'Tin']), 'Post Cat')))->toBe('vocabulary_invalid')
            ->and(twRefusal(fn () => $create(new TaxonomyData(names: ['vi' => 'Tin'], listTemplate: 'no-such-layout'))))->toBe('template_invalid')
            ->and(twRefusal(fn () => $create(new TaxonomyData(names: ['vi' => 'Tin'], detailTemplate: '../page'))))->toBe('template_invalid')
            ->and(twRefusal(fn () => twCreate('Liên hệ')))->toBe('slug_taken')
            ->and(twRefusal(fn () => twCreate('Tin tức', vocabulary: 'product_cat')))->toBe('slug_taken')
            ->and(Taxonomy::query()->count())->toBe(1);

        // A second writing language is fine, but the default language's name is required.
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        app(SiteLocaleContext::class)->forget();
        expect(twRefusal(fn () => $create(new TaxonomyData(names: ['en' => 'News']))))->toBe('name_invalid');

        $both = $create(new TaxonomyData(names: ['vi' => 'Sự kiện', 'en' => 'Events']));
        expect(SiteRoute::query()->where('taxonomy_id', $both->id)->orderBy('locale')->pluck('path', 'locale')->all())
            ->toBe(['en' => '/events', 'vi' => '/su-kien']);
    });
});

it('renames a category: its page moves with a 301, a new name alone keeps the address', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $update = fn (TaxonomyData $data) => app(UpdateTaxonomy::class)->handle($news, $data);

        $update(new TaxonomyData(names: ['vi' => 'Tin tức & sự kiện']));
        expect($news->translationFor('vi')?->name)->toBe('Tin tức & sự kiện')
            ->and(twPage($news))->toBe('/tin-tuc')
            ->and(Redirect::query()->count())->toBe(0);

        $update(new TaxonomyData(slugs: ['vi' => 'ban-tin'], listTemplate: 'category'));
        expect(twPage($news))->toBe('/ban-tin')
            ->and(twRedirect('/tin-tuc'))->toBe('/ban-tin')
            ->and($news->fresh()?->list_template)->toBe('category');

        // Back again: one hop from each old address, never a loop from the live one.
        $update(new TaxonomyData(slugs: ['vi' => 'tin-tuc']));
        expect(twPage($news))->toBe('/tin-tuc')
            ->and(twRedirect('/ban-tin'))->toBe('/tin-tuc')
            ->and(twRedirect('/tin-tuc'))->toBeNull()
            ->and($news->fresh()?->list_template)->toBeNull();
    });
});

it('refuses a rename onto an address in use and changes nothing', function (): void {
    twSiteOne(function (): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now()]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/lien-he', 'entry_id' => $page->id]);
        $news = twCreate('Tin tức');
        twCreate('Sự kiện');

        expect(twRefusal(fn () => app(UpdateTaxonomy::class)->handle($news, new TaxonomyData(slugs: ['vi' => 'lien-he']))))->toBe('slug_taken')
            ->and(twRefusal(fn () => app(UpdateTaxonomy::class)->handle($news, new TaxonomyData(slugs: ['vi' => 'su-kien']))))->toBe('slug_taken')
            ->and($news->translationFor('vi')?->slug)->toBe('tin-tuc')
            ->and(twPage($news))->toBe('/tin-tuc');
    });
});

it('moves a category among its siblings and under another parent, rewriting the branch path', function (): void {
    twSiteOne(function (): void {
        $a = twCreate('A');
        $b = twCreate('B');
        $c = twCreate('C');

        twMove($c, null, 0);
        expect(Taxonomy::query()->whereNull('parent_id')->orderBy('position')->pluck('id')->all())->toBe([$c->id, $a->id, $b->id]);

        $a1 = twCreate('A1', $a);
        $a2 = twCreate('A2', $a1);
        $b1 = twCreate('B1', $b);

        twMove($a1, $b, 0);
        expect($a1->fresh()?->parent_id)->toBe($b->id)
            ->and(Taxonomy::query()->where('parent_id', $b->id)->orderBy('position')->pluck('position', 'id')->all())->toBe([$a1->id => 0, $b1->id => 1])
            ->and(twPath($a1))->toBe("{$b->id}.{$a1->id}")
            ->and(twPath($a2))->toBe("{$b->id}.{$a1->id}.{$a2->id}");
    });
});

it('never lets the tree loop, grow past three levels or mix vocabularies', function (): void {
    twSiteOne(function (): void {
        $l1 = twCreate('L1');
        $l2 = twCreate('L2', $l1);
        $l3 = twCreate('L3', $l2);
        $machines = twCreate('Máy', vocabulary: 'product_cat');

        expect(twRefusal(fn () => twCreate('L4', $l3)))->toBe('depth_exceeded')
            ->and(twRefusal(fn () => twMove($l1, $l3)))->toBe('parent_invalid')
            ->and(twRefusal(fn () => twMove($l2, $l2)))->toBe('parent_invalid')
            ->and(twRefusal(fn () => twMove($machines, $l1)))->toBe('vocabulary_mismatch')
            ->and(twRefusal(fn () => twCreate('Máy CNC', $l1, 'product_cat')))->toBe('vocabulary_mismatch');

        // The branch under L2 is two levels tall: it fits under a top-level category (levels 2–3)
        // but not under a second-level one (levels 3–4).
        $root = twCreate('R');
        $rootChild = twCreate('R1', $root);
        expect(twRefusal(fn () => twMove($l2, $root, 1)))->toBeNull()
            ->and(twRefusal(fn () => twMove($l2, $rootChild)))->toBe('depth_exceeded')
            ->and($l2->fresh()?->parent_id)->toBe($root->id);
    });
});

it('moves posts with a renamed or moved category under the category-path preset, each with a 301', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $projects = twCreate('Dự án');
        twSection($news, 'category_path');
        twSection($projects, 'category_path', 'projects');
        $events = twCreate('Sự kiện', $news);
        $post = twPost('hoi-thao', [$events]);
        $other = twPost('ra-mat', [$news]);
        expect(twRoute($post))->toBe('/tin-tuc/su-kien/hoi-thao');

        app(UpdateTaxonomy::class)->handle($events, new TaxonomyData(slugs: ['vi' => 'su-kien-moi']));
        expect(twRoute($post))->toBe('/tin-tuc/su-kien-moi/hoi-thao')
            ->and(twRedirect('/tin-tuc/su-kien/hoi-thao'))->toBe('/tin-tuc/su-kien-moi/hoi-thao')
            ->and(twRoute($other))->toBe('/tin-tuc/ra-mat');

        $archive = twCreate('Lưu trữ', $news);
        twMove($events, $archive);
        expect(twRoute($post))->toBe('/tin-tuc/luu-tru/su-kien-moi/hoi-thao')
            ->and(twRedirect('/tin-tuc/su-kien-moi/hoi-thao'))->toBe('/tin-tuc/luu-tru/su-kien-moi/hoi-thao')
            ->and(twRedirect('/tin-tuc/su-kien/hoi-thao'))->toBe('/tin-tuc/luu-tru/su-kien-moi/hoi-thao');
    });
});

it('refuses a move that would hand posts to another section or to none', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $projects = twCreate('Dự án');
        twSection($news, 'category_path');
        twSection($projects, 'category_path', 'projects');
        $events = twCreate('Sự kiện', $news);
        $post = twPost('hoi-thao', [$events]);

        // A section carries its posts' permissions and approval workflow.
        expect(twRefusal(fn () => twMove($events, $projects)))->toBe('section_changed')
            ->and(twRefusal(fn () => twMove($events, null)))->toBe('section_changed')
            ->and($events->fresh()?->parent_id)->toBe($news->id)
            ->and(twRoute($post))->toBe('/tin-tuc/su-kien/hoi-thao');

        // Without posts there is nothing to hand over; posts joining a section from none gain an
        // approval workflow rather than escape one; a section's own root keeps its section.
        $empty = twCreate('Trống', $news);
        $loose = twCreate('Rời');
        twPost('rieng', [$loose]);
        expect(twRefusal(fn () => twMove($empty, $projects)))->toBeNull()
            ->and(twRefusal(fn () => twMove($loose, $news)))->toBeNull()
            ->and(twRefusal(fn () => twMove($projects, $news)))->toBeNull()
            ->and($projects->fresh()?->parent_id)->toBe($news->id);
    });
});

it('moves posts to another section once the caller confirms it, each with a 301', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $projects = twCreate('Dự án');
        twSection($news, 'category_path');
        twSection($projects, 'category_path', 'projects');
        $events = twCreate('Sự kiện', $news);
        $post = twPost('hoi-thao', [$events]);

        app(MoveTaxonomy::class)->handle($events, $projects, 0, evenIfSectionChanges: true);

        expect(twRoute($post))->toBe('/du-an/su-kien/hoi-thao')
            ->and(twRedirect('/tin-tuc/su-kien/hoi-thao'))->toBe('/du-an/su-kien/hoi-thao');
    });
});

it('never moves posts out of a section whose approval workflow is on, even when confirmed', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $projects = twCreate('Dự án');
        twSection($news, 'category_path');
        twSection($projects, 'category_path', 'projects');
        Workflow::query()->create(['key' => 'review', 'name' => 'Duyệt bài', 'applies_to_type' => 'posts', 'is_active' => true]);
        $events = twCreate('Sự kiện', $news);
        $post = twPost('hoi-thao', [$events]);

        // The editor refuses re-filing such a post one by one; a category move is no way around it.
        expect(twRefusal(fn () => app(MoveTaxonomy::class)->handle($events, $projects, 0, evenIfSectionChanges: true)))->toBe('workflow_governed')
            ->and(twRefusal(fn () => app(MoveTaxonomy::class)->handle($events, null, 0, evenIfSectionChanges: true)))->toBe('workflow_governed')
            ->and($events->fresh()?->parent_id)->toBe($news->id)
            ->and(twRoute($post))->toBe('/tin-tuc/su-kien/hoi-thao');

        // Inside the section, or with no posts to take along, nothing escapes the workflow.
        $archive = twCreate('Lưu trữ', $news);
        $empty = twCreate('Trống', $news);
        expect(twRefusal(fn () => twMove($events, $archive)))->toBeNull()
            ->and(twRefusal(fn () => twMove($empty, $projects)))->toBeNull();

        // A section without an active workflow still only asks for confirmation — a switched-off
        // workflow included.
        Workflow::query()->where('key', 'review')->update(['is_active' => false]);
        expect(twRefusal(fn () => twMove($events, $projects)))->toBe('section_changed');
        $sites = twCreate('Công trình', $projects);
        twPost('cong-trinh', [$sites]);
        expect(twRefusal(fn () => twMove($sites, $news)))->toBe('section_changed');
    });
});

it('never re-files or detaches posts out of a section whose approval workflow is on, even when confirmed', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $projects = twCreate('Dự án');
        twSection($news, 'category');
        twSection($projects, 'category', 'projects');
        Workflow::query()->create(['key' => 'review', 'name' => 'Duyệt bài', 'applies_to_type' => 'posts', 'is_active' => true]);
        $events = twCreate('Sự kiện', $news);
        $talks = twCreate('Hội thảo', $news);
        $sites = twCreate('Công trình', $projects);
        $post = twPost('bai', [$events]);
        $delete = app(DeleteTaxonomy::class);

        expect(twRefusal(fn () => $delete->handle($events, CategoryItemsOnDelete::Reassign, $sites, evenIfSectionChanges: true)))->toBe('workflow_governed')
            ->and(twRefusal(fn () => $delete->handle($events, CategoryItemsOnDelete::Detach, evenIfSectionChanges: true)))->toBe('workflow_governed')
            ->and(twFiled($post))->toBe([$events->id => 0]);

        // Onto a category of the same section is ordinary filing.
        expect(twRefusal(fn () => $delete->handle($events, CategoryItemsOnDelete::Reassign, $talks)))->toBeNull()
            ->and(twFiled($post))->toBe([$talks->id => 0]);
    });
});

it('refuses a delete that would take posts out of their section unless confirmed', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $projects = twCreate('Dự án');
        twSection($news, 'category');
        twSection($projects, 'category', 'projects');
        $events = twCreate('Sự kiện', $news);
        $talks = twCreate('Hội thảo', $news);
        $sites = twCreate('Công trình', $projects);
        $post = twPost('bai', [$events]);
        $delete = app(DeleteTaxonomy::class);

        expect(twRefusal(fn () => $delete->handle($events, CategoryItemsOnDelete::Reassign, $sites)))->toBe('section_changed')
            ->and(twRefusal(fn () => $delete->handle($events, CategoryItemsOnDelete::Detach)))->toBe('section_changed')
            ->and(twFiled($post))->toBe([$events->id => 0]);

        $delete->handle($events, CategoryItemsOnDelete::Reassign, $sites, evenIfSectionChanges: true);
        expect(twFiled($post))->toBe([$sites->id => 0])
            ->and(twRoute($post))->toBe('/cong-trinh/bai');

        // Onto a category of the same section needs no confirmation.
        $later = twPost('bai-2', [$talks]);
        $more = twCreate('Hội nghị', $news);
        expect(twRefusal(fn () => $delete->handle($talks, CategoryItemsOnDelete::Reassign, $more)))->toBeNull()
            ->and(twFiled($later))->toBe([$more->id => 0]);
    });
});

it('leaves post addresses alone when every preset is flat', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $events = twCreate('Sự kiện', $news);
        $post = twPost('hoi-thao', [$events]);

        app(UpdateTaxonomy::class)->handle($events, new TaxonomyData(slugs: ['vi' => 'su-kien-moi']));
        twMove($events, null);

        expect(twRoute($post))->toBe('/hoi-thao')
            ->and(Redirect::query()->pluck('from_path')->all())->toBe(['/su-kien']);
    });
});

it('keeps a category with posts or sub-categories unless told what to do with them', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        twSection($news, 'flat');
        $events = twCreate('Sự kiện', $news);
        $jobs = twCreate('Tuyển dụng');
        twPost('tin', [$jobs]);
        $delete = app(DeleteTaxonomy::class);

        expect(twRefusal(fn () => $delete->handle($events->fresh() ?? $events)))->toBeNull()
            ->and(twRefusal(fn () => $delete->handle($jobs)))->toBe('has_items')
            ->and(twRefusal(fn () => $delete->handle($news, CategoryItemsOnDelete::Detach, children: CategoryChildrenOnDelete::Lift)))->toBe('section_bound');

        $parent = twCreate('Cha');
        twCreate('Con', $parent);
        expect(twRefusal(fn () => $delete->handle($parent)))->toBe('has_children')
            ->and(Taxonomy::query()->whereKey([$jobs->id, $parent->id, $news->id])->count())->toBe(3)
            ->and(Taxonomy::query()->whereKey($events->id)->exists())->toBeFalse();
    });
});

it('moves the posts of a deleted category onto another, keeping their other categories and facet terms', function (): void {
    twSiteOne(function (): void {
        $a = twCreate('A');
        $b = twCreate('B');
        $c = twCreate('C');
        $sector = twCreate('Ô tô', vocabulary: 'sector');

        $first = twPost('mot', [$a, $c]);
        $first->taxonomies()->attach($sector->id, ['position' => Entry::FACET_POSITION_BASE, 'site_id' => 1]);
        $second = twPost('hai', [$c, $a]);
        $third = twPost('ba', [$a, $b]);
        $fourth = twPost('bon', [$b, $c, $a]);

        $result = app(DeleteTaxonomy::class)->handle($a, CategoryItemsOnDelete::Reassign, $b);

        expect($result['items'])->toBe(4)
            ->and(twFiled($first))->toBe([$b->id => 0, $c->id => 1, $sector->id => Entry::FACET_POSITION_BASE])
            ->and(twFiled($second))->toBe([$c->id => 0, $b->id => 1])
            ->and(twFiled($third))->toBe([$b->id => 0])
            ->and(twFiled($fourth))->toBe([$b->id => 0, $c->id => 1])
            ->and(Taxonomy::query()->whereKey($a->id)->exists())->toBeFalse();
    });
});

it('detaches the posts of a deleted category and renumbers what they keep', function (): void {
    twSiteOne(function (): void {
        $a = twCreate('A');
        $c = twCreate('C');
        $sector = twCreate('Ô tô', vocabulary: 'sector');
        $first = twPost('mot', [$a, $c]);
        $first->taxonomies()->attach($sector->id, ['position' => Entry::FACET_POSITION_BASE, 'site_id' => 1]);
        $second = twPost('hai', [$c, $a]);
        $only = twPost('ba', [$a]);

        app(DeleteTaxonomy::class)->handle($a, CategoryItemsOnDelete::Detach);

        expect(twFiled($first))->toBe([$c->id => 0, $sector->id => Entry::FACET_POSITION_BASE])
            ->and(twFiled($second))->toBe([$c->id => 0])
            ->and(twFiled($only))->toBe([]);

        // Deleting a facet term drops it and leaves every category rank alone.
        app(DeleteTaxonomy::class)->handle($sector, CategoryItemsOnDelete::Detach);
        expect(twFiled($first))->toBe([$c->id => 0]);
    });
});

it('lifts sub-categories into the deleted category\'s place, or deletes the whole branch', function (): void {
    twSiteOne(function (): void {
        $root = twCreate('Gốc');
        $x = twCreate('X', $root);
        $d = twCreate('D', $root);
        $y = twCreate('Y', $root);
        $d1 = twCreate('D1', $d);
        $d2 = twCreate('D2', $d);

        app(DeleteTaxonomy::class)->handle($d, children: CategoryChildrenOnDelete::Lift);
        expect(Taxonomy::query()->where('parent_id', $root->id)->orderBy('position')->pluck('position', 'id')->all())
            ->toBe([$x->id => 0, $d1->id => 1, $d2->id => 2, $y->id => 3])
            ->and(twPath($d1))->toBe("{$root->id}.{$d1->id}")
            ->and(twPath($d2))->toBe("{$root->id}.{$d2->id}");

        $z = twCreate('Z');
        $e = twCreate('E', $z);
        $e1 = twCreate('E1', $e);
        $post = twPost('bai', [$e1]);
        $result = app(DeleteTaxonomy::class)->handle($z, CategoryItemsOnDelete::Reassign, $x, CategoryChildrenOnDelete::Delete);

        expect($result['categories'])->toBe(3)
            ->and(Taxonomy::query()->whereKey([$z->id, $e->id, $e1->id])->exists())->toBeFalse()
            ->and(twFiled($post))->toBe([$x->id => 0]);
    });
});

it('moves the posts of lifted sub-categories under the category-path preset', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        twSection($news, 'category_path');
        $events = twCreate('Sự kiện', $news);
        $talks = twCreate('Hội thảo', $events);
        $post = twPost('bai', [$talks]);
        expect(twRoute($post))->toBe('/tin-tuc/su-kien/hoi-thao/bai');

        app(DeleteTaxonomy::class)->handle($events, children: CategoryChildrenOnDelete::Lift);

        expect(twRoute($post))->toBe('/tin-tuc/hoi-thao/bai')
            ->and(twRedirect('/tin-tuc/su-kien/hoi-thao/bai'))->toBe('/tin-tuc/hoi-thao/bai')
            ->and(twRedirect('/su-kien'))->toBe('/tin-tuc')
            ->and($talks->fresh()?->parent_id)->toBe($news->id)
            ->and(twPath($talks))->toBe("{$news->id}.{$talks->id}");
    });
});

it('answers every address a deleted category leaves behind with a 301', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        twSection($news, 'category');
        $events = twCreate('Sự kiện', $news);
        $talks = twCreate('Hội thảo', $news);
        $jobs = twCreate('Tuyển dụng', $news);
        $moved = twPost('bai-viet', [$events]);
        $detached = twPost('tin', [$jobs]);
        expect(twRoute($moved))->toBe('/su-kien/bai-viet');

        $result = app(DeleteTaxonomy::class)->handle($events, CategoryItemsOnDelete::Reassign, $talks);
        expect($result)->toBe(['categories' => 1, 'items' => 1, 'routes_moved' => 1, 'redirects' => 1, 'menu_links' => 0])
            ->and(twRoute($moved))->toBe('/hoi-thao/bai-viet')
            ->and(twRedirect('/su-kien/bai-viet'))->toBe('/hoi-thao/bai-viet')
            ->and(twRedirect('/su-kien'))->toBe('/hoi-thao');

        // No target: the page goes to the nearest category above; the post loses its section too,
        // which the caller has to confirm.
        app(DeleteTaxonomy::class)->handle($jobs, CategoryItemsOnDelete::Detach, evenIfSectionChanges: true);
        expect(twRoute($detached))->toBe('/tin')
            ->and(twRedirect('/tuyen-dung/tin'))->toBe('/tin')
            ->and(twRedirect('/tuyen-dung'))->toBe('/tin-tuc');

        // Nothing above: the home page.
        $orphan = twCreate('Khác');
        app(DeleteTaxonomy::class)->handle($orphan);
        expect(twRedirect('/khac'))->toBe('/');
    });
});

it('points menu links at the category the items moved to, and leaves them when there is none', function (): void {
    twSiteOne(function (): void {
        $news = twCreate('Tin tức');
        $events = twCreate('Sự kiện', $news);
        $talks = twCreate('Hội thảo', $news);
        $jobs = twCreate('Tuyển dụng');
        $menu = app(SaveMenu::class)->handle(null, 'Menu chính');
        $toEvents = app(SaveMenuItem::class)->handle($menu, null, new MenuItemData('taxonomy', $events->id));
        $toJobs = app(SaveMenuItem::class)->handle($menu, null, new MenuItemData('taxonomy', $jobs->id));

        $result = app(DeleteTaxonomy::class)->handle($events, CategoryItemsOnDelete::Reassign, $talks);
        app(DeleteTaxonomy::class)->handle($jobs, CategoryItemsOnDelete::Detach);

        expect($result['menu_links'])->toBe(1)
            ->and($toEvents->fresh()?->routable_id)->toBe($talks->id)
            ->and($toJobs->fresh()?->routable_id)->toBe($jobs->id);
    });
});

it('refuses a target that is not a remaining category of the same vocabulary', function (): void {
    $foreign = app(Tenancy::class)->enter(2, fn () => twCreate('Ngoài'));

    twSiteOne(function () use ($foreign): void {
        $a = twCreate('A');
        $child = twCreate('A1', $a);
        $machines = twCreate('Máy', vocabulary: 'product_cat');
        twPost('bai', [$a]);
        $delete = app(DeleteTaxonomy::class);

        expect(twRefusal(fn () => $delete->handle($a, CategoryItemsOnDelete::Reassign, null, CategoryChildrenOnDelete::Lift)))->toBe('target_invalid')
            ->and(twRefusal(fn () => $delete->handle($a, CategoryItemsOnDelete::Reassign, $child, CategoryChildrenOnDelete::Delete)))->toBe('target_invalid')
            ->and(twRefusal(fn () => $delete->handle($a, CategoryItemsOnDelete::Reassign, $foreign, CategoryChildrenOnDelete::Lift)))->toBe('target_invalid')
            ->and(twRefusal(fn () => $delete->handle($a, CategoryItemsOnDelete::Reassign, $machines, CategoryChildrenOnDelete::Lift)))->toBe('vocabulary_mismatch')
            ->and(Taxonomy::query()->whereKey($a->id)->exists())->toBeTrue();
    });
});

it('does not reach another site', function (): void {
    $foreign = app(Tenancy::class)->enter(2, fn () => twCreate('Ngoài'));

    twSiteOne(function () use ($foreign): void {
        $local = twCreate('Trong');

        expect(Taxonomy::query()->whereKey($foreign->id)->exists())->toBeFalse()
            ->and(twRefusal(fn () => app(UpdateTaxonomy::class)->handle($foreign, new TaxonomyData(names: ['vi' => 'X']))))->toBe('taxonomy_invalid')
            ->and(twRefusal(fn () => twMove($foreign, null)))->toBe('taxonomy_invalid')
            ->and(twRefusal(fn () => app(DeleteTaxonomy::class)->handle($foreign)))->toBe('taxonomy_invalid')
            ->and(twRefusal(fn () => twCreate('Con', $foreign)))->toBe('parent_invalid')
            ->and(twRefusal(fn () => twMove($local, $foreign)))->toBe('parent_invalid');
    });

    app(Tenancy::class)->enter(2, function () use ($foreign): void {
        expect($foreign->fresh()?->translationFor('vi')?->name)->toBe('Ngoài');
    });
});

it('records every change in the audit log', function (): void {
    twSiteOne(function (): void {
        $a = twCreate('A');
        $b = twCreate('B');
        app(UpdateTaxonomy::class)->handle($a, new TaxonomyData(slugs: ['vi' => 'a-moi']));
        twMove($b, $a);
        app(DeleteTaxonomy::class)->handle($a, children: CategoryChildrenOnDelete::Lift);
    });

    $rows = AuditLog::query()->where('site_id', 1)->orderBy('id')->get(['action', 'meta']);
    expect($rows->pluck('action')->all())->toBe([
        'cms.taxonomy.created', 'cms.taxonomy.created', 'cms.taxonomy.updated', 'cms.taxonomy.moved', 'cms.taxonomy.deleted',
    ])
        ->and($rows[2]->meta['slugs_changed'] ?? null)->toBe(['vi' => 'a-moi'])
        ->and($rows[4]->meta['slugs'] ?? null)->toBe(['a-moi']);
});

it('rebuilds missing paths on every site, and a second run writes nothing', function (): void {
    [$root, $child] = twSiteOne(function (): array {
        $root = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'parent_id' => $root->id]);

        return [$root, $child];
    });
    $other = app(Tenancy::class)->enter(2, fn () => Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]));

    $this->artisan('cms:rebuild-taxonomy-paths')->expectsOutputToContain('3 category path(s) written')->assertSuccessful();
    $this->artisan('cms:rebuild-taxonomy-paths')->expectsOutputToContain('0 category path(s) written')->assertSuccessful();

    twSiteOne(fn () => expect([twPath($root), twPath($child)])->toBe([(string) $root->id, "{$root->id}.{$child->id}"]));
    app(Tenancy::class)->enter(2, fn () => expect(twPath($other))->toBe((string) $other->id));
});

it('keeps paths right when an older parent has none yet', function (): void {
    twSiteOne(function (): void {
        $legacy = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $legacy->translations()->create(['locale' => 'vi', 'name' => 'Cũ', 'slug' => 'cu']);
        expect(twPath($legacy))->toBeNull();

        $child = twCreate('Mới', $legacy);
        expect(twPath($child))->toBe("{$legacy->id}.{$child->id}");
    });
});

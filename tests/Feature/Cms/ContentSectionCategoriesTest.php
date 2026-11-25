<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\ContentSections;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\ContentSectionCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * content_section_categories: a content section covers one category or several side by side (the
 * news and the job offers, two categories at the top of the tree, one «Tin tức» list in the
 * admin), and a category belongs to one section at most. A post belongs to the section covering
 * the NEAREST category up its main category's chain. The section's first category stays on
 * content_sections.taxonomy_id, and the section keeps it in the table too. A website table: RLS
 * FORCE fails closed, and another site's rows are never read or written.
 */

beforeEach(function (): void {
    seedTenancy();
});

function cscatCategory(?int $parentId = null): Taxonomy
{
    return Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'parent_id' => $parentId]);
}

function cscatPost(int $categoryId): Entry
{
    $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => User::factory()->create()->id]);
    $post->assignCategories([$categoryId]);

    return $post->fresh();
}

function cscatSection(string $key, int $taxonomyId, int $sortOrder = 1): ContentSection
{
    return ContentSection::query()->create(['key' => $key, 'label' => ucfirst($key), 'entry_type' => 'post', 'taxonomy_id' => $taxonomyId, 'permission_prefix' => 'entries', 'sort_order' => $sortOrder]);
}

function cscatCover(ContentSection $section, int $taxonomyId): void
{
    ContentSectionCategory::query()->create(['site_id' => $section->site_id, 'content_section_id' => $section->id, 'taxonomy_id' => $taxonomyId]);
}

it('keeps a section\'s first category in the table, moving it with the section and listing it first', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = cscatCategory();
        $other = cscatCategory();
        $jobs = cscatCategory();
        $section = cscatSection('posts', $news->id);
        expect($section->categoryIds())->toBe([$news->id]);

        cscatCover($section, $jobs->id);
        expect($section->categoryIds())->toBe([$news->id, $jobs->id]);

        // a new first category takes the old one's place; the others stay
        $section->update(['taxonomy_id' => $other->id]);
        expect($section->categoryIds())->toBe([$other->id, $jobs->id])
            ->and(app(ContentSections::class)->taxonomyIdOf('posts'))->toBe($other->id);

        // the section's rows go with it
        $section->delete();
        expect(ContentSectionCategory::query()->count())->toBe(0);
    });
});

it('files posts of every category a section covers, and of the categories below them, under it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = cscatCategory();
        $company = cscatCategory($news->id);
        $jobs = cscatCategory();
        $engineers = cscatCategory($jobs->id);
        $alone = cscatCategory();
        $section = cscatSection('posts', $news->id);
        cscatCover($section, $jobs->id);
        $resolver = app(SectionResolver::class);

        expect($resolver->forEntry(cscatPost($company->id)))->toBe(['key' => 'posts', 'type' => 'posts'])
            ->and($resolver->forEntry(cscatPost($jobs->id)))->toBe(['key' => 'posts', 'type' => 'posts'])
            ->and($resolver->forEntry(cscatPost($engineers->id)))->toBe(['key' => 'posts', 'type' => 'posts'])
            ->and($resolver->forEntry(cscatPost($alone->id)))->toBeNull()
            ->and(app(ContentSections::class)->boundToEach([$news->id, $jobs->id, $company->id]))->toBe([
                $news->id => ['key' => 'posts', 'entry_type' => 'post'],
                $jobs->id => ['key' => 'posts', 'entry_type' => 'post'],
            ]);
    });
});

it('gives a post to the section of the nearest covered category up its chain', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = cscatCategory();
        $jobs = cscatCategory($news->id);
        $engineers = cscatCategory($jobs->id);
        cscatSection('posts', $news->id);
        cscatSection('careers', $jobs->id, 2);
        $resolver = app(SectionResolver::class);

        expect($resolver->forEntry(cscatPost($engineers->id))['key'] ?? null)->toBe('careers')
            ->and($resolver->forEntry(cscatPost($jobs->id))['key'] ?? null)->toBe('careers')
            ->and($resolver->forEntry(cscatPost($news->id))['key'] ?? null)->toBe('posts');
    });
});

it('lets a category belong to one section at most', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = cscatCategory();
        $jobs = cscatCategory();
        $posts = cscatSection('posts', $news->id);
        cscatCover($posts, $jobs->id);
        $careers = cscatSection('careers', cscatCategory()->id, 2);

        expect(fn () => DB::transaction(fn () => cscatCover($careers, $jobs->id)))->toThrow(QueryException::class, 'content_section_categories_site_taxonomy_unique');
        expect(app(ContentSections::class)->boundTo($jobs->id))->toBe(['key' => 'posts', 'entry_type' => 'post']);
    });
});

it('refuses a section whose first category another section covers, writing nothing, and lets one take its own', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = cscatCategory();
        $jobs = cscatCategory();
        $posts = cscatSection('posts', $news->id);
        cscatCover($posts, $jobs->id);

        // a new section on «posts»'s second category, and one moved onto its first: both refused
        expect(fn () => cscatSection('careers', $jobs->id, 2))->toThrow(DomainException::class, 'already belongs to another section');
        expect(ContentSection::query()->where('key', 'careers')->exists())->toBeFalse();
        $careers = cscatSection('careers', cscatCategory()->id, 2);
        expect(fn () => $careers->update(['taxonomy_id' => $news->id]))->toThrow(DomainException::class, 'already belongs to another section');
        expect($careers->fresh()?->taxonomy_id)->not->toBe($news->id)
            ->and(ContentSectionCategory::query()->where('taxonomy_id', $news->id)->value('content_section_id'))->toBe($posts->id);

        // a section making one of its own categories its first keeps one row for it
        $posts->update(['taxonomy_id' => $jobs->id]);
        expect($posts->categoryIds())->toBe([$jobs->id])
            ->and(ContentSectionCategory::query()->where('content_section_id', $posts->id)->count())->toBe(1);
    });
});

it('fails closed: no rows without a site, none of another site\'s, and none written for another site', function (): void {
    $tenancy = app(Tenancy::class);
    [$section, $jobs] = $tenancy->enter(1, function (): array {
        $section = cscatSection('posts', cscatCategory()->id);
        $jobs = cscatCategory();
        cscatCover($section, $jobs->id);

        return [$section, $jobs];
    });
    expect(DB::connection('pgsql_admin')->table('content_section_categories')->where('site_id', 1)->count())->toBe(2);

    // the app role with no tenant context reads nothing (RLS FORCE, the policy's NULL site)
    expect(DB::transaction(fn (): int => (int) DB::selectOne('select count(*) as n from content_section_categories')->n))->toBe(0);

    $tenancy->enter(2, function () use ($section, $jobs): void {
        expect(DB::table('content_section_categories')->count())->toBe(0)
            ->and(app(ContentSections::class)->boundToEach([$jobs->id]))->toBe([])
            ->and(app(SectionResolver::class)->forEntry(cscatPost(cscatCategory()->id)))->toBeNull();
        expect(fn () => DB::transaction(fn () => DB::table('content_section_categories')->insert([
            'site_id' => 1, 'content_section_id' => $section->id, 'taxonomy_id' => $jobs->id,
        ])))->toThrow(QueryException::class);
    });

    expect(DB::connection('pgsql_admin')->table('content_section_categories')->count())->toBe(2);
});

it('has RLS forced and a site policy on the table', function (): void {
    $row = DB::connection('pgsql_admin')->selectOne("select relrowsecurity and relforcerowsecurity as forced from pg_class where relname = 'content_section_categories'");
    $policies = DB::connection('pgsql_admin')->select("select polname from pg_policy where polrelid = 'content_section_categories'::regclass");

    expect($row->forced)->toBeTrue()
        ->and(array_column($policies, 'polname'))->toBe(['content_section_categories_site_isolation']);
});

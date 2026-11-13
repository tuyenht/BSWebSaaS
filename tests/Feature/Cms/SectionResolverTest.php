<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Facades\DB;

/*
 * SectionResolver derives a post's permalink section (key + type) from its PRIMARY category
 * (pivot position 0) matched to a ContentSection.taxonomy_id, walking the category parent chain so
 * a post filed under a sub-category still resolves. This is the single seam a future
 * content_section_id FK would replace.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A post_cat taxonomy (+ optional parent) in the current site. */
function srTaxonomy(?int $parentId = null): Taxonomy
{
    return Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'parent_id' => $parentId]);
}

/** A draft post assigned the given primary category. */
function srPost(int $categoryId): Entry
{
    $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => User::factory()->create()->id]);
    $post->assignCategories([$categoryId]);

    return $post->fresh();
}

it('resolves a post to the section bound to its primary category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $cat = srTaxonomy();
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin', 'entry_type' => 'post', 'taxonomy_id' => $cat->id]);

        expect(app(SectionResolver::class)->forEntry(srPost($cat->id)))->toBe(['key' => 'posts', 'type' => 'posts']);
    });
});

it('walks the parent chain when the primary category is a sub-category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $parent = srTaxonomy();
        $child = srTaxonomy($parent->id);
        // Section bound to the PARENT; the post's primary is the child → resolves via the ancestor.
        ContentSection::query()->create(['key' => 'products', 'label' => 'SP', 'entry_type' => 'post', 'taxonomy_id' => $parent->id]);

        expect(app(SectionResolver::class)->forEntry(srPost($child->id)))->toBe(['key' => 'products', 'type' => 'posts']);
    });
});

it('returns null when the post has no category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => User::factory()->create()->id]);

        expect(app(SectionResolver::class)->forEntry($post->fresh()))->toBeNull();
    });
});

it('returns null when no section binds the category chain', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $cat = srTaxonomy();

        expect(app(SectionResolver::class)->forEntry(srPost($cat->id)))->toBeNull();
    });
});

it('keeps the memo isolated per site — a resolver never leaks a section across sites', function (): void {
    $resolver = app(SectionResolver::class);
    $tenancy = app(Tenancy::class);

    $tenancy->enter(1, function () use ($resolver): void {
        $cat = srTaxonomy();
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin', 'entry_type' => 'post', 'taxonomy_id' => $cat->id]);
        expect($resolver->forEntry(srPost($cat->id)))->toBe(['key' => 'posts', 'type' => 'posts']);
    });

    // Site 2: a fresh category with no section → null (RLS hides site 1's rows; memo keyed by site).
    $tenancy->enter(2, function () use ($resolver): void {
        expect($resolver->forEntry(srPost(srTaxonomy()->id)))->toBeNull();
    });
});

/** Overwrite a category's stored path / parent on the test's own connection (the model would rewrite them). */
function srRaw(int $id, array $set): void
{
    DB::table('taxonomies')->where('id', $id)->update($set);
}

it('walks the parent chain when the stored path disagrees with parent_id', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $parent = srTaxonomy();
        $child = srTaxonomy($parent->id);
        ContentSection::query()->create(['key' => 'products', 'label' => 'SP', 'entry_type' => 'post', 'taxonomy_id' => $parent->id]);
        srRaw($child->id, ['path' => '999']);

        $resolver = app(SectionResolver::class);

        expect($resolver->forCategory(Taxonomy::query()->findOrFail($child->id)))->toBe(['key' => 'products', 'type' => 'posts']);
    });
});

it('stops a parent cycle instead of walking it forever', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = srTaxonomy();
        $b = srTaxonomy($a->id);
        srRaw($a->id, ['parent_id' => $b->id, 'path' => '999']);
        srRaw($b->id, ['path' => '998']);

        expect(app(SectionResolver::class)->forCategory(Taxonomy::query()->findOrFail($b->id)))->toBeNull();
    });
});

it('keeps a post whose category path is unusable in the SQL filter, and drops a usable one on the wrong stage', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $root = srTaxonomy();
        $stale = srTaxonomy($root->id);
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin', 'entry_type' => 'post', 'taxonomy_id' => $root->id]);
        srRaw($root->id, ['path' => (string) $root->id]);
        srRaw($stale->id, ['path' => '999']);
        $kept = srPost($stale->id);
        $wrongStage = srPost($root->id);
        DB::table('entries')->whereIn('id', [$kept->id, $wrongStage->id])->update(['workflow_state' => 'draft']);

        $query = Entry::query();
        app(SectionResolver::class)->whereOnSectionStage($query, ['posts' => ['pending']]);

        expect($query->pluck('id')->all())->toBe([$kept->id]);
    });
});

it('reads a loaded, agreeing category without another query, and a changed one fresh', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $root = srTaxonomy();
        $loaded = Taxonomy::query()->findOrFail($root->id);
        $resolver = app(SectionResolver::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $resolver->forCategory($loaded);
        $trusted = count(DB::getQueryLog());

        $sub = srTaxonomy($root->id);
        $dirty = Taxonomy::query()->findOrFail($sub->id);
        $dirty->path = '999';
        DB::flushQueryLog();
        $resolver->flush();
        $resolver->forCategory($dirty);
        $dirtyQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($dirtyQueries)->toBeGreaterThan($trusted);
    });
});

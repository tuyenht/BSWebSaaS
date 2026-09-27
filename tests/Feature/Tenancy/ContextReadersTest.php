<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\ContentSections;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteDirectory;
use App\Modules\Tenancy\Context\SiteSettings;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;

/*
 * The Tenancy\Context readers feature modules use instead of Tenancy\Models: ContentSections,
 * SiteSettings and SiteDirectory::nameOfCurrent. Each answers for the CURRENT site only — the same
 * rows written on the other site never show — and SiteSettings refuses any group not opened on
 * purpose, so credential-holding groups stay out of feature modules.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A post category with a section of $entryType built on it (inside the tenant context). */
function sectionOn(string $key, ?string $entryType, int $sortOrder = 1): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);

    ContentSection::query()->create([
        'key' => $key,
        'label' => ucfirst($key),
        'entry_type' => $entryType,
        'taxonomy_id' => $category->id,
        'sort_order' => $sortOrder,
    ]);

    return $category;
}

it('lists the post sections of the current site only, in display order', function (): void {
    app(Tenancy::class)->enter(2, function (): void {
        sectionOn('other-site-news', 'post');
    });

    app(Tenancy::class)->enter(1, function (): void {
        sectionOn('projects', 'post', 2);
        sectionOn('news', 'post', 1);
        sectionOn('about', 'page');
        ContentSection::query()->create(['key' => 'untyped', 'label' => 'Untyped', 'sort_order' => 0]);

        expect(app(ContentSections::class)->postSectionKeys())->toBe(['news', 'projects']);
    });
});

it('finds the section built on a category, and which categories carry one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = sectionOn('news', 'post');
        $about = sectionOn('about', 'page');
        $plain = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $sections = app(ContentSections::class);

        expect($sections->boundTo($news->id))->toBe(['key' => 'news', 'entry_type' => 'post'])
            ->and($sections->boundTo($about->id))->toBe(['key' => 'about', 'entry_type' => 'page'])
            ->and($sections->boundTo($plain->id))->toBeNull()
            ->and($sections->boundAmong([$news->id, $plain->id]))->toBe([$news->id])
            ->and($sections->boundAmong([]))->toBe([])
            ->and($sections->taxonomyIdOf('news'))->toBe($news->id)
            ->and($sections->taxonomyIdOf('missing'))->toBeNull();
    });
});

it('picks the first section by display order, then id, when several share a category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $shared = sectionOn('zeta', 'post', 5);
        ContentSection::query()->create(['key' => 'beta', 'label' => 'Beta', 'entry_type' => 'page', 'taxonomy_id' => $shared->id, 'sort_order' => 5]);
        ContentSection::query()->create(['key' => 'alpha', 'label' => 'Alpha', 'entry_type' => 'post', 'taxonomy_id' => $shared->id, 'sort_order' => 9]);
        sectionOn('tied-later', 'post', 5);

        $sections = app(ContentSections::class);
        expect($sections->boundTo($shared->id))->toBe(['key' => 'zeta', 'entry_type' => 'post'])
            ->and($sections->postSectionKeys())->toBe(['zeta', 'tied-later', 'alpha']);
    });
});

it('answers nothing outside a tenant context', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        sectionOn('news', 'post');
        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'commerce']]);
    });

    expect(app(ContentSections::class)->postSectionKeys())->toBe([])
        ->and(app(ContentSections::class)->taxonomyIdOf('news'))->toBeNull()
        ->and(app(SiteSettings::class)->value('catalog', 'mode'))->toBeNull();
});

it('does not see a section of another site', function (): void {
    $otherCategoryId = app(Tenancy::class)->enter(2, fn (): int => sectionOn('products', 'post')->id);

    app(Tenancy::class)->enter(1, function () use ($otherCategoryId): void {
        $sections = app(ContentSections::class);

        expect($sections->taxonomyIdOf('products'))->toBeNull()
            ->and($sections->boundTo($otherCategoryId))->toBeNull()
            ->and($sections->boundAmong([$otherCategoryId]))->toBe([]);
    });
});

it('reads a readable setting of the current site, null when absent', function (): void {
    app(Tenancy::class)->enter(2, function (): void {
        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'commerce']]);
    });

    app(Tenancy::class)->enter(1, function (): void {
        $settings = app(SiteSettings::class);
        expect($settings->value('catalog', 'mode'))->toBeNull();

        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => ['value' => 'brochure']]);
        expect($settings->value('catalog', 'mode'))->toBe(['value' => 'brochure']);
    });
});

it('reads a setting whose JSON is a bare scalar as absent', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        SiteSetting::query()->create(['group_name' => 'catalog', 'key' => 'mode', 'value' => 'commerce']);

        expect(app(SiteSettings::class)->value('catalog', 'mode'))->toBeNull();
    });
});

it('refuses setting groups not opened to feature modules', function (string $group): void {
    app(Tenancy::class)->enter(1, function () use ($group): void {
        app(SiteSettings::class)->value($group, 'config');
    });
})->with(['email', 'oauth_social', 'general'])->throws(LogicException::class);

it('gives the stored name of the current site, untrimmed', function (): void {
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['name' => '  Bảo Sơn B ']);

    expect(app(Tenancy::class)->enter(1, fn (): string => app(SiteDirectory::class)->nameOfCurrent(app(CurrentSite::class))))->toBe('A')
        ->and(app(Tenancy::class)->enter(2, fn (): string => app(SiteDirectory::class)->nameOfCurrent(app(CurrentSite::class))))->toBe('  Bảo Sơn B ');
});

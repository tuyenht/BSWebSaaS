<?php

use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * A category's short card label (taxonomy_translations.card_label): the product photo cards and the
 * post cards show it in place of the category's name, per language, and fall back to the name when
 * it is empty. Breadcrumbs, the category's own page and its title keep the name. The label comes
 * with the translation rows the cards already load, so it costs no query.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** A catalog with the "Đồ gá" category, one product in it, and a page of photo cards; returns the site and the category. */
function labelSite(): array
{
    $siteId = StorefrontCards::site();
    $category = app(Tenancy::class)->enter($siteId, function (): Taxonomy {
        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
        StorefrontCards::catalog($catalog);
        $jigs = StorefrontCards::category('Đồ gá', 'do-ga', $catalog);
        StorefrontCards::product('Jig kiểm VF7', 'jig-vf7', $jigs, StorefrontCards::media('card', 960, 720), CarbonImmutable::parse('2026-10-02 08:00', 'UTC'));
        StorefrontCards::page('/khoi', 'Các khối', [['type' => 'product_grid', 'props' => ['heading' => 'Sản phẩm', 'variant' => 'overlay']]]);

        return $jigs;
    });

    return [$siteId, $category];
}

function setCardLabel(int $siteId, Taxonomy $category, ?string $label): void
{
    app(Tenancy::class)->enter($siteId, fn () => TaxonomyTranslation::query()->where('taxonomy_id', $category->id)->where('locale', 'vi')->update(['card_label' => $label]));
}

it('shows the category name on a product card while the category has no card label', function (): void {
    labelSite();

    expect($this->get('http://mau.platform.test/khoi')->assertOk()->getContent())->toContain('<p class="sf-card__label">Đồ gá</p>');
});

it('shows the card label on the product card, and keeps the name on the category page and in the breadcrumb', function (): void {
    [$siteId, $category] = labelSite();
    setCardLabel($siteId, $category, 'Jig gá');

    $cards = $this->get('http://mau.platform.test/khoi')->assertOk()->getContent();
    expect($cards)->toContain('<p class="sf-card__label">Jig gá</p>')
        ->and($cards)->not->toContain('<p class="sf-card__label">Đồ gá</p>');

    $page = $this->get('http://mau.platform.test/do-ga')->assertOk()->getContent();
    expect($page)->toContain('Đồ gá')->and($page)->not->toContain('Jig gá');

    $product = $this->get('http://mau.platform.test/jig-vf7')->assertOk()->getContent();
    expect($product)->toContain('Đồ gá')->and($product)->not->toContain('Jig gá');
});

it('falls back to the name for a label that is only spaces', function (): void {
    [$siteId, $category] = labelSite();
    setCardLabel($siteId, $category, '   ');

    expect($this->get('http://mau.platform.test/khoi')->assertOk()->getContent())->toContain('<p class="sf-card__label">Đồ gá</p>');
});

it('reads the card label with no extra query', function (): void {
    [$siteId, $category] = labelSite();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('http://mau.platform.test/khoi')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $count();   // warm the per-process caches first
    $without = $count();
    setCardLabel($siteId, $category, 'Jig gá');
    $with = $count();

    expect($with)->toBe($without);
});

it('keeps one label per language and falls back to that language\'s own name', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        app(TaxonomyPaths::class)->refreshBranch($category->id);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga', 'card_label' => 'Jig gá']);
        $category->translations()->create(['locale' => 'en', 'name' => 'Fixtures', 'slug' => 'fixtures']);
        $category = $category->fresh(['translations']);

        expect($category->translationFor('vi')?->cardLabel())->toBe('Jig gá')
            ->and($category->translationFor('en')?->cardLabel())->toBe('Fixtures');
    });
});

it('shows the card label of a post\'s sector on the post cards and the project cards', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $projects = StorefrontCards::category('Dự án', 'du-an');
        $post = StorefrontCards::post($projects, 'Mông Dương 1', 'mong-duong-1', null, [], 'Bảo ôn nhà máy.');
        $sector = Taxonomy::query()->create(['vocabulary' => 'sector', 'parent_id' => $projects->id, 'position' => 0]);
        $sector->translations()->create(['locale' => 'vi', 'name' => 'Nhiệt điện', 'slug' => 'nhiet-dien', 'card_label' => 'Điện']);
        $post->taxonomies()->attach($sector->id, ['position' => Entry::FACET_POSITION_BASE, 'site_id' => $post->site_id]);

        $grid = app(EntryGridComponent::class);
        expect($grid->data(['taxonomy_slug' => 'du-an', 'locale' => 'vi'])['items'][0]['sector'])->toBe('Điện')
            ->and($grid->data(['taxonomy_slug' => 'du-an', 'variant' => 'news-cards', 'locale' => 'vi'])['items'][0]['label'])->toBe('Điện');
    });
});

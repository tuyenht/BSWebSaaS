<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Storefront\Support\ArticleData;
use App\Modules\Storefront\Support\PostDetail;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * A post's own byline (entry_translations.author_name): free text naming a team or department, per
 * language, linked to no staff account. The news look prints it under the title and the Article
 * data names it as an Organization that is part of the business; without one, both name the
 * business as before. Other looks print no author line.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** A site named "Cơ khí Mẫu" (unless $brand is false) with one news post, whose vi byline is $author. */
function bylineSite(?string $author, bool $brand = true): int
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($author, $brand): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Vật liệu khuôn mới', 'vat-lieu-khuon', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
        EntryTranslation::query()->where('entry_id', $post->id)->update(['author_name' => $author]);
        if ($brand) {
            SiteProfile::query()->updateOrCreate([], ['brand_name' => 'Cơ khí Mẫu']);
        }
    });

    return $siteId;
}

function bylinePage(): string
{
    return test()->get('http://mau.platform.test/tin-tuc/vat-lieu-khuon')->assertOk()->getContent();
}

it('prints the post\'s own byline under the title and names it as part of the business', function (): void {
    bylineSite('Phòng Kỹ thuật');
    $html = bylinePage();

    expect($html)->toMatch('#<span class="post-band__author"><span class="post-band__avatar" aria-hidden="true">[^<]*</span>Phòng Kỹ thuật</span>#')
        ->and(ldNode($html, 'Article')['author'])->toBe([
            '@type' => 'Organization',
            'name' => 'Phòng Kỹ thuật',
            'parentOrganization' => ['@type' => 'Organization', '@id' => 'http://mau.platform.test/#organization', 'name' => 'Cơ khí Mẫu'],
        ])
        // The business stays the publisher.
        ->and(ldNode($html, 'Article')['publisher'])->toBe(['@type' => 'Organization', '@id' => 'http://mau.platform.test/#organization', 'name' => 'Cơ khí Mẫu']);
});

it('names the business as the author while the post has no byline', function (?string $empty): void {
    bylineSite($empty);
    $html = bylinePage();

    expect($html)->toMatch('#<span class="post-band__author"><span class="post-band__avatar" aria-hidden="true">[^<]*</span>Cơ khí Mẫu</span>#')
        ->and(ldNode($html, 'Article')['author'])->toBe(['@type' => 'Organization', '@id' => 'http://mau.platform.test/#organization', 'name' => 'Cơ khí Mẫu']);
})->with(['none' => [null], 'only spaces' => ['   ']]);

it('names the byline alone when the site names no business', function (): void {
    bylineSite('Phòng Kỹ thuật', brand: false);
    $ld = ldNode(bylinePage(), 'Article');

    expect($ld['author'])->toBe(['@type' => 'Organization', 'name' => 'Phòng Kỹ thuật'])
        ->and($ld)->not->toHaveKey('publisher');
});

it('prints no author line and no Article author on the job look, byline or not', function (): void {
    $siteId = bylineSite('Phòng Kỹ thuật');
    app(Tenancy::class)->enter($siteId, fn () => DB::table('entries')->where('type', 'post')->update(['template_override' => 'job']));
    $html = bylinePage();

    expect($html)->not->toContain('post-band__author')
        ->and($html)->not->toContain('Phòng Kỹ thuật')
        ->and(ldNode($html, 'Article'))->toBeNull();
});

it('prints no author line on the capability look and names the business as the Article author, byline or not', function (): void {
    $siteId = bylineSite('Phòng Kỹ thuật');
    app(Tenancy::class)->enter($siteId, fn () => DB::table('entries')->where('type', 'post')->update(['template_override' => 'capability']));
    $html = bylinePage();

    expect($html)->toContain('post-page--capability')
        ->and($html)->not->toContain('post-band__author')
        ->and($html)->not->toContain('Phòng Kỹ thuật')
        ->and(ldNode($html, 'Article')['author'])->toBe(['@type' => 'Organization', '@id' => 'http://mau.platform.test/#organization', 'name' => 'Cơ khí Mẫu']);
});

it('reads the byline per language: a language without one names the business', function (): void {
    $siteId = bylineSite('Phòng Kỹ thuật');
    app(Tenancy::class)->enter($siteId, function (): void {
        $vi = EntryTranslation::query()->where('slug', 'vat-lieu-khuon')->sole();
        $post = Entry::query()->findOrFail($vi->entry_id);
        $en = $post->translations()->create(['locale' => 'en', 'title' => 'New mould steel', 'slug' => 'new-mould-steel', 'status' => 'published', 'body' => ['blocks' => []]]);
        $brand = ['name' => 'Cơ khí Mẫu'];
        $business = ['@type' => 'Organization', 'name' => 'Cơ khí Mẫu', 'url' => 'http://mau.platform.test/en'];

        expect(app(PostDetail::class)->band($post, $vi, 'vi')['author'])->toBe('Phòng Kỹ thuật')
            ->and(app(PostDetail::class)->band($post, $en, 'en')['author'])->toBe('')
            ->and(ArticleData::ld($post, $en, 'http://mau.platform.test/en/new-mould-steel', 'http://mau.platform.test/en', 'en', null, $brand)['author'])->toBe($business)
            ->and(ArticleData::ld($post, $vi, 'http://mau.platform.test/vat-lieu-khuon', 'http://mau.platform.test/en', 'vi', null, $brand)['author']['name'])->toBe('Phòng Kỹ thuật');
    });
});

it('leaves the content date alone when only the byline changes', function (): void {
    $siteId = bylineSite(null);
    app(Tenancy::class)->enter($siteId, function (): void {
        $translation = EntryTranslation::query()->where('slug', 'vat-lieu-khuon')->sole();
        $mark = $translation->content_changed_at?->toIso8601String();
        expect($mark)->not->toBeNull();

        $this->travel(3)->days();
        $translation->update(['author_name' => 'Phòng Kỹ thuật']);

        expect($translation->fresh()->content_changed_at?->toIso8601String())->toBe($mark)
            ->and($translation->fresh()->author_name)->toBe('Phòng Kỹ thuật');
    });
});

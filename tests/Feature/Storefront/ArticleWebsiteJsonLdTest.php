<?php

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * The head's Article data on a post (headline, the dates the post shows, the business as author and
 * publisher, never a staff name) and the WebSite data on the home page (the site's name in search
 * results). Both come from what the page already loaded.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** A site with one news post published on 1 October and, unless $brand is false, a business name and logo. */
function articleSite(bool $brand = true): int
{
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($brand): void {
        $post = StorefrontCards::post(StorefrontCards::news(), 'Gia công CNC chính xác', 'gia-cong-cnc', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), [], "Phay, tiện\n chính xác.");
        EntryTranslation::query()->where('entry_id', $post->id)->update(['updated_at' => '2026-10-05 09:30:00+00', 'content_changed_at' => '2026-10-05 09:30:00+00']);
        if ($brand) {
            SiteProfile::query()->updateOrCreate([], ['brand_name' => 'Cơ khí Mẫu']);
        }
    });

    return $siteId;
}

/**
 * The structured data of $type printed in the page head, or null when the page carries none.
 *
 * @return array<string, mixed>|null
 */
function headLd(string $html, string $type): ?array
{
    return ldNode($html, $type);
}

it('describes a post as an Article with its dates and the business behind it', function (): void {
    articleSite();

    $ld = headLd($this->get('http://mau.platform.test/tin-tuc/gia-cong-cnc')->assertOk()->getContent(), 'Article');

    expect($ld)->toBe([
        '@type' => 'Article',
        'headline' => 'Gia công CNC chính xác',
        'description' => 'Phay, tiện chính xác.',
        'datePublished' => '2026-10-01T08:00:00+00:00',
        'dateModified' => '2026-10-05T09:30:00+00:00',
        'inLanguage' => 'vi',
        'mainEntityOfPage' => 'http://mau.platform.test/tin-tuc/gia-cong-cnc',
        // The business is named by its Organization id (described in full on the home page).
        'author' => ['@id' => 'http://mau.platform.test/#organization', 'name' => 'Cơ khí Mẫu'],
        'publisher' => ['@id' => 'http://mau.platform.test/#organization'],
    ]);
});

it('never dates the change before the publish, and prefers the Google description over the excerpt', function (): void {
    $siteId = articleSite();
    app(Tenancy::class)->enter($siteId, function (): void {
        EntryTranslation::query()->where('slug', 'gia-cong-cnc')->update([
            'updated_at' => '2026-09-20 00:00:00+00', 'content_changed_at' => '2026-09-20 00:00:00+00', 'seo_description' => 'Mô tả cho Google.',
        ]);
    });

    $ld = headLd($this->get('http://mau.platform.test/tin-tuc/gia-cong-cnc')->assertOk()->getContent(), 'Article');

    expect($ld['dateModified'])->toBe('2026-10-01T08:00:00+00:00')
        ->and($ld['description'])->toBe('Mô tả cho Google.');
});

it('leaves the author and publisher out when the site names no business', function (): void {
    articleSite(brand: false);

    $ld = headLd($this->get('http://mau.platform.test/tin-tuc/gia-cong-cnc')->assertOk()->getContent(), 'Article');

    expect($ld)->not->toBeNull()
        ->and($ld)->not->toHaveKey('author')
        ->and($ld)->not->toHaveKey('publisher');
});

it('names the website on the home page only', function (): void {
    articleSite();

    $home = $this->get('http://mau.platform.test/')->assertOk()->getContent();
    $contact = $this->get('http://mau.platform.test/lien-he')->assertOk()->getContent();

    expect(headLd($home, 'WebSite'))->toBe([
        '@type' => 'WebSite', '@id' => 'http://mau.platform.test/#website', 'name' => 'Cơ khí Mẫu', 'url' => 'http://mau.platform.test', 'inLanguage' => 'vi',
        'publisher' => ['@id' => 'http://mau.platform.test/#organization'],
    ])
        ->and(headLd($contact, 'WebSite'))->toBeNull()
        ->and(headLd($home, 'Article'))->toBeNull();
});

it('prints no WebSite data when the site names no business', function (): void {
    articleSite(brand: false);

    expect(headLd($this->get('http://mau.platform.test/')->assertOk()->getContent(), 'WebSite'))->toBeNull();
});

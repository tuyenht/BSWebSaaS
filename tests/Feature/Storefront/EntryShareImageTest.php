<?php

use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * The share picture an editor picks for one language of a post (entry_translations.og_image_id) is the
 * og:image of that page; without one the cover stands in, then the site's brand share image. A picked
 * file in the Trash (or another website's) is ignored. It rides the read of the translations, so the
 * page costs no extra query.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** @return array{site: int, post: int} a post with a cover and a site brand share image */
function shareSite(bool $withCover = true): array
{
    $siteId = StorefrontCards::site();
    $post = app(Tenancy::class)->enter($siteId, function () use ($withCover): int {
        $brand = StorefrontCards::media('marca-chia-se', 2400, 1260);
        SiteProfile::query()->firstOrFail()->update(['og_image_media_id' => $brand->id]);
        $meta = $withCover ? ['image_url' => StorefrontCards::media('anh-bia')->storage_key] : [];

        return StorefrontCards::post(StorefrontCards::news(), 'Bài chia sẻ', 'bai-chia-se', null, $meta)->id;
    });

    return ['site' => $siteId, 'post' => $post];
}

function pickShare(int $siteId, int $postId, int $mediaId): void
{
    app(Tenancy::class)->enter($siteId, fn () => DB::table('entry_translations')->where('entry_id', $postId)->update(['og_image_id' => $mediaId]));
}

function shareHead(): string
{
    return (string) test()->get('http://mau.platform.test/tin-tuc/bai-chia-se')->assertOk()->getContent();
}

function shareQueries(): int
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    shareHead();

    return $count;
}

it('uses the share image picked for the language as og:image, in the article data and the sitemap', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    $picked = app(Tenancy::class)->enter($site, fn (): Media => StorefrontCards::media('anh-chon-rieng', 1600, 900));
    pickShare($site, $post, $picked->id);

    $html = shareHead();
    expect($html)->toMatch('/property="og:image" content="[^"]*anh-chon-rieng[^"]*w=1280/')
        ->and($html)->not->toMatch('/property="og:image" content="[^"]*anh-bia/')
        ->and($html)->toMatch('/"image":[^}]*anh-chon-rieng/');
    expect((string) test()->get('http://mau.platform.test/sitemap.xml')->getContent())->toContain('anh-chon-rieng');
});

it('falls back to the cover without a picked share image', function (): void {
    shareSite();

    expect(shareHead())->toMatch('/property="og:image" content="[^"]*anh-bia[^"]*w=1280/');
});

it('falls back to the site brand image with neither a share image nor a cover', function (): void {
    shareSite(withCover: false);

    expect(shareHead())->toMatch('/property="og:image" content="[^"]*marca-chia-se/');
});

it('ignores a picked share image of another website', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    $other = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
    $foreign = app(Tenancy::class)->enter($other, fn (): Media => StorefrontCards::media('anh-nguoi-khac', 1600, 900));
    // the database refuses the pairing outright when it can; either way the page never draws it
    try {
        pickShare($site, $post, $foreign->id);
    } catch (Throwable) {
        // refused by the same-site key: nothing to draw
    }

    expect(shareHead())->not->toContain('anh-nguoi-khac')->toMatch('/property="og:image" content="[^"]*anh-bia/');
});

it('ignores a picked share image that is in the Trash', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    $trashed = app(Tenancy::class)->enter($site, function (): Media {
        $media = StorefrontCards::media('anh-da-bo', 1600, 900);
        $media->delete();

        return $media;
    });
    pickShare($site, $post, $trashed->id);

    expect(shareHead())->not->toContain('anh-da-bo')->toMatch('/property="og:image" content="[^"]*anh-bia/');
});

it('costs no more queries with a share image picked than without', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    shareHead(); // warm caches
    $without = shareQueries();
    $picked = app(Tenancy::class)->enter($site, fn (): Media => StorefrontCards::media('anh-chon-rieng', 1600, 900));
    pickShare($site, $post, $picked->id);
    shareHead();
    $with = shareQueries();

    expect($with)->toBe($without);
});

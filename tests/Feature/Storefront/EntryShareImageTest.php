<?php

use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\EntryShareImage;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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

/** The same pairing the database's same-site key refuses, planted with the key checks switched off (privileged role only). */
function pickShareUnchecked(int $postId, int $mediaId): void
{
    DB::connection('pgsql_admin')->transaction(function ($admin) use ($postId, $mediaId): void {
        $admin->statement('set local session_replication_role = replica');
        $admin->table('entry_translations')->where('entry_id', $postId)->update(['og_image_id' => $mediaId]);
    });
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

it('leaves the sitemap lastmod alone when only the share image changes', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    app(Tenancy::class)->enter($site, function () use ($post): void {
        DB::table('entry_translations')->where('entry_id', $post)->update(['content_changed_at' => '2026-01-02 03:04:05+00', 'updated_at' => '2026-01-02 03:04:05+00']);
        DB::table('routes')->where('entry_id', $post)->update(['updated_at' => '2026-01-02 03:04:05+00']);
        DB::table('entries')->where('id', $post)->update(['published_at' => '2026-01-01 00:00:00+00']);
    });
    $lastmod = fn (): CarbonImmutable => CarbonImmutable::parse(preg_match('#/tin-tuc/bai-chia-se</loc><lastmod>([^<]+)</lastmod>#', (string) test()->get('http://mau.platform.test/sitemap.xml')->getContent(), $m) === 1 ? $m[1] : throw new RuntimeException('post missing from the sitemap'));
    expect($lastmod()->toDateString())->toBe('2026-01-02');

    $picked = app(Tenancy::class)->enter($site, fn (): Media => StorefrontCards::media('anh-chon-rieng', 1600, 900));
    app(Tenancy::class)->enter($site, fn () => EntryTranslation::query()->where('entry_id', $post)->firstOrFail()->update(['og_image_id' => $picked->id]));

    expect($lastmod()->toDateString())->toBe('2026-01-02');
});

it('falls back to the cover without a picked share image', function (): void {
    shareSite();

    expect(shareHead())->toMatch('/property="og:image" content="[^"]*anh-bia[^"]*w=1280/');
    expect((string) test()->get('http://mau.platform.test/sitemap.xml')->getContent())->toContain('anh-bia');
});

it('falls back to the site brand image with neither a share image nor a cover', function (): void {
    shareSite(withCover: false);

    expect(shareHead())->toMatch('/property="og:image" content="[^"]*marca-chia-se/');
});

it('ignores a picked share image of another website', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    $other = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
    $foreign = app(Tenancy::class)->enter($other, fn (): Media => StorefrontCards::media('anh-nguoi-khac', 1600, 900));

    // the same-site key (entry_translations_og_image_id_same_site_foreign) refuses the pairing outright
    expect(fn () => pickShare($site, $post, $foreign->id))->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('23503'));

    // with the key checks off the pairing exists: the page and the sitemap must still never draw it (site_id match)
    try {
        pickShareUnchecked($post, $foreign->id);
        expect(DB::connection('pgsql_admin')->table('entry_translations')->where('entry_id', $post)->value('og_image_id'))->toBe($foreign->id);

        expect(shareHead())->not->toContain('anh-nguoi-khac')->toMatch('/property="og:image" content="[^"]*anh-bia/');
        expect((string) test()->get('http://mau.platform.test/sitemap.xml')->getContent())->not->toContain('anh-nguoi-khac')->toContain('anh-bia');
    } finally {
        // the planted pairing breaks the key: leave the shared database valid even when an expectation above fails
        DB::connection('pgsql_admin')->table('entry_translations')->where('entry_id', $post)->update(['og_image_id' => null]);
    }
});

it('matches the share image to the page by website in the lookup itself, not only by the scope and row security around it', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    $other = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
    $foreign = app(Tenancy::class)->enter($other, fn (): Media => StorefrontCards::media('anh-nguoi-khac', 1600, 900));
    $own = app(Tenancy::class)->enter($site, fn (): Media => StorefrontCards::media('anh-cua-minh', 1600, 900));

    // read as the privileged role with every scope off: only the lookup's own site_id match is left to decide
    $read = fn (): ?string => EntryTranslation::on('pgsql_admin')->withoutGlobalScopes()->where('entry_id', $post)
        ->addSelect(['entry_translations.id', EntryShareImage::KEY => EntryShareImage::keySubquery()->withoutGlobalScopes()])
        ->firstOrFail()->getAttribute(EntryShareImage::KEY);

    try {
        pickShareUnchecked($post, $foreign->id);
        expect($read())->toBeNull();
    } finally {
        pickShareUnchecked($post, $own->id);
    }
    expect($read())->toBe($own->storage_key);
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
    expect((string) test()->get('http://mau.platform.test/sitemap.xml')->getContent())->not->toContain('anh-da-bo')->toContain('anh-bia');
});

it('connects to the media host only when the post draws a cover from it', function (): void {
    ['site' => $site, 'post' => $post] = shareSite(withCover: false);
    $picked = app(Tenancy::class)->enter($site, fn (): Media => StorefrontCards::media('anh-chon-rieng', 1600, 900));
    pickShare($site, $post, $picked->id);
    expect(shareHead())->toContain('anh-chon-rieng')->not->toContain('rel="preconnect"');

    app(Tenancy::class)->enter($site, fn () => DB::table('entries')->where('id', $post)->update(['meta' => json_encode(['image_url' => StorefrontCards::media('anh-bia')->storage_key])]));
    expect(shareHead())->toContain('<link rel="preconnect" href="https://files.test">');
});

it('costs no more queries with a share image picked than without', function (): void {
    ['site' => $site, 'post' => $post] = shareSite();
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    $queries = function () use (&$count): int {
        $count = 0;
        shareHead();

        return $count;
    };
    $queries(); // warm caches
    $without = $queries();
    $picked = app(Tenancy::class)->enter($site, fn (): Media => StorefrontCards::media('anh-chon-rieng', 1600, 900));
    pickShare($site, $post, $picked->id);
    $queries();
    $with = $queries();

    expect($without)->toBe(17)->and($with)->toBe(17);
});

<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Media\Models\Media;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * A post's cover moves from an address in meta.image_url to the row entries.cover_media_id names.
 * While both shapes exist every reader takes the id when it is set and the old key only when it is
 * not, so a website whose covers are all old, all new or a mix draws byte for byte the same pages:
 * the news list, the grid blocks (the job tabs too), a tag's list, the search page, the post itself
 * (its cover, og:image and related posts), the sitemap and the search suggestions. A cover whose id
 * names no live file of the website (in the Trash, another website's) draws as no cover at all, even
 * when the old key is still in meta: no picture on the card, the brand share image in the head.
 */

/** Every page that draws a post's cover: cards, grid blocks, a tag's list, the search page, the post, the sitemap, the suggestions. */
const COVER_PATHS = ['/tin-tuc', '/khoi', '/the/cnc', '/tim-kiem?q=bai', '/tin-tuc/bai-2', '/sitemap.xml', '/tim-kiem/goi-y?q=bai'];

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/**
 * A website with $rows news posts on the cards layout, each with its own photo (old shape), a post
 * drawing a glyph, a post with an outside picture, and a page of grid blocks over the news.
 *
 * @return array{site: int, posts: list<int>}
 */
function coverSite(int $rows = 4): array
{
    $siteId = StorefrontCards::site();
    $posts = app(Tenancy::class)->enter($siteId, function () use ($rows): array {
        $news = StorefrontCards::news();
        $news->update(['list_template' => 'grid-news']);
        // two sub-categories, so a grid block with tabs draws one tab each
        $groups = [StorefrontCards::category('Tin máy', 'tin-may', $news, routed: false), StorefrontCards::category('Tin xưởng', 'tin-xuong', $news, position: 1, routed: false)];
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        $posts = [];
        foreach (range(1, $rows) as $n) {
            $photo = StorefrontCards::media("anh-bai-{$n}", 900 + $n * 100, 600);
            $post = StorefrontCards::post($news, "Bài {$n}", "bai-{$n}", $at->addHours($n), ['image_url' => $photo->storage_key], 'Tóm tắt.');
            $post->assignCategories([$news->id, $groups[($n - 1) % 2]->id]);
            app(EntryTags::class)->assign($post, 'vi', ['CNC']);
            $posts[] = $post->id;
        }
        StorefrontCards::post($news, 'Bài hình vẽ', 'bai-hinh-ve', $at, ['image_url' => 'shield'], 'Tóm tắt.');
        StorefrontCards::post($news, 'Bài ảnh ngoài', 'bai-anh-ngoai', $at->subHour(), ['image_url' => 'https://cdn.example.test/a/ngoai.jpg'], 'Tóm tắt.');
        StorefrontCards::page('/khoi', 'Các khối', [
            ['type' => 'entry_grid', 'props' => ['heading' => 'Mặc định', 'taxonomy_slug' => 'tin-tuc', 'limit' => 12]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Thẻ tin', 'taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'limit' => 12]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Dự án', 'taxonomy_slug' => 'tin-tuc', 'variant' => 'list', 'limit' => 12]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Việc làm', 'taxonomy_slug' => 'tin-tuc', 'variant' => 'job', 'limit' => 12]],
            ['type' => 'entry_grid', 'props' => ['heading' => 'Theo nhóm', 'taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'tabs' => 'auto', 'limit' => 12]],
        ]);

        return $posts;
    });

    return ['site' => $siteId, 'posts' => $posts];
}

/**
 * Names the cover of these posts by id and takes it out of meta, past the model (as the backfill
 * will): no content date moves, so only the cover's shape differs.
 *
 * @param  list<int>  $postIds
 */
function coverToId(int $siteId, array $postIds): void
{
    app(Tenancy::class)->enter($siteId, function () use ($postIds): void {
        foreach (Entry::query()->whereIn('id', $postIds)->get() as $entry) {
            $meta = $entry->meta;
            $media = Media::query()->where('storage_key', $meta['image_url'])->firstOrFail();
            unset($meta['image_url']);
            DB::table('entries')->where('id', $entry->id)->update(['cover_media_id' => $media->id, 'meta' => json_encode($meta)]);
        }
    });
}

/** @return array<string, string> each page of the website as a visitor gets it */
function coverPages(): array
{
    $pages = [];
    foreach (COVER_PATHS as $path) {
        $pages[$path] = (string) test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    return $pages;
}

it('draws every page byte for byte the same whether its covers are old, new or mixed', function (): void {
    ['site' => $site, 'posts' => $posts] = coverSite();
    $old = coverPages();

    coverToId($site, [$posts[0], $posts[2]]);
    $mixed = coverPages();

    coverToId($site, [$posts[1], $posts[3]]);
    $new = coverPages();

    // the photos are really drawn (a page with no cover anywhere would also compare equal)
    $key = app(Tenancy::class)->enter($site, fn (): string => Media::query()->where('slug', 'anh-bai-2')->value('storage_key'));
    $code = substr($key, (int) strrpos($key, '/') + 1);
    foreach (COVER_PATHS as $path) {
        expect($old[$path])->toContain(pathinfo($code, PATHINFO_FILENAME));
    }
    expect($old['/tin-tuc/bai-2'])->toContain('property="og:image"')
        // the job look and the tabs are drawn, not left out for want of posts
        ->and($old['/khoi'])->toContain('sf-cards--job')->toContain('Tin máy')->toContain('Tin xưởng');

    foreach ($old as $path => $html) {
        expect($mixed[$path])->toBe($html, "mixed covers changed {$path}")
            ->and($new[$path])->toBe($html, "covers by id changed {$path}");
    }
});

it('draws a cover whose id names a trashed file or another website’s as no cover at all', function (string $case): void {
    ['site' => $site, 'posts' => $posts] = coverSite(3);
    $other = $case === 'another website’s file' ? StorefrontCards::site('Xưởng Khác', 'khac.platform.test') : $site;
    $file = app(Tenancy::class)->enter($other, function () use ($case): Media {
        $media = StorefrontCards::media('anh-ngoai-cuoc', 1600, 900);
        if ($case === 'a file in the Trash') {
            $media->delete();
        }

        return $media;
    });
    // the reference: the post has no cover in any shape
    app(Tenancy::class)->enter($site, function () use ($posts): void {
        $entry = Entry::query()->findOrFail($posts[1]);
        $meta = $entry->meta;
        unset($meta['image_url']);
        DB::table('entries')->where('id', $entry->id)->update(['meta' => json_encode($meta)]);
    });
    $none = coverPages();

    app(Tenancy::class)->enter($site, fn () => DB::table('entries')->where('id', $posts[1])->update(['cover_media_id' => $file->id]));
    $pages = coverPages();

    foreach ($none as $path => $html) {
        expect($pages[$path])->toBe($html, "{$case} drew a picture on {$path}")
            ->and($pages[$path])->not->toContain('anh-ngoai-cuoc');
    }
})->with(['a file in the Trash', 'another website’s file']);

it('draws no cover when the id names a trashed file, even with the old key of a live photo still in meta', function (): void {
    ['site' => $site, 'posts' => $posts] = coverSite(3);
    $trashed = app(Tenancy::class)->enter($site, function (): Media {
        $media = StorefrontCards::media('anh-da-xoa', 1600, 900);
        $media->delete();

        return $media;
    });
    $meta = app(Tenancy::class)->enter($site, fn (): array => Entry::query()->findOrFail($posts[1])->meta);
    $photo = pathinfo(basename($meta['image_url']), PATHINFO_FILENAME);
    $withPhoto = coverPages();
    // the reference: the post has no cover in any shape
    app(Tenancy::class)->enter($site, function () use ($posts, $meta): void {
        $bare = $meta;
        unset($bare['image_url']);
        DB::table('entries')->where('id', $posts[1])->update(['meta' => json_encode($bare)]);
    });
    $none = coverPages();

    // the trashed id beside the old key of the live photo
    app(Tenancy::class)->enter($site, fn () => DB::table('entries')->where('id', $posts[1])->update(['cover_media_id' => $trashed->id, 'meta' => json_encode($meta)]));
    $pages = coverPages();

    foreach (['/tin-tuc', '/tin-tuc/bai-2', '/sitemap.xml', '/tim-kiem/goi-y?q=bai'] as $path) {
        expect(str_contains($withPhoto[$path], $photo))->toBeTrue("the photo was not drawn on {$path} to begin with");
    }
    expect($withPhoto['/tin-tuc/bai-2'])->toMatch('/property="og:image" content="[^"]*'.preg_quote($photo, '/').'/');
    foreach ($none as $path => $html) {
        expect($pages[$path])->toBe($html, "the old key drew a picture on {$path}")
            ->and($pages[$path])->not->toContain($photo);
    }
});

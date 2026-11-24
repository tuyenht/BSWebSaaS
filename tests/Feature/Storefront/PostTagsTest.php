<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * A post's tags on the storefront: chips under the post, each linking to the tag's page at
 * /the/{slug}; the tag page lists the posts published in its language that carry the tag, newest
 * first, paged like every list; it is `noindex,follow` and left out of the sitemap while it holds
 * fewer than two such posts. A save in one language changes only the tags named in it.
 */

const POST_TAGS_HOST = 'http://mau.platform.test';

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** Tag $post with $names in Vietnamese, as the editor's save does. */
function tagPost(int $siteId, Entry $post, array $names, string $locale = 'vi'): array
{
    return app(Tenancy::class)->enter($siteId, fn (): array => app(EntryTags::class)->assign($post, $locale, $names));
}

it('shows the post’s tags as links to the tag page, which lists only its published posts', function (): void {
    $siteId = StorefrontCards::site();
    [$first, $draft] = app(Tenancy::class)->enter($siteId, function (): array {
        $news = StorefrontCards::news();
        $first = StorefrontCards::post($news, 'Máy mới về xưởng', 'may-moi', CarbonImmutable::parse('2026-10-02 08:00', 'UTC'));
        $draft = StorefrontCards::post($news, 'Bản nháp', 'ban-nhap');
        $draft->forceFill(['status' => 'draft'])->save();

        return [$first, $draft];
    });
    tagPost($siteId, $first, ['Máy phay 5 trục', 'CNC']);
    tagPost($siteId, $draft, ['CNC']);

    $post = $this->get(POST_TAGS_HOST.'/tin-tuc/may-moi')->assertOk()->getContent();
    expect($post)->toContain('<nav class="post-tags" aria-label="'.__('storefront.post_tags').'">')
        ->toContain('href="/the/may-phay-5-truc">Máy phay 5 trục</a>')
        ->toContain('href="/the/cnc">CNC</a>');

    $page = $this->get(POST_TAGS_HOST.'/the/cnc')->assertOk()->getContent();
    expect($page)->toContain('Máy mới về xưởng')->not->toContain('Bản nháp')
        ->toContain('<meta name="robots" content="noindex,follow">')
        ->toContain('<link rel="canonical" href="'.POST_TAGS_HOST.'/the/cnc">');
});

it('lets a tag page into search and the sitemap once two posts carry it', function (): void {
    $siteId = StorefrontCards::site();
    $posts = app(Tenancy::class)->enter($siteId, function (): array {
        $news = StorefrontCards::news();

        return [StorefrontCards::post($news, 'Bài một', 'bai-mot'), StorefrontCards::post($news, 'Bài hai', 'bai-hai')];
    });
    tagPost($siteId, $posts[0], ['CNC']);

    expect($this->get(POST_TAGS_HOST.'/sitemap.xml')->assertOk()->getContent())->not->toContain('/the/cnc');

    tagPost($siteId, $posts[1], ['CNC']);
    $page = $this->get(POST_TAGS_HOST.'/the/cnc')->assertOk()->getContent();
    expect($page)->not->toContain('noindex')->toContain('Bài một')->toContain('Bài hai')
        ->and($this->get(POST_TAGS_HOST.'/sitemap.xml')->assertOk()->getContent())->toContain(POST_TAGS_HOST.'/the/cnc</loc>');
});

it('pages a tag’s posts by keyset like every list, within a fixed number of queries', function (): void {
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function (): void {
        $news = StorefrontCards::news();
        foreach (range(1, 14) as $n) {
            app(EntryTags::class)->assign(StorefrontCards::post($news, "Bài {$n}", "bai-{$n}"), 'vi', ['CNC']);
        }
    });

    // as many queries as the category's own list of the same posts, plus the noindex check's none
    $count = function (string $path): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get(POST_TAGS_HOST.$path)->assertOk()->getContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$html, $queries];
    };
    [, $categoryQueries] = $count('/tin-tuc');
    [$first, $queries] = $count('/the/cnc');
    $second = $this->get(POST_TAGS_HOST.'/the/cnc?page=2')->assertOk()->getContent();

    preg_match_all('#href="/tin-tuc/bai-(\d+)"#', $first, $one);
    preg_match_all('#href="/tin-tuc/bai-(\d+)"#', $second, $two);
    expect(array_unique(array_map('intval', $one[1])))->toHaveCount(12)
        ->and(array_values(array_unique(array_map('intval', $two[1]))))->toBe([2, 1])
        ->and($first)->toContain('rel="next"')
        ->and($queries)->toBeLessThanOrEqual($categoryQueries);
});

it('changes only the tags named in the language saved, and keeps them off the categories', function (): void {
    $siteId = StorefrontCards::site();
    $post = app(Tenancy::class)->enter($siteId, fn (): Entry => StorefrontCards::post(StorefrontCards::news(), 'Bài', 'bai'));

    expect(tagPost($siteId, $post, ['Máy phay']))->toBe(['Máy phay'])
        ->and(tagPost($siteId, $post, ['Milling'], 'en'))->toBe(['Milling'])
        ->and(tagPost($siteId, $post, []))->toBe([]);

    app(Tenancy::class)->enter($siteId, function () use ($post): void {
        expect(app(EntryTags::class)->names($post, 'en'))->toBe(['Milling'])
            ->and(SiteRoute::query()->where('locale', 'en')->where('path', '/the/milling')->exists())->toBeTrue()
            ->and($post->primaryCategory()?->vocabulary)->toBe('post_cat')
            // the Vietnamese tag stays, out of the post only
            ->and(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(2);
    });
});

it('has no tag page while no live post carries the tag, and a noindex one once a post carrying it is published', function (): void {
    $siteId = StorefrontCards::site();
    $draft = app(Tenancy::class)->enter($siteId, function (): Entry {
        $draft = StorefrontCards::post(StorefrontCards::news(), 'Bản nháp', 'ban-nhap');
        $draft->forceFill(['status' => 'draft'])->save();
        $draft->translations()->update(['status' => 'draft']);

        return $draft;
    });
    tagPost($siteId, $draft, ['Tên lạ']);

    // made by a draft's save: the tag's name is not on the website, on no page of the list
    $this->get(POST_TAGS_HOST.'/the/ten-la')->assertNotFound()->assertDontSee('Tên lạ');
    $this->get(POST_TAGS_HOST.'/the/ten-la?page=2')->assertNotFound();
    // a post published, but its Vietnamese version still a draft: no page either
    app(Tenancy::class)->enter($siteId, fn () => $draft->forceFill(['status' => 'published'])->save());
    $this->get(POST_TAGS_HOST.'/the/ten-la')->assertNotFound();

    app(Tenancy::class)->enter($siteId, fn () => $draft->translations()->update(['status' => 'published']));
    $page = $this->get(POST_TAGS_HOST.'/the/ten-la')->assertOk()->getContent();
    expect($page)->toContain('Bản nháp')->toContain('<meta name="robots" content="noindex,follow">');

    // the post taken off the website again: the page goes with it
    app(Tenancy::class)->enter($siteId, fn () => $draft->delete());
    $this->get(POST_TAGS_HOST.'/the/ten-la')->assertNotFound();
});

it('gives a tag page of another language its language in every address', function (): void {
    $siteId = StorefrontCards::site();
    $post = app(Tenancy::class)->enter($siteId, function (): Entry {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true]);
        $post = StorefrontCards::post(StorefrontCards::news(), 'Bài', 'bai');
        $post->translations()->create(['locale' => 'en', 'title' => 'New milling machine', 'slug' => 'new-milling', 'status' => 'published', 'body' => ['blocks' => []]]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/new-milling', 'entry_id' => $post->id]);

        return $post;
    });
    tagPost($siteId, $post, ['Milling'], 'en');

    $page = $this->get(POST_TAGS_HOST.'/en/the/milling')->assertOk()->getContent();
    expect($page)->toContain('New milling machine')
        ->toContain('<link rel="canonical" href="'.POST_TAGS_HOST.'/en/the/milling">')
        ->not->toContain('"'.POST_TAGS_HOST.'/the/milling"')
        ->not->toContain('href="/the/milling"');
});

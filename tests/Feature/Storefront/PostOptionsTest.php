<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * A post's «Thuộc tính bài» on the website (board 07): its own look over its category's layout
 * (entries.template_override `hero`: the cover photo leads the page at full width above the band;
 * `wide`: no side column) and the day it shows as published (meta.display_date, a day in the site's
 * zone; empty = the real day) on its band and its cards. The structured data and the sitemap keep the
 * real publish time.
 */

const POST_OPTIONS_HOST = 'http://mau.platform.test';

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/** A news post with a cover and two headings (a table of contents), with $template and $meta. */
function postOptionsPost(?string $template = null, array $meta = []): Entry
{
    $site = StorefrontCards::site();

    return app(Tenancy::class)->enter($site, function () use ($template, $meta): Entry {
        $cover = StorefrontCards::media('xuong-phay');
        $post = StorefrontCards::post(StorefrontCards::news(), 'Máy phay mới về xưởng', 'may-phay-moi', CarbonImmutable::parse('2026-10-01 08:00', 'UTC'), ['image_url' => $cover->storage_key] + $meta);
        EntryTranslation::query()->where('entry_id', $post->id)->update(['body' => json_encode(['blocks' => [
            ['type' => 'richtext_html', 'props' => ['html' => '<h2>Tổng quan</h2><p>Máy mới.</p><h2>Thông số</h2><p>Năm trục.</p>']],
        ]])]);
        $post->update(['template_override' => $template]);

        return $post->fresh();
    });
}

it('leads a «hero» post with its cover at full width above the band, and keeps the usual cover otherwise', function (): void {
    postOptionsPost();
    $plain = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($plain)->toContain('<figure class="post-cover">')
        ->not->toContain('post-cover--hero');

    StorefrontCards::reset();
    postOptionsPost('hero');
    $hero = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($hero)->toContain('<figure class="post-cover post-cover--hero">')
        ->not->toContain('<figure class="post-cover">')
        // above the band, drawn once, never lazy (it is the first screen's main picture)
        ->and(strpos($hero, 'post-cover--hero'))->toBeLessThan(strpos($hero, 'class="post-band'))
        ->and(substr_count($hero, 'class="post-cover__img'))->toBe(1);
    preg_match('#<figure class="post-cover post-cover--hero">\s*<img[^>]*>#', $hero, $img);
    expect($img[0] ?? '')->not->toContain('loading="lazy"')->toContain('sizes="100vw"');
});

it('draws a «wide» post with no side column: the contents stay above the text', function (): void {
    postOptionsPost();
    $plain = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($plain)->toContain('<aside class="post-aside');

    StorefrontCards::reset();
    postOptionsPost('wide');
    $wide = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($wide)->not->toContain('<aside class="post-aside')
        ->toContain('post-columns--wide')
        ->toContain('<details class="post-toc-phone">')
        ->toContain('<h2 id=');
});

it('falls back to the category\'s look for a name the theme does not draw', function (): void {
    postOptionsPost('no-such-look');
    $html = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($html)->toContain('<figure class="post-cover">')->toContain('<aside class="post-aside');
});

it('prints the shown day on the band and the cards, while the data and the sitemap keep the real one', function (): void {
    $post = postOptionsPost(null, ['display_date' => '2026-09-15']);
    // the news list (dated cards) and a second post whose related cards show the first one
    app(Tenancy::class)->enter($post->site_id, function (): void {
        StorefrontCards::news()->update(['list_template' => 'grid-news']);
        StorefrontCards::post(StorefrontCards::news(), 'Xưởng thêm ca đêm', 'xuong-them-ca', CarbonImmutable::parse('2026-10-03 08:00', 'UTC'));
    });

    $html = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($html)->toContain('<time datetime="2026-09-15">15/09/2026</time>')
        ->not->toContain('<time datetime="2026-10-01">')
        ->and(ldNode($html, 'Article')['datePublished'] ?? null)->toStartWith('2026-10-01T');

    $list = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc')->assertOk()->getContent();
    expect($list)->toContain('<time class="sf-card__date" datetime="2026-09-15">15/09/2026</time>');
    $other = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/xuong-them-ca')->assertOk()->getContent();
    expect($other)->toContain('<time class="sf-card__date" datetime="2026-09-15">15/09/2026</time>');

    $sitemap = (string) $this->get(POST_OPTIONS_HOST.'/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->not->toContain('2026-09-15');
});

it('prints the real day when none is set', function (): void {
    postOptionsPost();
    $html = (string) $this->get(POST_OPTIONS_HOST.'/tin-tuc/may-phay-moi')->assertOk()->getContent();
    expect($html)->toContain('<time datetime="2026-10-01">01/10/2026</time>');
});

<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Carbon\CarbonImmutable;
use Tests\Fixtures\StorefrontCards;

/*
 * The shared breadcrumb (one trail for the visible "Trang chủ / … / page" and the BreadcrumbList
 * JSON-LD), the list page band with its cover photo and description, and the post page around its
 * body: previous and next posts, up to three related posts from the same category, and the
 * project layout a category can choose for its posts.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

const BC_HOST = 'http://mau.platform.test';

/**
 * The visible trail of the first breadcrumb on the page: each level's name and link (null for the
 * page itself, which must carry aria-current).
 *
 * @return list<array{0: string, 1: string|null}>
 */
function bcTrail(string $html): array
{
    if (preg_match('#<nav class="sf-crumbs[^"]*" aria-label="Đường dẫn">(.*?)</nav>#s', $html, $nav) !== 1) {
        return [];
    }
    preg_match_all('#<li class="sf-crumbs__item">(?:<a class="sf-crumbs__link" href="([^"]+)">|<span class="sf-crumbs__current" aria-current="page">)(.*?)</(?:a|span)></li>#s', $nav[1], $items, PREG_SET_ORDER);

    return array_map(fn (array $item): array => [html_entity_decode($item[2]), $item[1] !== '' ? $item[1] : null], $items);
}

/** @return list<string> the names in the page's BreadcrumbList JSON-LD, in order */
function bcJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts);
    foreach ($scripts[1] as $json) {
        $data = json_decode($json, true);
        if (($data['@type'] ?? null) === 'BreadcrumbList') {
            return array_map(fn (array $item): string => $item['name'], $data['itemListElement']);
        }
    }

    return [];
}

/** @return list<string> every href inside the element whose opening tag starts with $open */
function bcLinks(string $html, string $open, string $close): array
{
    $start = strpos($html, $open);
    if ($start === false) {
        return [];
    }
    $part = substr($html, $start, (int) strpos($html, $close, $start) - $start);
    preg_match_all('#href="([^"]+)"#', $part, $links);

    return $links[1];
}

/**
 * A site with two levels of categories and four posts in the inner one, a day apart, plus two in
 * the outer one; returns the posts oldest first.
 *
 * @return array{site: int, posts: list<Entry>, inner: Taxonomy, outer: Taxonomy}
 */
function bcNews(?string $detailTemplate = null): array
{
    $site = StorefrontCards::site();

    return app(Tenancy::class)->enter($site, function () use ($site, $detailTemplate): array {
        $outer = StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa');
        $inner = StorefrontCards::category('Ước tính giá cả', 'uoc-tinh-gia-ca', $outer);
        if ($detailTemplate !== null) {
            $inner->update(['detail_template' => $detailTemplate]);
        }
        $posts = [];
        foreach ([['Đề án mùa hè', 'de-an-mua-he'], ['Ống thép cỡ nhỏ', 'ong-thep-co-nho'], ['Ấn tượng xưởng mới mở', 'an-tuong-xuong-moi-mo'], ['Ước lượng chi phí', 'uoc-luong-chi-phi']] as $day => [$title, $slug]) {
            $posts[] = StorefrontCards::post($inner, $title, $slug, CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC')->addDays($day));
        }
        StorefrontCards::post($outer, 'Ở lại với nghề cơ khí', 'o-lai-voi-nghe-co-khi', CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));
        StorefrontCards::post($outer, 'Ủ nhiệt đúng độ', 'u-nhiet-dung-do', CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC'));

        return ['site' => $site, 'posts' => $posts, 'inner' => $inner, 'outer' => $outer];
    });
}

it('gives a nested page every published parent level, and the JSON-LD the same levels', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $top = StorefrontCards::page('/o-day-co-gi', 'Ở đây có gì', []);
        $middle = StorefrontCards::page('/uoc-mo-thuo-nho', 'Ước mơ thuở nhỏ', []);
        $middle->update(['parent_id' => $top->id]);
        StorefrontCards::page('/doi-moi-toan-bo', 'Đổi mới toàn bộ', [])->update(['parent_id' => $middle->id]);
    });

    $html = $this->get(BC_HOST.'/doi-moi-toan-bo')->assertOk()->getContent();

    expect(bcTrail($html))->toBe([
        ['Trang chủ', '/'], ['Ở đây có gì', '/o-day-co-gi'], ['Ước mơ thuở nhỏ', '/uoc-mo-thuo-nho'], ['Đổi mới toàn bộ', null],
    ])
        ->and(bcJsonLd($html))->toBe(['Trang chủ', 'Ở đây có gì', 'Ước mơ thuở nhỏ', 'Đổi mới toàn bộ'])
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('leaves out a parent page that is not published', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $top = StorefrontCards::page('/o-day-co-gi', 'Ở đây có gì', []);
        $top->update(['status' => 'draft']);
        StorefrontCards::page('/doi-moi-toan-bo', 'Đổi mới toàn bộ', [])->update(['parent_id' => $top->id]);
    });

    $html = $this->get(BC_HOST.'/doi-moi-toan-bo')->assertOk()->getContent();

    expect(bcTrail($html))->toBe([['Trang chủ', '/'], ['Đổi mới toàn bộ', null]])
        ->and($html)->not->toContain('Ở đây có gì');
});

it('gives a category, a post and a product their category levels', function (): void {
    ['site' => $site] = bcNews();
    app(Tenancy::class)->enter($site, function (): void {
        $shelf = StorefrontCards::category('Đồ gá cỡ nhỏ', 'do-ga-co-nho');
        StorefrontCards::catalog($shelf);
        StorefrontCards::product('Ổ bi chặn trục cỡ nhỏ', 'o-bi-chan-truc-co-nho', $shelf);
    });

    $category = $this->get(BC_HOST.'/uoc-tinh-gia-ca')->assertOk()->getContent();
    $post = $this->get(BC_HOST.'/uoc-tinh-gia-ca/ong-thep-co-nho')->assertOk()->getContent();
    $product = $this->get(BC_HOST.'/o-bi-chan-truc-co-nho')->assertOk()->getContent();

    expect(bcTrail($category))->toBe([['Trang chủ', '/'], ['Ấn phẩm số hóa', '/an-pham-so-hoa'], ['Ước tính giá cả', null]])
        ->and(bcJsonLd($category))->toBe(['Trang chủ', 'Ấn phẩm số hóa', 'Ước tính giá cả'])
        ->and(bcTrail($post))->toBe([['Trang chủ', '/'], ['Ấn phẩm số hóa', '/an-pham-so-hoa'], ['Ước tính giá cả', '/uoc-tinh-gia-ca'], ['Ống thép cỡ nhỏ', null]])
        ->and(bcJsonLd($post))->toBe(['Trang chủ', 'Ấn phẩm số hóa', 'Ước tính giá cả', 'Ống thép cỡ nhỏ'])
        ->and(bcTrail($product))->toBe([['Trang chủ', '/'], ['Đồ gá cỡ nhỏ', '/do-ga-co-nho'], ['Ổ bi chặn trục cỡ nhỏ', null]])
        ->and(bcJsonLd($product))->toBe(['Trang chủ', 'Đồ gá cỡ nhỏ', 'Ổ bi chặn trục cỡ nhỏ'])
        ->and(substr_count($post, '<h1'))->toBe(1);
});

it('prints the list band with its cover photo, description and trail, and the plain band without them', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $photo = StorefrontCards::media('xuong-co-khi');
        $covered = StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa', listTemplate: 'grid-news');
        $covered->update(['cover_media_id' => $photo->id]);
        $covered->translations()->update(['description' => 'Ống, thép và đồ gá cỡ nhỏ']);
        StorefrontCards::category('Ước tính giá cả', 'uoc-tinh-gia-ca', listTemplate: 'grid-news');
    });

    $covered = $this->get(BC_HOST.'/an-pham-so-hoa')->assertOk()->getContent();
    $plain = $this->get(BC_HOST.'/uoc-tinh-gia-ca')->assertOk()->getContent();

    expect($covered)
        ->toContain('<img class="page-band__bg"')
        ->toContain('fetchpriority="high"')
        ->toContain('srcset="https://files.test/s/library/xuong-co-khi-')
        ->toContain('<p class="page-band__subtitle">Ống, thép và đồ gá cỡ nhỏ</p>')
        ->toContain('<nav class="sf-crumbs sf-crumbs--dark page-band__crumbs"')
        ->and(bcTrail($covered))->toBe([['Trang chủ', '/'], ['Ấn phẩm số hóa', null]])
        ->and(substr_count($covered, '<h1'))->toBe(1)
        ->and($plain)
        ->toContain('class="page-band')
        ->toContain('Ước tính giá cả</h1>')
        ->not->toContain('page-band__bg')
        ->not->toContain('page-band__subtitle')
        ->not->toContain('fetchpriority="high"');
});

it('marks one image high priority on a covered list with cards, so the cover wins and no card competes', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $photo = StorefrontCards::media('xuong-co-khi');
        $category = StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa', listTemplate: 'grid-news');
        $category->update(['cover_media_id' => $photo->id]);
        foreach ([['Đề án mùa hè', 'de-an-mua-he'], ['Ống thép cỡ nhỏ', 'ong-thep-co-nho']] as [$title, $slug]) {
            StorefrontCards::post($category, $title, $slug, null, ['image_url' => $photo->storage_key]);
        }
    });

    $html = $this->get(BC_HOST.'/an-pham-so-hoa')->assertOk()->getContent();

    expect(substr_count($html, 'fetchpriority="high"'))->toBe(1)
        ->and($html)->toContain('class="sf-card');
});

it('keeps a post cover from competing with a hero that opens the body', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $photo = StorefrontCards::media('ong-thep');
        $category = StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa');
        $post = StorefrontCards::post($category, 'Đề án mùa hè', 'de-an-mua-he', null, ['image_url' => $photo->storage_key]);
        $post->translations()->update(['body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => $photo->id]]]]]);
    });

    $html = $this->get(BC_HOST.'/an-pham-so-hoa/de-an-mua-he')->assertOk()->getContent();

    expect(substr_count($html, 'fetchpriority="high"'))->toBe(1)
        ->and($html)->toContain('class="post-cover__img"');
});

it('keeps one high-priority image whichever body section prints one, and the cover takes it when none does', function (array $blocks, int $coverHigh): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function () use ($blocks): void {
        $photo = StorefrontCards::media('ong-thep');
        $category = StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa');
        $post = StorefrontCards::post($category, 'Đề án mùa hè', 'de-an-mua-he', null, ['image_url' => $photo->storage_key]);
        $withPhoto = array_map(fn (array $block): array => ['type' => $block['type'], 'props' => array_map(fn (mixed $value): mixed => $value === 'PHOTO' ? $photo->id : $value, $block['props'])], $blocks);
        $post->translations()->update(['body' => ['blocks' => $withPhoto]]);
    });

    $html = $this->get(BC_HOST.'/an-pham-so-hoa/de-an-mua-he')->assertOk()->getContent();

    expect(preg_match_all('#<img\b[^>]*\sfetchpriority="high"#', $html))->toBe(1)
        ->and(preg_match('#<img class="post-cover__img"[^>]*fetchpriority="high"#', $html))->toBe($coverHigh);
})->with([
    'a hero after a text block' => [[['type' => 'richtext', 'props' => ['heading' => 'Ống thép cỡ nhỏ']], ['type' => 'hero', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => 'PHOTO']]], 1],
    'a first block with a background photo' => [[['type' => 'richtext', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'bg' => 'image', 'bg_image' => 'PHOTO']]], 0],
    'a hero then an about block' => [[['type' => 'hero', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => 'PHOTO']], ['type' => 'about', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => 'PHOTO']]], 0],
    'a slider after a text block' => [[['type' => 'richtext', 'props' => ['heading' => 'Ống thép cỡ nhỏ']], ['type' => 'hero_slider', 'props' => ['slides' => [['image_url' => 'PHOTO', 'heading' => 'Ống thép cỡ nhỏ']]]]], 1],
    'a hero with no photo' => [[['type' => 'hero', 'props' => ['heading' => 'Ống thép cỡ nhỏ']]], 1],
    'an about block after a text block' => [[['type' => 'richtext', 'props' => ['heading' => 'Ống thép cỡ nhỏ']], ['type' => 'about', 'props' => ['heading' => 'Ống thép cỡ nhỏ', 'image_url' => 'PHOTO']]], 1],
    'rich text that writes the attribute as words' => [[['type' => 'richtext_html', 'props' => ['html' => '<p>Thêm fetchpriority="high" vào ảnh đầu trang</p>']]], 1],
]);

it('links the next older post as previous and the next newer one as next, and none past either end', function (): void {
    ['posts' => $posts] = bcNews();

    $second = $this->get(BC_HOST.'/uoc-tinh-gia-ca/ong-thep-co-nho')->assertOk()->getContent();
    $newest = $this->get(BC_HOST.'/uoc-tinh-gia-ca/uoc-luong-chi-phi')->assertOk()->getContent();
    $oldest = $this->get(BC_HOST.'/uoc-tinh-gia-ca/de-an-mua-he')->assertOk()->getContent();

    expect($second)
        ->toContain('<a class="post-nav__link post-nav__link--prev" href="/uoc-tinh-gia-ca/de-an-mua-he">')
        ->toContain('<a class="post-nav__link post-nav__link--next" href="/uoc-tinh-gia-ca/an-tuong-xuong-moi-mo">')
        ->and($newest)
        ->toContain('<a class="post-nav__link post-nav__link--prev" href="/uoc-tinh-gia-ca/an-tuong-xuong-moi-mo">')
        ->not->toContain('post-nav__link--next')
        ->and($oldest)
        ->not->toContain('post-nav__link--prev')
        ->toContain('<a class="post-nav__link post-nav__link--next" href="/uoc-tinh-gia-ca/ong-thep-co-nho">')
        ->and($posts)->toHaveCount(4);
});

it('shows at most three related posts, newest first, from the same category only and never the post itself', function (): void {
    bcNews();

    $html = $this->get(BC_HOST.'/uoc-tinh-gia-ca/de-an-mua-he')->assertOk()->getContent();
    $related = bcLinks($html, '<section class="post-related"', '</section>');

    expect($html)->toContain('Bài viết liên quan</h2>')
        ->and(array_values(array_unique($related)))->toBe(['/uoc-tinh-gia-ca/uoc-luong-chi-phi', '/uoc-tinh-gia-ca/an-tuong-xuong-moi-mo', '/uoc-tinh-gia-ca/ong-thep-co-nho'])
        ->and($html)->not->toContain('Ở lại với nghề cơ khí')
        ->not->toContain('Ủ nhiệt đúng độ');
});

it('never shows another site\'s posts as previous, next or related, and links by address, never by id', function (): void {
    ['posts' => $posts] = bcNews();
    $other = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
    app(Tenancy::class)->enter($other, function (): void {
        $category = StorefrontCards::category('Ước tính giá cả', 'uoc-tinh-gia-ca');
        StorefrontCards::post($category, 'Ế hàng cuối vụ', 'e-hang-cuoi-vu', CarbonImmutable::parse('2026-09-02 20:00:00', 'UTC'));
    });

    $html = $this->get(BC_HOST.'/uoc-tinh-gia-ca/ong-thep-co-nho')->assertOk()->getContent();
    $links = [...bcLinks($html, '<nav class="post-nav"', '</nav>'), ...bcLinks($html, '<section class="post-related"', '</section>')];

    expect($html)->not->toContain('Ế hàng cuối vụ')
        ->not->toContain('e-hang-cuoi-vu')
        ->toContain('post-nav__link--next" href="/uoc-tinh-gia-ca/an-tuong-xuong-moi-mo"')
        ->and($links)->not->toBeEmpty();
    foreach ($links as $link) {
        expect($link)->toMatch('#^/uoc-tinh-gia-ca/[a-z-]+$#');
    }
    foreach ($posts as $post) {
        expect($links)->not->toContain('/'.$post->id);
    }
});

it('prints the news post band with its category, dates, excerpt and cover caption', function (): void {
    $site = StorefrontCards::site();
    app(Tenancy::class)->enter($site, function (): void {
        $photo = StorefrontCards::media('ong-thep');
        $photo->update(['alt' => ['vi' => 'Ống thép xếp trong kho cũ'], 'title' => ['vi' => 'Ảnh: kho thép mới mở']]);
        $category = StorefrontCards::category('Ấn phẩm số hóa', 'an-pham-so-hoa');
        $post = StorefrontCards::post($category, 'Đề án mùa hè', 'de-an-mua-he', CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC'), ['image_url' => $photo->storage_key], 'Ước tính chi phí cả năm đó');
        $post->translations()->update(['updated_at' => CarbonImmutable::parse('2026-09-05 08:00:00', 'UTC'), 'content_changed_at' => CarbonImmutable::parse('2026-09-05 08:00:00', 'UTC')]);
    });

    $html = $this->get(BC_HOST.'/an-pham-so-hoa/de-an-mua-he')->assertOk()->getContent();

    expect($html)
        ->toContain('<p class="post-band__chip">Ấn phẩm số hóa</p>')
        ->toContain('<h1 class="post-band__title">Đề án mùa hè</h1>')
        ->toContain('<p class="post-band__excerpt">Ước tính chi phí cả năm đó</p>')
        ->toContain('<time datetime="2026-09-01">')
        ->toContain('Cập nhật: <time datetime="2026-09-05">')
        ->toContain('alt="Ống thép xếp trong kho cũ"')
        ->toContain('<figcaption class="post-cover__caption">Ảnh: kho thép mới mở</figcaption>')
        ->toContain('class="post__body sf-prose sf-prose--article"');
});

it('shows a project post on the project layout when its category chooses it', function (): void {
    bcNews('project');

    $html = $this->get(BC_HOST.'/uoc-tinh-gia-ca/ong-thep-co-nho')->assertOk()->getContent();

    expect($html)
        ->toContain('<header class="post-band post-band--split">')
        ->toContain('Dự án khác</h2>')
        ->toContain('href="/uoc-tinh-gia-ca"')
        ->not->toContain('post-nav__link')
        ->and(count(array_unique(bcLinks($html, '<section class="post-related', '</section>'))))->toBe(4)  // three projects + "see all"
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('reads a post page in a fixed number of queries, however many posts its category holds', function (): void {
    ['site' => $site, 'inner' => $inner] = bcNews();
    $path = BC_HOST.'/uoc-tinh-gia-ca/ong-thep-co-nho';
    $this->get($path)->assertOk();

    $few = countQueries(fn () => $this->get($path)->assertOk());
    app(Tenancy::class)->enter($site, function () use ($inner): void {
        foreach (range(1, 8) as $n) {
            StorefrontCards::post($inner, 'Ấn bản thứ '.$n.' cỡ nhỏ', 'an-ban-'.str_repeat('x', $n), CarbonImmutable::parse('2026-08-01 08:00:00', 'UTC')->addDays($n));
        }
    });
    $many = countQueries(fn () => $this->get($path)->assertOk());

    expect($many)->toBe($few)
        ->and($few)->toBeLessThanOrEqual(17); // measured: the parent category's name, then previous, next and related in one read with their titles and addresses
});

it('shows a post on the default post layout when its category holds a layout that cannot render a post', function (string $stored): void {
    bcNews($stored);   // written straight to the row, around the write actions

    $html = $this->get(BC_HOST.'/uoc-tinh-gia-ca/ong-thep-co-nho')->assertOk()->getContent();

    expect($html)->toContain('<header class="post-band')
        ->and(substr_count($html, '<h1'))->toBe(1);
})->with(['category', 'product']);

it('marks the menu item of the section a post sits in, in the desktop menu, the phone menu and the classic menu', function (): void {
    $news = bcNews();
    app(Tenancy::class)->enter($news['site'], function (): void {
        $menu = Menu::query()->firstOrFail();
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '/an-pham-so-hoa', 'position' => 5]);
        $item->translations()->create(['locale' => 'vi', 'label' => 'Ấn phẩm']);
    });

    // The classic header: the section's item carries the trail mark; the post itself is not in the menu.
    $classic = $this->get(BC_HOST.'/an-pham-so-hoa/o-lai-voi-nghe-co-khi')->assertOk()->getContent();
    expect(bcTrail($classic)[1] ?? null)->toBe(['Ấn phẩm số hóa', '/an-pham-so-hoa'])
        ->and($classic)->toMatch('#<li class="site-nav__item is-trail">\s*<a class="site-nav__link" href="/an-pham-so-hoa"#');

    // The rich header: both menus mark it, and the section's own list page does not (it is current).
    app(Tenancy::class)->enter($news['site'], fn () => SiteSetting::query()->updateOrCreate(
        ['group_name' => 'storefront', 'key' => 'header'], ['value' => ['sticky' => false]],
    ));
    $rich = $this->get(BC_HOST.'/an-pham-so-hoa/o-lai-voi-nghe-co-khi')->assertOk()->getContent();
    expect(substr_count($rich, '<a class="sf-nav__link is-trail" href="/an-pham-so-hoa"'))->toBe(2);

    $list = $this->get(BC_HOST.'/an-pham-so-hoa')->assertOk()->getContent();
    expect($list)->not->toContain('is-trail" href="/an-pham-so-hoa"')
        ->toMatch('#<a class="sf-nav__link" href="/an-pham-so-hoa"\s+aria-current="page"#');
});

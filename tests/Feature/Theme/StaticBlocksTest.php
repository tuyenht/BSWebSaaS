<?php

use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The static blocks of the page builder design: quick_links, gallery, faq, testimonial, team,
 * process and pricing. Each list has a maximum (some a minimum) that is refused at save in plain
 * words; links go through the safe-link check; every text is escaped; images are the site's own
 * pictures resolved in the page's one media lookup; the price is whole dong in Vietnamese format.
 */

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function staticBlockErrors(string $type, array $props): array
{
    app()->setLocale('vi');

    return array_values(Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

/**
 * @param  array<string, mixed>  $props
 * @param  array<string, string>  $srcsets
 * @param  array<string, string>  $alts
 */
function staticBlock(string $type, array $props, array $srcsets = [], array $alts = []): string
{
    app()->setLocale('vi');

    return View::make("theme::sections.{$type}", ['props' => $props, 'srcsets' => $srcsets, 'alts' => $alts])->render();
}

/**
 * @param  array<string, mixed>  $item
 * @return list<array<string, mixed>>
 */
function repeatItem(array $item, int $times): array
{
    return array_fill(0, $times, $item);
}

describe('validation', function (): void {
    it('accepts a well formed block of every type', function (string $type, array $props): void {
        expect(staticBlockErrors($type, $props))->toBe([]);
    })->with([
        'quick_links' => ['quick_links', ['heading' => 'Lối tắt', 'items' => [['icon' => 'phone', 'label' => 'Gọi', 'url' => '/lien-he']]]],
        'gallery' => ['gallery', ['images' => [['image' => 5, 'caption' => 'Xưởng'], ['image' => null, 'caption' => '']], 'columns' => '4']],
        'faq' => ['faq', ['items' => [['q' => 'Giao hàng?', 'a' => 'Có'], ['q' => 'Bảo hành?', 'a' => '']], 'columns' => '2']],
        'testimonial' => ['testimonial', ['items' => [['quote' => 'Tốt', 'name' => 'An', 'role' => 'Giám đốc', 'avatar' => 7]]]],
        'team' => ['team', ['members' => [['name' => 'Bình', 'role' => 'Kỹ sư', 'photo' => 9]]]],
        'process' => ['process', ['steps' => repeatItem(['title' => 'Bước', 'text' => 'Làm'], 4)]],
        'pricing' => ['pricing', ['plans' => repeatItem(['name' => 'Gói', 'price' => 1500000, 'unit' => '/ tháng', 'features' => "A\nB", 'cta_label' => 'Mua', 'cta_url' => 'https://example.com/mua'], 3)]],
        'empty props' => ['pricing', []],
        'price as typed digits' => ['pricing', ['plans' => repeatItem(['name' => 'Gói', 'price' => '2000000'], 2)]],
        'blank price' => ['pricing', ['plans' => repeatItem(['name' => 'Gói', 'price' => ''], 2)]],
    ]);

    it('refuses a list over its maximum, in plain words', function (string $type, string $key, array $item, int $max): void {
        expect(staticBlockErrors($type, [$key => repeatItem($item, $max)]))->toBe([]);

        $errors = staticBlockErrors($type, [$key => repeatItem($item, $max + 1)]);
        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toContain((string) $max)->toContain($type)->not->toContain('entries.');
    })->with([
        'quick_links' => ['quick_links', 'items', ['icon' => 'link', 'label' => 'A', 'url' => '/a'], 12],
        'gallery' => ['gallery', 'images', ['image' => null, 'caption' => 'A'], 12],
        'faq' => ['faq', 'items', ['q' => 'A', 'a' => 'B'], 12],
        'testimonial' => ['testimonial', 'items', ['quote' => 'A', 'name' => 'B'], 12],
        'team' => ['team', 'members', ['name' => 'A'], 12],
        'process' => ['process', 'steps', ['title' => 'A'], 6],
        'pricing' => ['pricing', 'plans', ['name' => 'A'], 4],
    ]);

    it('asks for at least three steps and two plans once a list has an item', function (): void {
        expect(staticBlockErrors('process', ['steps' => repeatItem(['title' => 'A'], 2)]))->toHaveCount(1)
            ->and(staticBlockErrors('process', ['steps' => repeatItem(['title' => 'A'], 3)]))->toBe([])
            ->and(staticBlockErrors('process', ['steps' => []]))->toBe([])
            ->and(staticBlockErrors('pricing', ['plans' => [['name' => 'A']]]))->toHaveCount(1)
            ->and(staticBlockErrors('pricing', ['plans' => repeatItem(['name' => 'A'], 2)]))->toBe([]);
    });

    it('counts only the steps with a title and the plans with a name toward the minimum', function (): void {
        $unnamed = ['name' => '  ', 'price' => 1000];
        expect(staticBlockErrors('pricing', ['plans' => [['name' => 'A'], $unnamed]]))->toHaveCount(1)
            ->and(staticBlockErrors('pricing', ['plans' => [['name' => 'A'], ['name' => 'B'], $unnamed]]))->toBe([])
            ->and(staticBlockErrors('process', ['steps' => [['title' => 'A'], ['title' => 'B'], ['title' => '', 'text' => 'x']]]))->toHaveCount(1)
            ->and(staticBlockErrors('process', ['steps' => [['title' => 'A'], ['title' => 'B'], ['title' => 'C'], ['text' => 'x']]]))->toBe([]);
    });

    it('refuses a script address, a bad icon, a bad column count and a picture typed as an address', function (string $type, array $props): void {
        expect(staticBlockErrors($type, $props))->not->toBe([]);
    })->with([
        'quick link javascript' => ['quick_links', ['items' => [['icon' => 'link', 'label' => 'A', 'url' => 'javascript:alert(1)']]]],
        'quick link wrong icon' => ['quick_links', ['items' => [['icon' => 'rocket', 'label' => 'A', 'url' => '/a']]]],
        'plan button data url' => ['pricing', ['plans' => repeatItem(['name' => 'A', 'cta_url' => 'data:text/html,x'], 2)]],
        'gallery columns' => ['gallery', ['columns' => '7']],
        'faq columns' => ['faq', ['columns' => '3']],
        'gallery picture url' => ['gallery', ['images' => [['image' => 'https://evil.test/x.png']]]],
        'avatar url' => ['testimonial', ['items' => [['quote' => 'A', 'avatar' => 'https://evil.test/x.png']]]],
        'photo url' => ['team', ['members' => [['name' => 'A', 'photo' => 'https://evil.test/x.png']]]],
    ]);

    it('accepts only whole non-negative dong for a price', function (mixed $price, bool $ok): void {
        $errors = staticBlockErrors('pricing', ['plans' => repeatItem(['name' => 'A', 'price' => $price], 2)]);
        expect($errors === [])->toBe($ok);
    })->with([
        [0, true], [1500000, true], ['1500000', true], [null, true], ['', true],
        [-1, false], [1.5, false], ['1.5', false], ['12abc', false], [1_000_000_000_000, false], [['x'], false],
    ]);

    it('refuses more than twelve feature lines in a plan', function (): void {
        $lines = fn (int $n): string => implode("\n", array_map(fn (int $i): string => "Ý {$i}", range(1, $n)));

        expect(staticBlockErrors('pricing', ['plans' => repeatItem(['name' => 'A', 'features' => $lines(12)."\n\n"], 2)]))->toBe([])
            ->and(staticBlockErrors('pricing', ['plans' => repeatItem(['name' => 'A', 'features' => $lines(13)], 2)]))->toHaveCount(1);
    });
});

describe('helpers', function (): void {
    it('takes the first letter of a name with its accent, also from a decomposed name', function (): void {
        expect(SectionProps::initial(' đức '))->toBe('Đ')
            ->and(SectionProps::initial("A\u{0301}nh"))->toBe('Á')
            ->and(SectionProps::initial(''))->toBe('');
    });

    it('prints the price in whole dong the Vietnamese way, and nothing for an unset one', function (): void {
        expect(SectionProps::price(1500000))->toBe("1.500.000\u{A0}₫")
            ->and(SectionProps::price('990000'))->toBe("990.000\u{A0}₫")
            ->and(SectionProps::price(0))->toBeNull()
            ->and(SectionProps::price(''))->toBeNull()
            ->and(SectionProps::price(null))->toBeNull()
            ->and(SectionProps::price(12.5))->toBeNull()
            ->and(SectionProps::price('-5'))->toBeNull();
    });

    it('keeps the non-blank lines, capped', function (): void {
        expect(SectionProps::lines("  A \r\n\nB\n   \nC", 2))->toBe(['A', 'B'])
            ->and(SectionProps::lines(null))->toBe([]);
    });
});

describe('quick_links', function (): void {
    it('renders tiles with the glyph, the label and a safe link, and skips the rest', function (): void {
        $html = staticBlock('quick_links', ['heading' => 'Lối tắt <b>', 'items' => [
            ['icon' => 'phone', 'label' => 'Gọi ngay', 'url' => '/lien-he'],
            ['icon' => 'nope', 'label' => 'Không biểu tượng', 'url' => 'https://example.com'],
            ['icon' => 'map', 'label' => 'Link xấu', 'url' => 'javascript:alert(1)'],
            ['icon' => 'map', 'label' => '', 'url' => '/a'],
        ]]);

        expect($html)->toContain('<h2 class="sf-section-head__title">Lối tắt &lt;b&gt;</h2>')
            ->and($html)->toContain('<a class="sf-qlinks__tile" href="/lien-he">')
            ->and($html)->toContain('Gọi ngay')->toContain('Không biểu tượng')
            ->and($html)->not->toContain('javascript')->not->toContain('Link xấu')
            ->and(substr_count($html, 'class="sf-qlinks__tile"'))->toBe(2)
            ->and($html)->toContain('<svg class="sf-icon"');
    });

    it('draws the chosen variant, defaulting to the 2x2 grid', function (string $variant, string $expected): void {
        $items = repeatItem(['icon' => 'phone', 'label' => 'Gọi', 'url' => '/lien-he'], 3);

        expect(staticBlock('quick_links', ['variant' => $variant, 'items' => $items]))->toContain($expected);
    })->with([
        'default' => ['', 'sf-qlinks__list--grid-2x2'],
        'unknown' => ['nope', 'sf-qlinks__list--grid-2x2'],
        'row' => ['row', 'sf-qlinks__list--row sf-qlinks__list--n3'],
        'pill' => ['pill', 'sf-qlinks__list--pill'],
    ]);

    it('caps a row at four tiles across', function (): void {
        expect(staticBlock('quick_links', ['variant' => 'row', 'items' => repeatItem(['label' => 'A', 'url' => '/a'], 6)]))->toContain('sf-qlinks__list--n4');
    });

    it('renders nothing without a usable tile', function (): void {
        expect(trim(staticBlock('quick_links', ['heading' => 'Trống'])))->toBe('');
    });
});

describe('gallery', function (): void {
    it('renders lazy sized pictures with the caption, in the chosen column count', function (): void {
        $url = 'https://files.test/s/library/a.webp?w=1280';
        $html = staticBlock('gallery', ['columns' => '4', 'images' => [['image' => $url, 'caption' => 'Xưởng <i>1</i>'], ['image' => null, 'caption' => 'Mất ảnh']]], [$url => 'https://files.test/s/library/a.webp?w=640 640w']);

        expect($html)->toContain('sf-gallery__list--4')
            ->and($html)->toMatch('#<img class="sf-gallery__img" src="https://files\.test/s/library/a\.webp\?w=1280" srcset="[^"]+" sizes="[^"]+" alt="" width="800" height="600" loading="lazy"#')
            ->and($html)->toContain('<figcaption class="sf-gallery__caption">Xưởng &lt;i&gt;1&lt;/i&gt;</figcaption>')
            ->and($html)->not->toContain('Mất ảnh');
    });

    it('falls back to three columns for an unknown value and renders nothing without a picture', function (): void {
        $url = 'https://files.test/a.webp';
        expect(staticBlock('gallery', ['columns' => '9', 'images' => [['image' => $url]]]))->toContain('sf-gallery__list--3')
            ->and(trim(staticBlock('gallery', ['images' => [['image' => null]]])))->toBe('');
    });

    it('keeps alt empty under a caption and uses the library alt text, escaped, without one', function (): void {
        $a = 'https://files.test/a.webp';
        $b = 'https://files.test/b.webp';
        $c = 'https://files.test/c.webp';
        $html = staticBlock('gallery', ['images' => [
            ['image' => $a, 'caption' => 'Có chú thích'],
            ['image' => $b],
            ['image' => $c],
        ]], [], [$a => 'Không dùng', $b => 'Xưởng "CNC" <b>']);

        expect($html)->toMatch('#src="https://files\.test/a\.webp"[^>]* alt="" #')
            ->and($html)->toMatch('#src="https://files\.test/b\.webp"[^>]* alt="Xưởng &quot;CNC&quot; &lt;b&gt;" #')
            ->and($html)->toMatch('#src="https://files\.test/c\.webp"[^>]* alt="" #')
            ->and($html)->not->toContain('Không dùng');
    });

    it('prints no caption element when the caption is empty', function (): void {
        expect(staticBlock('gallery', ['images' => [['image' => 'https://files.test/a.webp', 'caption' => '  ']]]))->not->toContain('figcaption');
    });

    it('links every picture to itself for the photo viewer, named by its caption, its alt text or neither', function (): void {
        $a = 'https://files.test/a.webp?w=1280';
        $b = 'https://files.test/b.webp';
        $c = 'https://files.test/c.webp';
        $html = staticBlock('gallery', ['images' => [
            ['image' => $a, 'caption' => 'Xưởng "A" <b>'],
            ['image' => $b],
            ['image' => $c],
        ]], [], [$b => 'Máy phay']);

        expect($html)->toContain('<a class="sf-gallery__zoom" href="https://files.test/a.webp?w=1280" data-sf-zoom="gallery" aria-label="Xem ảnh lớn: Xưởng &quot;A&quot; &lt;b&gt;">')
            ->and($html)->toContain('<a class="sf-gallery__zoom" href="https://files.test/b.webp" data-sf-zoom="gallery" aria-label="Xem ảnh lớn: Máy phay">')
            ->and($html)->toContain('<a class="sf-gallery__zoom" href="https://files.test/c.webp" data-sf-zoom="gallery" aria-label="Xem ảnh lớn">')
            ->and(preg_match_all('#<a class="sf-gallery__zoom"[^>]*>\s*<img class="sf-gallery__img"[^>]*>\s*</a>#', $html))->toBe(3);
    });
});

describe('faq', function (): void {
    it('renders native details with the question and an escaped answer, no script', function (): void {
        $html = staticBlock('faq', ['heading' => 'Hỏi đáp', 'columns' => '2', 'items' => [
            ['q' => 'Giao <hàng>?', 'a' => "Có <script>x</script>\nTrong 2 ngày"],
            ['q' => 'Không có đáp án', 'a' => ''],
            ['q' => '', 'a' => 'Mồ côi'],
        ]]);

        expect($html)->toContain('sf-faq__list--2')
            ->and(substr_count($html, '<details class="sf-faq__item"'))->toBe(2)
            ->and(substr_count($html, ' open'))->toBe(1)
            ->and($html)->toContain('<details class="sf-faq__item" open>')
            ->and($html)->toContain('<span class="sf-faq__q-text">Giao &lt;hàng&gt;?</span>')
            ->and($html)->toContain('Có &lt;script&gt;x&lt;/script&gt;
Trong 2 ngày')
            ->and($html)->not->toContain('<script')->not->toContain('Mồ côi')
            ->and($html)->toContain('<h2 class="sf-section-head__title">Hỏi đáp</h2>');
    });

    it('renders nothing without a question', function (): void {
        expect(trim(staticBlock('faq', ['items' => [['q' => '', 'a' => 'x']]])))->toBe('');
    });
});

describe('testimonial', function (): void {
    it('renders the quote with its author, role and avatar, all escaped', function (): void {
        $avatar = 'https://files.test/s/library/av.webp?w=1280';
        $html = staticBlock('testimonial', ['items' => [
            ['quote' => 'Rất <b>tốt</b>', 'name' => 'Anh "An"', 'role' => 'Giám đốc', 'avatar' => $avatar],
            ['quote' => 'Chỉ có lời', 'name' => '', 'role' => '', 'avatar' => null],
            ['quote' => '', 'name' => 'Không lời'],
        ]]);

        expect($html)->toContain('Rất &lt;b&gt;tốt&lt;/b&gt;')
            ->and($html)->toContain('<span class="sf-quote__name">Anh &quot;An&quot;</span>')
            ->and($html)->toContain('<span class="sf-quote__role">Giám đốc</span>')
            ->and($html)->toMatch('#<img class="sf-quote__avatar" src="https://files\.test/s/library/av\.webp\?w=1280"[^>]* width="96" height="96" loading="lazy"#')
            ->and(substr_count($html, '<figure class="sf-quote">'))->toBe(2)
            ->and($html)->not->toContain('Không lời')
            ->and(substr_count($html, '<figcaption'))->toBe(1);
    });
});

describe('testimonial layout', function (): void {
    it('shows a quote glyph, the italic text and an initial when there is no avatar', function (): void {
        $html = staticBlock('testimonial', ['items' => [['quote' => 'Tốt', 'name' => 'ánh Mai', 'role' => 'Chủ xưởng'], ['quote' => 'Hai', 'name' => '', 'role' => 'Khách']]]);

        expect($html)->toContain('sf-quote__mark')->toContain('<svg class="sf-icon"')
            ->and($html)->toContain('<span class="sf-quote__avatar sf-quote__avatar--initial" aria-hidden="true">Á</span>')
            ->and(substr_count($html, 'sf-quote__avatar--initial'))->toBe(1)
            ->and($html)->not->toContain('<img');
    });

    it('draws two columns by default and three on request, never more than the quotes', function (): void {
        $two = repeatItem(['quote' => 'Q'], 2);

        expect(staticBlock('testimonial', ['items' => repeatItem(['quote' => 'Q'], 5)]))->toContain('sf-quotes__list--grid-2 sf-quotes__list--n2')
            ->and(staticBlock('testimonial', ['variant' => 'grid-3', 'items' => repeatItem(['quote' => 'Q'], 5)]))->toContain('sf-quotes__list--grid-3 sf-quotes__list--n3')
            ->and(staticBlock('testimonial', ['variant' => 'grid-3', 'items' => $two]))->toContain('sf-quotes__list--n2')
            ->and(staticBlock('testimonial', ['variant' => 'x', 'items' => $two]))->toContain('sf-quotes__list--grid-2');
    });
});

describe('team', function (): void {
    it('puts each name in a heading one level under the section heading', function (): void {
        $photo = 'https://files.test/s/library/p.webp?w=1280';
        $html = staticBlock('team', ['heading' => 'Đội ngũ', 'members' => [['name' => 'Bình <x>', 'role' => 'Kỹ sư', 'photo' => $photo], ['name' => '', 'role' => 'Vô danh']]]);

        expect($html)->toContain('<h2 class="sf-section-head__title">Đội ngũ</h2>')
            ->and($html)->toContain('<h3 class="sf-member__name">Bình &lt;x&gt;</h3>')
            ->and($html)->toContain('<p class="sf-member__role">Kỹ sư</p>')
            ->and($html)->toMatch('#<img class="sf-member__photo" src="[^"]+"[^>]* width="168" height="168" loading="lazy"#')
            ->and($html)->not->toContain('Vô danh');
    });

    it('uses h2 for the names when the section has no heading, and renders a member without a photo', function (): void {
        $html = staticBlock('team', ['members' => [['name' => 'Bình', 'photo' => null]]]);

        expect($html)->toContain('<h2 class="sf-member__name">Bình</h2>')->not->toContain('<img');
    });

    it('shows the uppercase first letter on a tinted disc when a member has no photo', function (): void {
        $html = staticBlock('team', ['members' => [['name' => 'ơn Hải'], ['name' => 'Bình', 'photo' => 'https://files.test/p.webp']]]);

        expect($html)->toContain('<span class="sf-member__photo sf-member__photo--initial" aria-hidden="true">Ơ</span>')
            ->and(substr_count($html, 'sf-member__photo--initial'))->toBe(1)
            ->and($html)->toContain('<img class="sf-member__photo"');
    });

    it('draws three columns by default and four on request, never more than the members', function (): void {
        $members = fn (int $n): array => repeatItem(['name' => 'A'], $n);

        expect(staticBlock('team', ['members' => $members(8)]))->toContain('sf-team__list--grid-3 sf-team__list--n3')
            ->and(staticBlock('team', ['variant' => 'grid-4', 'members' => $members(8)]))->toContain('sf-team__list--grid-4 sf-team__list--n4')
            ->and(staticBlock('team', ['variant' => 'grid-4', 'members' => $members(2)]))->toContain('sf-team__list--n2');
    });
});

describe('process', function (): void {
    it('renders the steps as an ordered list with titles one level under the heading', function (): void {
        $html = staticBlock('process', ['heading' => 'Quy trình', 'steps' => [
            ['title' => 'Tư vấn <1>', 'text' => 'Gặp khách'], ['title' => 'Thiết kế', 'text' => ''], ['title' => 'Sản xuất'], ['title' => 'Giao hàng', 'text' => 'Tận nơi'],
        ]]);

        expect($html)->toContain('<ol class="sf-steps__list sf-steps__list--4">')
            ->and(substr_count($html, '<li class="sf-step">'))->toBe(4)
            ->and($html)->toContain('<h3 class="sf-step__title">Tư vấn &lt;1&gt;</h3>')
            ->and(substr_count($html, 'class="sf-step__text"'))->toBe(2);
    });

    it('renders nothing under three usable steps and never more than six', function (): void {
        expect(trim(staticBlock('process', ['steps' => [['title' => 'A'], ['title' => 'B'], ['title' => '']]])))->toBe('')
            ->and(substr_count(staticBlock('process', ['steps' => repeatItem(['title' => 'A'], 9)]), '<li class="sf-step">'))->toBe(6);
    });
});

describe('pricing', function (): void {
    it('prints the price in Vietnamese format, "Liên hệ" when empty, features per line and a safe button', function (): void {
        $html = staticBlock('pricing', ['heading' => 'Bảng giá', 'plans' => [
            ['name' => 'Cơ bản <b>', 'price' => 1500000, 'unit' => '/ tháng', 'features' => "Một\n\n  Hai <i>\nBa", 'cta_label' => 'Đăng ký', 'cta_url' => '/lien-he'],
            ['name' => 'Doanh nghiệp', 'price' => '', 'unit' => '/ tháng', 'features' => '', 'cta_label' => 'Gọi', 'cta_url' => 'javascript:alert(1)'],
            ['name' => 'Tuỳ chỉnh', 'price' => 0],
        ]]);

        expect($html)->toContain('sf-plans--3')
            ->and($html)->toContain('<h3 class="sf-plan__name">Cơ bản &lt;b&gt;</h3>')
            ->and($html)->toContain("<span class=\"sf-plan__amount\">1.500.000\u{A0}₫</span>")
            ->and($html)->toContain('<span class="sf-plan__unit">/ tháng</span>')
            ->and(substr_count($html, 'Liên hệ'))->toBe(2)
            ->and($html)->toContain('<li>Hai &lt;i&gt;</li>')
            ->and(substr_count($html, '<ul class="sf-plan__features">'))->toBe(1)
            ->and($html)->toContain('<a class="sf-view-all__link sf-plan__cta sf-plan__cta--outline" href="/lien-he">Đăng ký</a>')
            ->and($html)->not->toContain('javascript')
            ->and(substr_count($html, 'sf-plan__cta '))->toBe(1);
    });

    it('features the second plan only when there are three or more', function (): void {
        $plan = fn (string $name): array => ['name' => $name, 'price' => 1000, 'cta_label' => 'Chọn', 'cta_url' => '/lien-he'];
        $three = staticBlock('pricing', ['plans' => [$plan('A'), $plan('B'), $plan('C')]]);
        $two = staticBlock('pricing', ['plans' => [$plan('A'), $plan('B')]]);

        expect(substr_count($three, 'sf-plan--featured'))->toBe(1)
            ->and($three)->toMatch('#<li class="sf-plan sf-plan--featured">\s*<h2 class="sf-plan__name">B</h2>#')
            ->and(substr_count($three, 'sf-plan__cta--filled'))->toBe(1)
            ->and(substr_count($three, 'sf-plan__cta--outline'))->toBe(2)
            ->and($two)->not->toContain('sf-plan--featured')->not->toContain('sf-plan__cta--filled')
            ->and(substr_count($two, 'sf-plan__cta--outline'))->toBe(2);
    });

    it('does not count an unnamed plan when picking the featured one', function (): void {
        $html = staticBlock('pricing', ['plans' => [['name' => 'A'], ['name' => ''], ['name' => 'C']]]);

        expect($html)->not->toContain('sf-plan--featured');
    });

    it('shows no unit next to "Liên hệ"', function (): void {
        $html = staticBlock('pricing', ['plans' => [['name' => 'A', 'price' => null, 'unit' => '/ tháng'], ['name' => 'B']]]);

        expect($html)->not->toContain('sf-plan__unit');
    });

    it('renders nothing with fewer than two named plans and never more than four', function (): void {
        expect(trim(staticBlock('pricing', ['plans' => [['name' => 'A'], ['name' => '']]])))->toBe('')
            ->and(substr_count(staticBlock('pricing', ['plans' => repeatItem(['name' => 'A'], 7)]), '<li class="sf-plan'))->toBe(4);
    });
});

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
    });

    /** @param  list<array<string, mixed>>  $blocks  "@pic" stands for a fresh media id */
    function staticPage(string $path, array $blocks): string
    {
        app(Tenancy::class)->enter(test()->siteId, function () use ($path, $blocks): void {
            $n = 0;
            $json = (string) preg_replace_callback('/"@pic"/', function () use ($path, &$n): string {
                return (string) StorefrontCards::media('pic'.str_replace('/', '-', $path).'-'.(++$n))->id;
            }, (string) json_encode($blocks));
            StorefrontCards::page($path, 'Giới thiệu', json_decode($json, true));
        });

        return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    it('resolves the pictures of every block from the library', function (): void {
        $html = staticPage('/khoi', [
            ['type' => 'gallery', 'props' => ['heading' => 'Ảnh', 'images' => [['image' => '@pic', 'caption' => 'Một']]]],
            ['type' => 'team', 'props' => ['members' => [['name' => 'An', 'photo' => '@pic']]]],
            ['type' => 'testimonial', 'props' => ['items' => [['quote' => 'Tốt', 'avatar' => '@pic']]]],
        ]);

        expect(preg_match_all('#<img class="sf-(?:gallery__img|member__photo|quote__avatar)" src="https://files\.test/s/library/pic-khoi-\d-[0-9a-f]{12}\.webp\?w=1280" srcset="[^"]+"#', $html))->toBe(3);
    });

    it('gives a gallery picture without a caption the library alt text of the page locale', function (): void {
        [$viId, $enOnlyId, $bareId, $captionedId] = app(Tenancy::class)->enter(test()->siteId, function (): array {
            $make = function (string $slug, array $alt): int {
                $media = StorefrontCards::media($slug);
                $media->forceFill(['alt' => $alt])->save();

                return $media->id;
            };

            return [
                $make('alt-vi', ['vi' => 'Máy phay <CNC>', 'en' => 'CNC mill']),
                $make('alt-other', ['en' => 'Only English']),
                $make('alt-none', []),
                $make('alt-cap', ['vi' => 'Bị bỏ']),
            ];
        });
        $html = staticPage('/alt', [['type' => 'gallery', 'props' => ['images' => [
            ['image' => $viId], ['image' => $enOnlyId], ['image' => $bareId], ['image' => $captionedId, 'caption' => 'Chú thích'],
        ]]]]);

        expect($html)->toContain('alt="Máy phay &lt;CNC&gt;"')
            ->and($html)->not->toContain('Bị bỏ')
            ->and(preg_match_all('#class="sf-gallery__img"[^>]* alt="" #', $html))->toBe(3);
    });

    it('loads the photo viewer and its translated frame only on a page with a gallery picture', function (): void {
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
        $with = staticPage('/anh', [['type' => 'gallery', 'props' => ['images' => [['image' => '@pic', 'caption' => 'Một']]]]]);
        $without = staticPage('/chu', [['type' => 'richtext', 'props' => ['heading' => 'Một', 'paragraphs' => ['Bài nhắc tới <a data-sf-zoom="gallery"> bằng chữ.']]]]);
        $empty = staticPage('/rong', [['type' => 'gallery', 'props' => ['images' => [['image' => null, 'caption' => 'Mất ảnh']]]]]);

        expect($with)->toContain('<script type="module" src="/resources/js/sf-viewer.js"></script>')
            ->and($with)->toContain('<template id="sf-viewer"><dialog class="sf-viewer" aria-label="Xem ảnh">')
            ->and($with)->toContain('aria-label="Ảnh trước"')->and($with)->toContain('aria-label="Ảnh sau"')->and($with)->toContain('aria-label="Đóng"')
            ->and($without)->not->toContain('sf-viewer')
            ->and($empty)->not->toContain('sf-viewer');
    });

    it('resolves every picture of the page in the one media lookup', function (): void {
        $gallery = fn (int $pictures): array => ['type' => 'gallery', 'props' => ['images' => repeatItem(['image' => '@pic', 'caption' => 'x'], $pictures)]];
        $team = fn (int $pictures): array => ['type' => 'team', 'props' => ['members' => repeatItem(['name' => 'An', 'photo' => '@pic'], $pictures)]];
        staticPage('/mot', [$gallery(1), $team(1)]);
        staticPage('/nhieu', [$gallery(6), $team(6), $gallery(3)]);

        $one = countQueries(fn () => test()->get('http://mau.platform.test/mot')->assertOk());
        $many = countQueries(fn () => test()->get('http://mau.platform.test/nhieu')->assertOk());

        expect($many)->toBe($one);
    });

    it('renders the blocks without pictures with no queries of their own', function (): void {
        $all = [
            ['type' => 'quick_links', 'props' => ['items' => [['icon' => 'phone', 'label' => 'Gọi', 'url' => '/lien-he']]]],
            ['type' => 'faq', 'props' => ['items' => [['q' => 'A?', 'a' => 'B']]]],
            ['type' => 'process', 'props' => ['steps' => repeatItem(['title' => 'A'], 3)]],
            ['type' => 'pricing', 'props' => ['plans' => repeatItem(['name' => 'A', 'price' => 1000], 2)]],
        ];
        staticPage('/thuong', [['type' => 'richtext', 'props' => ['heading' => 'Một']]]);
        $html = staticPage('/day', $all);

        $base = countQueries(fn () => test()->get('http://mau.platform.test/thuong')->assertOk());
        $full = countQueries(fn () => test()->get('http://mau.platform.test/day')->assertOk());

        expect($html)->toContain('sf-qlinks__tile')->toContain('sf-faq__item')->toContain('sf-step__title')->toContain('sf-plan__name')
            ->and($full)->toBe($base);
    });
});

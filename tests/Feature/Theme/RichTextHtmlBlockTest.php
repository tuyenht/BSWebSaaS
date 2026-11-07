<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\ComponentRegistry;
use App\Modules\Theme\Rendering\RichHtml;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * The formatted-text block (`richtext_html`, ADR-19) on the storefront: its HTML is printed as
 * HTML, after the renderer turns each library picture into a responsive image (from the page's
 * one media lookup, the id never printed) and each video frame into the click-to-load façade.
 * The block is not offered in the builder's add list yet — content packs fill it.
 */

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
        // Print each Vite entry as a module script so the head's choices are visible.
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    /**
     * Publish a page whose blocks $build returns (called inside the site, so it may create media),
     * then fetch it.
     *
     * @param  Closure(): list<array<string, mixed>>  $build
     */
    function richPage(string $path, Closure $build): string
    {
        app(Tenancy::class)->enter(test()->siteId, function () use ($path, $build): void {
            StorefrontCards::page($path, 'Giới thiệu', $build());
        });

        return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    function richBlock(string $html): array
    {
        return ['type' => 'richtext_html', 'props' => ['html' => $html]];
    }

    /** The printed section of the block. */
    function proseOf(string $page): string
    {
        expect(preg_match('~<section class="sf-prose">(.*?)</section>~s', $page, $m))->toBe(1);

        return trim($m[1]);
    }

    it('prints the formatted text, with a library picture as a responsive image', function (): void {
        $page = richPage('/gioi-thieu', function (): array {
            $id = StorefrontCards::media('xuong')->id;

            return [richBlock('<h2>Câu chuyện</h2><p>Đoạn <strong>đậm</strong> và <a href="/lien-he">liên hệ</a>.</p>'
                ."<figure><img data-media-id=\"{$id}\" data-size=\"md\" data-align=\"left\" alt=\"Máy phay\"><figcaption>Xưởng CNC</figcaption></figure>")];
        });
        $prose = proseOf($page);

        expect($prose)->toContain('<h2>Câu chuyện</h2><p>Đoạn <strong>đậm</strong> và <a href="/lien-he">liên hệ</a>.</p>')
            ->and($prose)->toMatch('#<figure><img class="sf-prose__img sf-prose__img--md sf-prose__img--left" src="https://files\.test/s/library/xuong-[0-9a-f]{12}\.webp\?w=\d+" srcset="[^"]+ \d+w[^"]*" sizes="\(min-width: 52rem\) 24rem, 50vw" alt="Máy phay" width="1600" height="900" loading="lazy" decoding="async"><figcaption>Xưởng CNC</figcaption></figure>#')
            ->and($page)->not->toContain('data-media-id')
            ->and($page)->not->toContain('data-size')
            ->and($page)->not->toContain('sf-video.js');
    });

    it('lets a keyboard reach a table, so a wide one can be scrolled', function (): void {
        $prose = proseOf(richPage('/bang', fn (): array => [richBlock('<table><tbody><tr><td>Máy</td><td>4</td></tr></tbody></table>')]));

        expect($prose)->toBe('<table tabindex="0"><tbody><tr><td>Máy</td><td>4</td></tr></tbody></table>');
    });

    it('defaults a picture to full width, centred, with the library alt text escaped', function (): void {
        $prose = proseOf(richPage('/anh', function (): array {
            $media = StorefrontCards::media('nha-may');
            $media->update(['alt' => ['vi' => 'Nhà máy "An" <1>']]);

            return [richBlock("<p><img data-media-id=\"{$media->id}\"></p>")];
        }));

        expect($prose)->toMatch('#<img class="sf-prose__img sf-prose__img--full sf-prose__img--center" src="[^"]+" srcset="[^"]+" sizes="\(min-width: 52rem\) 48rem, 100vw" alt="Nhà máy &quot;An&quot; &lt;1&gt;" width="1600" height="900"#');
    });

    it('prints the library alt for a picture stored with an address and no alt', function (): void {
        $prose = proseOf(richPage('/anh-co-dia-chi', function (): array {
            $media = StorefrontCards::media('xuong-moi');
            $media->update(['alt' => ['vi' => 'Xưởng mới']]);

            return [richBlock("<p><img data-media-id=\"{$media->id}\" src=\"https://evil.test/x.png\"></p>")];
        }));

        expect($prose)->toContain('alt="Xưởng mới"')
            ->and($prose)->not->toContain('x.png')
            ->and($prose)->not->toContain('evil.test');
    });

    it('drops a picture that is not in this site\'s library, and its empty figure', function (): void {
        $otherSite = StorefrontCards::site('Xưởng Khác', 'khac.platform.test');
        $foreign = app(Tenancy::class)->enter($otherSite, fn (): int => StorefrontCards::media('cua-site-khac')->id);

        $prose = proseOf(richPage('/mat-anh', fn (): array => [richBlock(
            "<p>Trước</p><figure><img data-media-id=\"{$foreign}\" alt=\"x\"><figcaption>Chú thích</figcaption></figure>"
            .'<p>Giữa<img data-media-id="999999" alt="y">sau</p>',
        )]));

        expect($prose)->toBe('<p>Trước</p><p>Giữasau</p>');
    });

    it('turns a video frame into the click-to-load façade and loads the video script', function (): void {
        $page = richPage('/video', fn (): array => [richBlock(
            '<p>Xem</p><div class="video-embed"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo" allowfullscreen></iframe></div>',
        )]);

        expect($page)->toContain('<script type="module" src="/resources/js/sf-video.js"></script>')
            ->and(proseOf($page))->toContain('data-sf-video')
            ->and($page)->not->toContain('<iframe');
    });

    it('keeps printing every other block\'s text escaped', function (): void {
        $page = richPage('/thuong', fn (): array => [
            ['type' => 'richtext', 'props' => ['heading' => '<b>Tiêu đề</b>']],
            richBlock('<p><b>đậm</b></p>'),
        ]);

        expect($page)->toContain('&lt;b&gt;Tiêu đề&lt;/b&gt;')
            ->and(proseOf($page))->toBe('<p><b>đậm</b></p>');
    });

    it('prints nothing for an empty block', function (): void {
        expect(richPage('/rong', fn (): array => [richBlock('  '), ['type' => 'richtext_html', 'props' => ['html' => null]]]))
            ->not->toContain('sf-prose');
    });

    it('still prints no script when an attack reached the database round the model', function (): void {
        // a clean block first, then the stored value overwritten as is, past the model and its cast
        $page = richPage('/tan-cong', fn (): array => [richBlock('<p>Sạch</p>')]);
        expect($page)->toContain('sf-prose');

        app(Tenancy::class)->enter($this->siteId, function (): void {
            $translation = EntryTranslation::query()->where('slug', 'tan-cong')->firstOrFail();
            $body = DB::table('entry_translations')->where('id', $translation->id)->value('body');
            $body = json_decode((string) $body, true);
            $body['blocks'][0]['props'] = ['html' => '<p onclick="steal()">Chào <a href="javascript:alert(1)">bấm</a></p><script>alert(1)</script>'
                .'<img src="x" onerror="alert(2)"><svg onload="alert(3)"></svg><iframe src="https://evil.test/x"></iframe>'
                .'<a href="&#106;avascript:alert(4)">b</a><p style="background:url(javascript:alert(5))">s</p>'
                .'<!-- <script>alert(7)</script> --><p class="sf-hero">c</p>'];
            DB::table('entry_translations')->where('id', $translation->id)->update(['body' => json_encode($body)]);
        });

        $prose = proseOf(test()->get('http://mau.platform.test/tan-cong')->assertOk()->getContent());

        expect(strtolower($prose))->not->toContain('<script')
            ->not->toContain('javascript')
            ->not->toContain('avascript')
            ->not->toContain('<svg')
            ->not->toContain('evil.test')
            ->not->toContain('url(')
            ->not->toContain('<!--')
            ->not->toContain('sf-hero')
            ->and($prose)->not->toMatch('~\son\w+\s*=~i')
            ->and($prose)->toContain('Chào');
    });

    it('resolves every picture of the page in its one media lookup', function (): void {
        $pictures = fn (int $count): Closure => function () use ($count): array {
            $html = '';
            for ($i = 1; $i <= $count; $i++) {
                $id = StorefrontCards::media('anh-'.$count.'-'.$i)->id;
                $html .= "<figure><img data-media-id=\"{$id}\" alt=\"{$i}\"><figcaption>{$i}</figcaption></figure>";
            }

            return [richBlock($html), richBlock('<p>Không ảnh</p>')];
        };
        richPage('/mot', $pictures(1));
        $many = richPage('/nhieu', $pictures(6));

        expect(substr_count($many, 'sf-prose__img--full'))->toBe(6);

        $one = countQueries(fn () => test()->get('http://mau.platform.test/mot')->assertOk());
        $six = countQueries(fn () => test()->get('http://mau.platform.test/nhieu')->assertOk());

        expect($six)->toBe($one);
    });

    it('costs no query of its own without pictures', function (): void {
        richPage('/thuong', fn (): array => [['type' => 'richtext', 'props' => ['heading' => 'Một']]]);
        richPage('/van-ban', fn (): array => [['type' => 'richtext', 'props' => ['heading' => 'Một']], richBlock('<h2>A</h2><p>B</p>'), richBlock('<ul><li>C</li></ul>')]);

        $base = countQueries(fn () => test()->get('http://mau.platform.test/thuong')->assertOk());
        $rich = countQueries(fn () => test()->get('http://mau.platform.test/van-ban')->assertOk());

        expect($rich)->toBe($base);
    });
});

describe('the renderer', function (): void {
    it('accepts the sanitizer\'s own output as clean and refuses anything else', function (string $html, bool $clean): void {
        expect(RichHtml::looksSanitized($html))->toBe($clean);
    })->with([
        'plain text' => ['Chào', true],
        'sanitized article' => ['<h2>T</h2><p style="text-align:center">a &amp; b</p><figure><img data-media-id="7" data-size="sm" alt="&quot;x&quot;" /><figcaption>c</figcaption></figure>', true],
        'video wrapper' => ['<div class="video-embed"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo" allowfullscreen="allowfullscreen"></iframe></div>', true],
        'script' => ['<p>a</p><script>alert(1)</script>', false],
        'event handler' => ['<p onclick="x()">a</p>', false],
        'unquoted attribute' => ['<p title=x>a</p>', false],
        'script link' => ['<a href="javascript:alert(1)">x</a>', false],
        'encoded script link' => ['<a href="&#106;avascript:alert(1)">x</a>', false],
        'style url' => ['<p style="background:url(x)">a</p>', false],
        'other class' => ['<p class="sf-hero">a</p>', false],
        'comment' => ['<!-- x --><p>a</p>', false],
        'stray angle bracket' => ['<p>a < b</p>', false],
        'img with an address' => ['<img src="https://evil.test/x.png" />', false],
        'style off the list' => ['<p style="position:fixed;inset:0;z-index:99999;background-color:white">a</p>', false],
        'a vertical tab between attributes' => ["<p\x0Bstyle=\"color:red\">a</p>", false],
        'a frame from another host' => ['<iframe src="https://evil.test/x"></iframe>', false],
        'a frame from a video player' => ['<div class="video-embed"><iframe src="https://www.youtube.com/embed/abc"></iframe></div>', true],
        'a vendor keyword' => ['<p style="text-align:-webkit-center">a</p>', false],
        'style function' => ['<span style="background-image:image-set(&quot;https://evil.test/x.png&quot; 1x)">x</span>', false],
        'style value with quotes' => ['<p style="font-size:&quot;x&quot;;">a</p>', false],
        'style value with an escape' => ['<p style="color:\\72 ed;">a</p>', false],
        'style value naming url' => ['<p style="color:url;">a</p>', false],
        'style without a value' => ['<p style="color;">a</p>', false],
        'stray closers' => ['</p></div></div></div><p>outside</p>', false],
        'left open' => ['<p><a href="https://evil.test">unclosed</p>', false],
        'never closed' => ['<p>a', false],
        'caption outside a figure' => ['<figcaption>x</figcaption>', false],
        'link in a link' => ['<a href="/a"><a href="/b">x</a></a>', false],
        'link deep in a link' => ['<a href="/a"><b><a href="/b">x</a></b></a>', false],
        'block in a paragraph' => ['<p><div>x</div></p>', false],
        'block in an inline' => ['<strong><p>x</p></strong>', false],
        'list item outside a list' => ['<li>x</li>', false],
        'cell outside a row' => ['<table><td>x</td></table>', false],
        'text loose in a list' => ['<ul>x<li>a</li></ul>', false],
        'tag inside a frame' => ['<iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"><p>x</p></iframe>', false],
        'closed void' => ['<p>a<br></br></p>', false],
        'self-closed paragraph' => ['<p />', false],
        'unclosed void' => ['<p>a<br>b</p>', false],
    ]);

    it('prints what the sanitizer would for markup it does not recognise as clean', function (string $payload, string $gone): void {
        $clean = app(RichTextSanitizer::class)->cms($payload);
        $printed = app(RichHtml::class)->render($payload, [])['html'];

        expect(RichHtml::looksSanitized($payload))->toBeFalse()
            ->and($printed)->toBe($clean)
            ->and($printed)->not->toContain($gone);
    })->with([
        'fixed overlay' => ['<p style="position:fixed;inset:0;z-index:99999;background-color:white">Fake login</p>', 'position'],
        'image-set' => ['<span style="background-image:image-set(&quot;https://evil.test/x.png&quot; 1x)">x</span>', 'image-set'],
        'closes the theme\'s wrappers' => ['</p></div></div></div><p>outside</p>', '</div>'],
        'unclosed link' => ['<p><a href="https://evil.test">unclosed</p>', '<p><a href="https://evil.test">unclosed</p>'],
        'caption outside a figure' => ['<figcaption>x</figcaption>', 'figcaption'],
        'link in a link' => ['<a href="/a"><a href="/b">x</a></a>', '<a href="/a"><a'],
    ]);

    it('drops only the figures whose picture was dropped', function (): void {
        $printed = app(RichHtml::class)->render(
            '<figure><figcaption>Only text</figcaption></figure><p><img data-media-id="999999" /></p>'
            .'<figure><img data-media-id="999998" /><figcaption>Mất ảnh</figcaption></figure>'
            .'<figure><img data-media-id="999997" /><img data-media-id="5" /></figure>',
            [5 => 'https://files.test/s/library/a.webp'],
        )['html'];

        expect($printed)->toStartWith('<figure><figcaption>Only text</figcaption></figure><p></p><figure><img class="sf-prose__img')
            ->and($printed)->not->toContain('Mất ảnh')
            ->and(substr_count($printed, '<figure>'))->toBe(2)
            ->and($printed)->not->toContain('<!--');
    });

    it('lists the library ids the pictures name, once each', function (): void {
        expect(RichHtml::imageIds('<img data-media-id="5" /><p>x</p><img alt="a" data-media-id="5" /><img data-media-id="12" /><img data-media-id="0" />'))
            ->toBe([5, 12])
            ->and(RichHtml::imageIds('<p>no pictures</p>'))->toBe([]);
    });
});

describe('the builder', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
    });

    it('does not offer the block in the add list, and refuses to add it', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $editor = User::factory()->create();
            grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);

            Livewire::actingAs($editor->fresh())
                ->test(Editor::class)
                ->assertViewHas('availableTypes', fn (array $types): bool => ! in_array('richtext_html', $types, true) && in_array('richtext', $types, true))
                ->call('addBlock', 'richtext_html')
                ->assertHasErrors('blocks')
                ->assertSet('blocks', []);
        });

        $registry = app(ComponentRegistry::class);
        expect($registry->has('richtext_html'))->toBeTrue()
            ->and($registry->listedTypes())->not->toContain('richtext_html')
            // the formatted-text block and the three blocks only a block slot may hold stay out of the list
            ->and(count($registry->listedTypes()))->toBe(count($registry->types()) - 4);
    });
});

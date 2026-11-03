<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Shared\Support\RichTextSanitizer;
use App\Modules\Shared\Support\VideoEmbed;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\VideoFacades;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * Click-to-load video: the `video` block and the videos already stored in rich text render as a
 * façade — the site's own poster (or a neutral placeholder), a play control and a notice naming the
 * host. Nothing is requested from YouTube or Vimeo before the visitor presses play: the player's
 * address waits in a data attribute and the small video script, loaded only on a page that has a
 * video, builds the iframe on the press. Only addresses VideoEmbed recognises are accepted.
 */

/** @param  list<array<string, mixed>>  $blocks */
function videoBlocksValidator(array $blocks): Illuminate\Validation\Validator
{
    return Validator::make(['blocks' => $blocks], ['blocks' => [app(ValidPageJson::class)]]);
}

/** @param  array<string, mixed>  $props */
function videoSection(array $props, array $srcsets = []): string
{
    app()->setLocale('vi');

    return View::make('theme::sections.video', ['props' => $props, 'srcsets' => $srcsets])->render();
}

/** Every address the browser would fetch on its own (src, srcset, poster, data, link href) — never the anchor's href. */
function fetchedAddresses(string $html): string
{
    preg_match_all('/(?<![\w-])(?:src|srcset|poster|data)="([^"]*)"/', $html, $attributes);
    preg_match_all('/<link\b[^>]*href="([^"]*)"/', $html, $links);

    return implode(' ', [...$attributes[1], ...$links[1]]);
}

function expectNothingFetchedFromTheVideoHost(string $html): void
{
    expect($html)->not->toContain('<iframe')
        ->and(fetchedAddresses($html))->not->toMatch('~youtube|youtu\.be|ytimg|vimeo~i');
}

describe('validation', function (): void {
    it('accepts a youtube or vimeo address, an empty one and no poster', function (mixed $url): void {
        expect(videoBlocksValidator([['type' => 'video', 'props' => ['heading' => 'Nhà máy', 'url' => $url, 'poster' => null]]])->passes())->toBeTrue();
    })->with([
        'youtube watch' => 'https://www.youtube.com/watch?v=SxwkhMy4Ywo',
        'youtu.be' => 'https://youtu.be/SxwkhMy4Ywo',
        'vimeo' => 'https://vimeo.com/123456789',
        'empty' => '',
        'unset' => null,
    ]);

    it('accepts a poster from the media library', function (): void {
        expect(videoBlocksValidator([['type' => 'video', 'props' => ['url' => 'https://youtu.be/SxwkhMy4Ywo', 'poster' => 42]]])->passes())->toBeTrue();
    });

    it('refuses an address that is not a youtube or vimeo video, in plain words', function (string $url): void {
        $validator = videoBlocksValidator([['type' => 'video', 'props' => ['url' => $url]]]);

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('blocks'))->toBe(__('entries.err_block_video_invalid'));
    })->with([
        'a file' => 'https://example.com/video.mp4',
        'youtube without a video' => 'https://www.youtube.com/',
        'vimeo page' => 'https://vimeo.com/about',
        'not an address' => 'xem video',
    ]);

    it('refuses a script address and a poster typed as an address', function (): void {
        expect(videoBlocksValidator([['type' => 'video', 'props' => ['url' => 'javascript:alert(1)']]])->fails())->toBeTrue()
            ->and(videoBlocksValidator([['type' => 'video', 'props' => ['url' => 'https://youtu.be/SxwkhMy4Ywo', 'poster' => 'https://i.ytimg.com/vi/SxwkhMy4Ywo/hqdefault.jpg']]])->fails())->toBeTrue();
    });
});

describe('the block', function (): void {
    it('renders the façade with the player address waiting in a data attribute', function (): void {
        $html = videoSection(['heading' => 'Tham quan nhà máy', 'url' => 'https://www.youtube.com/watch?v=SxwkhMy4Ywo']);

        expect($html)->toContain('<h2 class="sf-section-head__title">Tham quan nhà máy</h2>')
            ->and($html)->toContain('data-sf-video')
            ->and($html)->toContain('data-src="https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&amp;autoplay=1"')
            ->and($html)->toContain('data-title="Tham quan nhà máy"')
            // Without the script the control is a plain link to the video's own page.
            ->and($html)->toMatch('#<a class="sf-video__play" href="https://www\.youtube\.com/watch\?v=SxwkhMy4Ywo">#')
            ->and($html)->toContain('<span class="sf-video__notice">Bấm để xem video trên YouTube</span>');
        expectNothingFetchedFromTheVideoHost($html);
    });

    it('names vimeo in the notice and the frame title when there is no heading', function (): void {
        $html = videoSection(['url' => 'https://vimeo.com/123456789']);

        expect($html)->toContain('Bấm để xem video trên Vimeo')
            ->and($html)->toContain('data-src="https://player.vimeo.com/video/123456789?autoplay=1"')
            ->and($html)->toContain('data-title="Video Vimeo"')
            ->and($html)->not->toContain('<h2');
        expectNothingFetchedFromTheVideoHost($html);
    });

    it('shows the site poster, or a neutral placeholder without one', function (): void {
        $poster = 'https://files.test/s/library/poster.webp?w=1280';
        $html = videoSection(['url' => 'https://youtu.be/SxwkhMy4Ywo', 'poster' => $poster], [$poster => 'https://files.test/s/library/poster.webp?w=640 640w']);

        expect($html)->toMatch('#<img class="sf-video__poster" src="https://files\.test/s/library/poster\.webp\?w=1280" srcset="https://files\.test/s/library/poster\.webp\?w=640 640w" sizes="[^"]+" alt="" width="1600" height="900" loading="lazy" decoding="async">#')
            ->and($html)->not->toContain('sf-video--blank');

        $blank = videoSection(['url' => 'https://youtu.be/SxwkhMy4Ywo', 'poster' => null]);
        expect($blank)->toContain('class="sf-video sf-video--blank"')->and($blank)->not->toContain('<img');
        expectNothingFetchedFromTheVideoHost($blank);
    });

    it('renders nothing without an address it recognises', function (mixed $url): void {
        expect(trim(videoSection(['heading' => 'Video', 'url' => $url])))->toBe('');
    })->with(['', null, 'https://example.com/a.mp4', 'javascript:alert(1)', 42]);

    it('escapes the heading everywhere it is printed', function (): void {
        $html = videoSection(['heading' => '"><script>alert(1)</script>', 'url' => 'https://youtu.be/SxwkhMy4Ywo']);

        expect($html)->not->toContain('<script>')->and($html)->toContain('&quot;&gt;&lt;script&gt;');
    });
});

describe('videos in rich text', function (): void {
    beforeEach(fn () => app()->setLocale('vi'));

    it('turns a stored youtube embed into the façade through nocookie', function (string $stored): void {
        ['html' => $html, 'videos' => $videos] = app(VideoFacades::class)->render('<p>Trước</p>'.$stored.'<p>Sau</p>');

        expect($videos)->toBe(1)
            ->and($html)->toStartWith('<p>Trước</p>')->toEndWith('<p>Sau</p>')
            ->and($html)->toContain('data-src="https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&amp;autoplay=1"')
            ->and($html)->toContain('class="sf-video sf-video--blank"')
            ->and($html)->toContain('Bấm để xem video trên YouTube')
            ->and($html)->not->toContain('video-embed');
        expectNothingFetchedFromTheVideoHost($html);
    })->with([
        'as the editor inserts it' => fn (): string => app(RichTextSanitizer::class)->full((string) VideoEmbed::html('https://www.youtube.com/watch?v=SxwkhMy4Ywo', autoplay: true)),
        'youtube.com/embed' => '<div class="video-embed"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo?rel=0" loading="lazy" allowfullscreen="allowfullscreen"></iframe></div>',
        'bare protocol-relative iframe' => '<iframe src="//www.youtube.com/embed/SxwkhMy4Ywo"></iframe>',
    ]);

    it('turns a stored vimeo embed into the façade', function (): void {
        $stored = app(RichTextSanitizer::class)->full((string) VideoEmbed::html('https://vimeo.com/123456789'));
        ['html' => $html, 'videos' => $videos] = app(VideoFacades::class)->render($stored);

        expect($videos)->toBe(1)
            ->and($html)->toContain('data-src="https://player.vimeo.com/video/123456789?autoplay=1"')
            ->and($html)->toContain('Bấm để xem video trên Vimeo');
        expectNothingFetchedFromTheVideoHost($html);
    });

    it('plays an unlisted vimeo video pasted with its hash', function (): void {
        $stored = app(RichTextSanitizer::class)->full('<iframe src="https://player.vimeo.com/video/123456789?h=abc123def0"></iframe>');
        ['html' => $html] = app(VideoFacades::class)->render($stored);

        expect($html)->toContain('data-src="https://player.vimeo.com/video/123456789?autoplay=1&amp;h=abc123def0"');
    });

    it('lifts a frame out of the paragraph holding it, so the paragraph is not split', function (): void {
        ['html' => $html, 'videos' => $videos] = app(VideoFacades::class)->render('<p>A</p><p><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"></iframe></p><p>B</p>');

        expect($videos)->toBe(1)
            ->and($html)->toStartWith('<p>A</p><div class="sf-video')
            ->and((string) preg_replace('~>\s+<~', '><', $html))->toEndWith('</a></div><p>B</p>')
            ->and($html)->not->toContain('<p><div')->not->toContain('<p></p>');
    });

    it('lifts a frame padded with no-break spaces and line breaks, and survives a huge blank paragraph', function (): void {
        $stored = app(RichTextSanitizer::class)->full('<p>A</p><p>&nbsp;<br><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"></iframe>&nbsp;</p><p>B</p>');
        ['html' => $html, 'videos' => $videos] = app(VideoFacades::class)->render($stored);
        expect($videos)->toBe(1)->and($html)->not->toContain('<p><div')->not->toContain('<p>'."\u{00A0}");

        $huge = '<p>'.str_repeat(' ', 20000).'chữ</p><div class="video-embed"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"></iframe></div>';
        expect(app(VideoFacades::class)->render($huge)['videos'])->toBe(1);
    });

    it('lifts a centred frame out of its styled paragraph too', function (): void {
        $stored = app(RichTextSanitizer::class)->full('<p>A</p><p style="text-align: center;"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"></iframe></p><p>B</p>');
        ['html' => $html, 'videos' => $videos] = app(VideoFacades::class)->render($stored);

        expect($videos)->toBe(1)
            ->and($html)->not->toContain('text-align')->not->toContain('<p></p>')
            ->and((string) preg_replace('~>\s+<~', '><', $html))->toStartWith('<p>A</p><div class="sf-video')->toEndWith('</a></div><p>B</p>');
    });

    it('names each video of a description apart', function (): void {
        $one = '<div class="video-embed"><iframe src="https://www.youtube.com/embed/SxwkhMy4Ywo"></iframe></div>';
        ['html' => $html] = app(VideoFacades::class)->render($one.$one);

        expect($html)->toContain('<span class="sr-only">: Video YouTube 1</span>')
            ->and($html)->toContain('<span class="sr-only">: Video YouTube 2</span>')
            ->and(app(VideoFacades::class)->render($one)['html'])->toContain('<span class="sr-only">: Video YouTube</span>')
            ->and(app(VideoFacades::class)->render($one.'<iframe src="https://evil.example/x"></iframe>')['html'])->toContain('<span class="sr-only">: Video YouTube</span>');
    });

    it('keeps an unknown iframe stripped, on save and on render', function (): void {
        $saved = app(RichTextSanitizer::class)->full('<p>A</p><iframe src="https://evil.example/x"></iframe><p>B</p>');
        expect($saved)->not->toContain('evil.example');

        // Old data from before the sanitizer, rendered anyway: the frame is dropped, the text stays.
        ['html' => $html, 'videos' => $videos] = app(VideoFacades::class)->render('<p>A</p><div class="video-embed"><iframe src="https://evil.example/x"></iframe></div><p>B</p>');
        expect($videos)->toBe(0)->and($html)->toBe('<p>A</p><p>B</p>');
    });

    it('leaves text without a frame exactly as stored', function (): void {
        $stored = '<p>Đồ gá <strong>kiểm tra</strong></p><div class="video-embed"></div>';

        expect(app(VideoFacades::class)->render($stored))->toBe(['html' => $stored, 'videos' => 0]);
    });
});

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

    /** @param  list<array<string, mixed>>  $blocks */
    function videoPage(string $path, array $blocks): string
    {
        app(Tenancy::class)->enter(test()->siteId, function () use ($path, $blocks): void {
            $poster = StorefrontCards::media('poster'.str_replace('/', '-', $path))->id;
            $blocks = json_decode(str_replace('"@poster"', (string) $poster, (string) json_encode($blocks)), true);
            StorefrontCards::page($path, 'Giới thiệu', $blocks);
        });

        return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    it('loads the video script and resolves the poster from the library, with nothing from the host', function (): void {
        $html = videoPage('/video', [['type' => 'video', 'props' => ['heading' => 'Nhà máy', 'url' => 'https://youtu.be/SxwkhMy4Ywo', 'poster' => '@poster']]]);

        expect($html)->toContain('<script type="module" src="/resources/js/sf-video.js"></script>')
            ->and($html)->toMatch('#<img class="sf-video__poster" src="https://files\.test/s/library/poster-video-[0-9a-f]{12}\.webp\?w=1280" srcset="[^"]+"#');
        expectNothingFetchedFromTheVideoHost($html);
    });

    it('loads no video script without a playable video', function (array $blocks): void {
        expect(videoPage('/khong', $blocks))->not->toContain('sf-video.js');
    })->with([
        'no video' => [[['type' => 'richtext', 'props' => ['heading' => 'Một']]]],
        'an address it does not recognise' => [[['type' => 'video', 'props' => ['url' => 'https://example.com/a.mp4']]]],
        'no address' => [[['type' => 'video', 'props' => ['heading' => 'Sắp có']]]],
    ]);

    it('resolves every poster on the page in the one media lookup', function (): void {
        $video = fn (): array => ['type' => 'video', 'props' => ['url' => 'https://youtu.be/SxwkhMy4Ywo', 'poster' => '@poster']];
        videoPage('/mot', [$video()]);
        videoPage('/ba', [$video(), $video(), $video()]);

        $one = countQueries(fn () => test()->get('http://mau.platform.test/mot')->assertOk());
        $three = countQueries(fn () => test()->get('http://mau.platform.test/ba')->assertOk());

        expect($three)->toBe($one);
    });

    it('renders a video stored in a product description as the façade and loads the script', function (): void {
        $path = app(Tenancy::class)->enter($this->siteId, function (): string {
            $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
            $product->translations()->create([
                'locale' => 'vi', 'name' => 'Jig kiểm', 'slug' => 'jig-kiem',
                'description' => app(RichTextSanitizer::class)->full('<p>Mô tả</p>'.VideoEmbed::html('https://www.youtube.com/watch?v=SxwkhMy4Ywo')),
            ]);

            return app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'jig-kiem');
        });

        $html = $this->get('http://mau.platform.test'.$path)->assertOk()->getContent();

        expect($html)->toContain('<div class="product__body"><p>Mô tả</p>')
            ->and($html)->toContain('data-src="https://www.youtube-nocookie.com/embed/SxwkhMy4Ywo?rel=0&amp;autoplay=1"')
            ->and($html)->toContain('<script type="module" src="/resources/js/sf-video.js"></script>');
        expectNothingFetchedFromTheVideoHost($html);
    });

    it('loads no video script on a product without a video', function (): void {
        $path = app(Tenancy::class)->enter($this->siteId, function (): string {
            $product = Product::query()->create(['status' => 'published', 'published_at' => now()]);
            $product->translations()->create(['locale' => 'vi', 'name' => 'Jig', 'slug' => 'jig', 'description' => '<p>Chỉ chữ</p>']);

            return app(RouteSync::class)->syncProduct($product->id, null, 'vi', 'jig');
        });

        expect($this->get('http://mau.platform.test'.$path)->assertOk()->getContent())->not->toContain('sf-video.js');
    });
});

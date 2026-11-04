<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Support\EntryBlockMediaReconciler;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * The hero's background video: a video from the site's library (a media id, never an address). It
 * plays where the hero image sits, muted and looped, started by a small script after the page has
 * loaded — the HTML has no source and no autoplay, so the image stays the page's main paint and a
 * visitor who asked for less motion or data downloads no video. A video that is missing, deleted,
 * of another site or not a video renders the plain hero. It counts as used in the library.
 */

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function heroVideoErrors(array $props): array
{
    app()->setLocale('vi');

    return array_values(Validator::make(['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Xưởng'] + $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

function heroVideoMedia(string $slug, string $mime = 'video/mp4'): Media
{
    $hash = hash('sha256', $slug);
    $ext = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'image/webp' => 'webp'][$mime];

    return Media::query()->create([
        'collection' => 'library', 'slug' => $slug, 'hash' => $hash,
        'storage_key' => 's/library/'.$slug.'-'.substr($hash, 0, 12).'.'.$ext,
        'mime' => $mime, 'size' => 4_800_000,
    ]);
}

describe('validation', function (): void {
    it('accepts no video or a library media id', function (mixed $value): void {
        expect(heroVideoErrors(['bg_video' => $value]))->toBe([]);
    })->with([null, 1, 123456]);

    it('accepts a hero without the prop at all', function (): void {
        expect(heroVideoErrors([]))->toBe([]);
    });

    it('refuses anything that is not a media id, in plain words', function (mixed $value): void {
        $errors = heroVideoErrors(['bg_video' => $value]);

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toBe(__('entries.err_block_bg_video_invalid'))
            ->and($errors[0])->toContain('Thư viện')->not->toContain('entries.');
    })->with([
        'zero' => 0,
        'negative' => -3,
        'digits as text' => '12',
        'an address' => 'https://files.test/s/library/a.mp4',
        'a fraction' => 1.5,
        'a list' => [[1]],
        'true' => true,
    ]);

    it('has its message and the control labels in every language file', function (): void {
        $labels = [
            'vi' => ['Dừng video', 'Phát video'], 'en' => ['Pause video', 'Play video'],
            'ja' => ['動画を停止', '動画を再生'], 'zh' => ['暂停视频', '播放视频'],
        ];
        foreach ($labels as $locale => [$pause, $play]) {
            $file = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);
            expect($file)->toHaveKey('entries.err_block_bg_video_invalid')
                ->and($file['storefront.bgvideo_pause'] ?? null)->toBe($pause)
                ->and($file['storefront.bgvideo_play'] ?? null)->toBe($play);
        }
    });
});

describe('render', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
    });

    /** @param  array<string, mixed>  $props */
    function heroVideoRender(array $props): string
    {
        app()->setLocale('vi');

        return app(Tenancy::class)->enter(test()->siteId, fn (): string => app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'hero', 'props' => ['heading' => 'Gia công cơ khí', 'cta_label' => 'Nhận báo giá', 'cta_url' => '/lien-he'] + $props],
        ]]));
    }

    it('prints the video where the image sits, with the image as poster, its file as source and no autoplay', function (): void {
        [$image, $video] = app(Tenancy::class)->enter($this->siteId, fn (): array => [StorefrontCards::media('xuong'), heroVideoMedia('xuong-cnc')]);
        $html = heroVideoRender(['image_url' => $image->id, 'bg_video' => $video->id]);

        $imageUrl = 'https://files.test/'.$image->storage_key.'?w=1280';
        expect($html)->toContain('<section class="hero hero--video">')
            ->toContain('<img class="hero__bg"')
            ->toContain('<video class="hero__bg hero__video" data-sf-bgvideo data-src="https://files.test/'.$video->storage_key.'" data-type="video/mp4" muted loop playsinline preload="none" disablepictureinpicture disableremoteplayback aria-hidden="true" poster="'.$imageUrl.'"></video>')
            ->not->toContain('autoplay')
            ->not->toContain('<source')
            ->not->toContain('data-media-id')
            ->not->toContain('bg_video');
        // The control is printed hidden (the script shows it), with both labels, after the content.
        expect($html)->toMatch('#</div>\s*<button type="button" class="hero__vctl" data-band="dark" data-pause="Dừng video" data-play="Phát video" hidden>#')
            ->toContain('<span class="hero__vctl-label">Dừng video</span>')
            ->not->toContain('aria-pressed');
    });

    it('takes a WebM video as it is and prints no poster when the hero has no image', function (): void {
        $video = app(Tenancy::class)->enter($this->siteId, fn (): Media => heroVideoMedia('may-phay', 'video/webm'));
        $html = heroVideoRender(['bg_video' => $video->id]);

        expect($html)->toContain('data-type="video/webm"')
            ->toContain('data-src="https://files.test/'.$video->storage_key.'"')
            ->not->toContain('poster=')
            ->not->toContain('<img');
    });

    it('renders the plain image hero when the video is not a video of this site', function (Closure $id): void {
        $image = app(Tenancy::class)->enter($this->siteId, fn (): Media => StorefrontCards::media('xuong'));
        $html = heroVideoRender(['image_url' => $image->id, 'bg_video' => $id($this)]);

        expect($html)->toContain('<section class="hero">')
            ->toContain('<img class="hero__bg"')
            ->not->toContain('<video')
            ->not->toContain('hero__vctl');
    })->with([
        'unknown id' => [fn (): int => 999_999],
        'trashed' => [fn ($t): int => app(Tenancy::class)->enter($t->siteId, function (): int {
            $video = heroVideoMedia('cu');
            $video->delete();

            return $video->id;
        })],
        'a picture' => [fn ($t): int => app(Tenancy::class)->enter($t->siteId, fn (): int => StorefrontCards::media('anh-khac')->id)],
        'another site' => [fn ($t): int => app(Tenancy::class)->enter(StorefrontCards::site('Xưởng Khác', 'khac.platform.test'), fn (): int => heroVideoMedia('cua-site-khac')->id)],
    ]);

    it('gives the control the look of the band it plays on', function (array $look, ?string $primary, string $band): void {
        app(Tenancy::class)->enter($this->siteId, function () use ($primary): void {
            if ($primary !== null) {
                SiteSetting::query()->create(['group_name' => 'theme', 'key' => 'tokens', 'value' => ['primary' => $primary]]);
            }
        });
        $video = app(Tenancy::class)->enter($this->siteId, fn (): Media => heroVideoMedia('xuong-cnc'));

        expect(heroVideoRender(['bg_video' => $video->id] + $look))->toContain('class="hero__vctl" data-band="'.$band.'"');
    })->with([
        'own dark band' => [[], null, 'dark'],
        'light set' => [['bg' => 'scheme', 'scheme' => 'light'], null, 'light'],
        'soft set' => [['bg' => 'scheme', 'scheme' => 'soft'], null, 'light'],
        'dark set' => [['bg' => 'scheme', 'scheme' => 'dark'], null, 'dark'],
        'dark brand set' => [['bg' => 'scheme', 'scheme' => 'brand'], '#1e3a8a', 'dark'],
        'light brand set' => [['bg' => 'scheme', 'scheme' => 'brand'], '#facc15', 'light'],
        'an older band value' => [['bg' => 'surface'], null, 'dark'],
    ]);

    it('labels the control in the visitor language', function (string $locale, string $pause, string $play): void {
        app()->setLocale($locale);
        $html = View::make('theme::sections.hero', ['props' => ['bg_video' => ['url' => 'https://files.test/a.mp4', 'type' => 'video/mp4']]])->render();

        expect($html)->toContain('data-pause="'.$pause.'" data-play="'.$play.'"')
            ->toContain('<span class="hero__vctl-label">'.$pause.'</span>');
    })->with([
        ['vi', 'Dừng video', 'Phát video'], ['en', 'Pause video', 'Play video'],
        ['ja', '動画を停止', '動画を再生'], ['zh', '暂停视频', '播放视频'],
    ]);
});

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->siteId = StorefrontCards::site();
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    /** @param  list<array<string, mixed>>  $blocks */
    function heroVideoPage(string $path, array $blocks): string
    {
        app(Tenancy::class)->enter(test()->siteId, fn () => StorefrontCards::page($path, 'Trang chủ', $blocks));

        return test()->get('http://mau.platform.test'.$path)->assertOk()->getContent();
    }

    it('loads the background-video script only where a hero video plays', function (): void {
        [$image, $video] = app(Tenancy::class)->enter($this->siteId, fn (): array => [StorefrontCards::media('xuong'), heroVideoMedia('xuong-cnc')]);

        expect(heroVideoPage('/co-video', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id, 'bg_video' => $video->id]]]))
            ->toContain('<script type="module" src="/resources/js/sf-bgvideo.js"></script>')
            ->toContain('data-sf-bgvideo');
        expect(heroVideoPage('/chi-anh', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id]]]))->not->toContain('sf-bgvideo');
        expect(heroVideoPage('/video-mat', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id, 'bg_video' => 999_999]]]))->not->toContain('sf-bgvideo');
    });

    it('costs no extra queries compared with an image hero (the video joins the one media lookup)', function (): void {
        [$image, $video] = app(Tenancy::class)->enter($this->siteId, fn (): array => [StorefrontCards::media('xuong'), heroVideoMedia('xuong-cnc')]);
        heroVideoPage('/anh', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id]]]);
        heroVideoPage('/video', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id, 'bg_video' => $video->id]]]);

        $plain = countQueries(fn () => test()->get('http://mau.platform.test/anh')->assertOk());
        $withVideo = countQueries(fn () => test()->get('http://mau.platform.test/video')->assertOk());

        expect($withVideo)->toBe($plain);
    });

    it('costs no extra queries on a brand-coloured hero', function (): void {
        [$image, $video] = app(Tenancy::class)->enter($this->siteId, fn (): array => [StorefrontCards::media('xuong'), heroVideoMedia('xuong-cnc')]);
        $look = ['bg' => 'scheme', 'scheme' => 'brand'];
        heroVideoPage('/anh-brand', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id] + $look]]);
        heroVideoPage('/video-brand', [['type' => 'hero', 'props' => ['heading' => 'A', 'image_url' => $image->id, 'bg_video' => $video->id] + $look]]);

        $plain = countQueries(fn () => test()->get('http://mau.platform.test/anh-brand')->assertOk());
        $withVideo = countQueries(fn () => test()->get('http://mau.platform.test/video-brand')->assertOk());

        expect($withVideo)->toBe($plain);
    });
});

describe('usage', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        $this->siteId = StorefrontCards::site();
    });

    it('counts the video as used by the page, and stops when it is removed', function (): void {
        app(Tenancy::class)->enter($this->siteId, function (): void {
            $image = StorefrontCards::media('xuong');
            $video = heroVideoMedia('xuong-cnc');
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'T', 'slug' => 't', 'status' => 'draft',
                'body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $image->id, 'bg_video' => $video->id]]]]]);
            $used = fn (): array => Mediable::query()->where('mediable_type', 'entry')->where('mediable_id', $entry->id)
                ->where('collection', 'content')->orderBy('media_id')->pluck('media_id')->all();

            app(EntryBlockMediaReconciler::class)->reconcile($entry);
            expect($used())->toBe([$image->id, $video->id]);

            $entry->translations()->where('locale', 'vi')->update(['body' => ['blocks' => [['type' => 'hero', 'props' => ['image_url' => $image->id, 'bg_video' => null]]]]]);
            app(EntryBlockMediaReconciler::class)->reconcile($entry);
            expect($used())->toBe([$image->id]);
        });
    });
});

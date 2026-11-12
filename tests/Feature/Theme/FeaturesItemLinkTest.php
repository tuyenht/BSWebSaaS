<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;
use Tests\Fixtures\StorefrontCards;

/*
 * A features card may lead somewhere: each item takes an optional button link (`url`, the quick links'
 * typed value: a page of the site, a safe address, a phone, a mail, a file). With one, the title is the
 * card's one link, stretched over the card inside its own layer, and the card ends with "Xem chi tiết →";
 * without one the card draws as it always did. The icon-cards look is the shared icon card, and with the
 * display group a rail like a list block's.
 */

/** @param array<string, mixed> $props */
function featuresHtml(array $props): string
{
    app()->setLocale('vi');

    return app(BlockRenderer::class)->render(['blocks' => [['type' => 'features', 'props' => $props]]]);
}

/**
 * @param  array<string, mixed>  $props
 * @return list<string>
 */
function featuresErrors(array $props): array
{
    app()->setLocale('vi');

    return array_values(Validator::make(['blocks' => [['type' => 'features', 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
}

function featuresPage(string $slug, string $path): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now()]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Gia công CNC', 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);
    SiteRoute::query()->create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);

    return $entry;
}

describe('drawing', function (): void {
    beforeEach(fn () => seedTenancy());

    it('makes a linked card one pressable link with "Xem chi tiết →", in every look', function (string $variant, string $card): void {
        $html = featuresHtml(['variant' => $variant, 'items' => [
            ['title' => 'Phay & Tiện CNC', 'text' => 'Gia công theo bản vẽ.', 'icon' => 'adjust', 'url' => '/gia-cong-phay-tien-cnc'],
            ['title' => 'Hàn & Lắp ráp', 'text' => 'Hàn MIG/MAG, TIG.', 'icon' => 'wrench'],
        ]]);

        expect(substr_count($html, 'class="sf-card__link" href="/gia-cong-phay-tien-cnc">Phay &amp; Tiện CNC</a>'))->toBe(1)
            ->and(substr_count($html, 'class="sf-card__link"'))->toBe(1)
            ->and(substr_count($html, '<span class="sf-card__more" aria-hidden="true">Xem chi tiết <span class="sf-card__arrow">→</span></span>'))->toBe(1)
            ->and($html)->toContain($card)
            ->and($html)->toContain('Hàn &amp; Lắp ráp</h3>');
    })->with([
        'default' => ['default', '<li class="sf-fcard sf-fcard--link">'],
        'top-rule' => ['top-rule', '<li class="sf-feature sf-feature--link">'],
        'icon-head' => ['icon-head', '<li class="sf-hcard sf-hcard--link">'],
        'icon-cards' => ['icon-cards', '<article class="sf-card sf-card--icon">'],
    ]);

    it('draws no link, no "Xem chi tiết" and no link class for cards without one', function (string $variant): void {
        $html = featuresHtml(['variant' => $variant, 'items' => [['title' => 'Nhà xưởng', 'text' => '12.000 m²', 'icon' => 'box'], ['title' => 'Máy CNC']]]);

        expect($html)->not->toContain('<a ')->not->toContain('Xem chi tiết')->not->toContain('--link')->not->toContain('sf-rail');
    })->with(['default', 'top-rule', 'icon-head', 'icon-cards']);

    it('opens an outside address in a new tab and says so, calls a phone, and downloads nothing it cannot find', function (): void {
        $html = featuresHtml(['variant' => 'top-rule', 'items' => [
            ['title' => 'Tài liệu', 'url_do' => ['k' => 'url', 'url' => 'https://example.com/ho-so']],
            ['title' => 'Gọi ngay', 'url_do' => ['k' => 'tel', 'phone' => '0915 668 383']],
        ]]);

        expect($html)->toContain('href="https://example.com/ho-so" target="_blank" rel="noopener">Tài liệu<span class="sr-only"> ')
            ->toContain('href="tel:0915668383">Gọi ngay</a>');

        $cards = featuresHtml(['variant' => 'icon-cards', 'items' => [['title' => 'Tài liệu', 'url_do' => ['k' => 'url', 'url' => 'https://example.com/ho-so']]]]);
        expect($cards)->toContain('href="https://example.com/ho-so" target="_blank" rel="noopener">Tài liệu<span class="sr-only"> ');
    });

    it('follows a page to its current address, so a renamed slug never breaks the card, and never prints an id', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $post = featuresPage('gia-cong-cnc', '/gia-cong-cnc');
            $props = ['variant' => 'icon-cards', 'items' => [['title' => 'Phay & Tiện CNC', 'url_do' => ['k' => 'page', 'page' => $post->ulid]]]];

            expect(featuresHtml($props))->toContain('href="/gia-cong-cnc"');

            SiteRoute::query()->where('entry_id', $post->id)->update(['path' => '/gia-cong-phay-tien-cnc']);
            $html = featuresHtml($props);

            expect($html)->toContain('href="/gia-cong-phay-tien-cnc"')->not->toContain('/gia-cong-cnc"')
                ->not->toContain($post->ulid)->not->toMatch('~href="[^"]*/'.$post->id.'"~');
        });
    });

    it('draws no link for an unsafe address, older or typed', function (array $item): void {
        foreach (['default', 'top-rule', 'icon-head', 'icon-cards'] as $variant) {
            $html = featuresHtml(['variant' => $variant, 'items' => [['title' => 'Ấn vào', 'text' => 'Mô tả'] + $item]]);
            expect($html)->not->toContain('<a ')->not->toContain('evil.example')->not->toContain('Xem chi tiết');
        }
    })->with([
        'javascript:' => [['url' => 'javascript:location="//evil.example"']],
        'protocol relative' => [['url' => '//evil.example/a']],
        'typed javascript:' => [['url_do' => ['k' => 'url', 'url' => 'javascript:alert("evil.example")']]],
        'typed data:' => [['url_do' => ['k' => 'url', 'url' => 'data:text/html,evil.example']]],
    ]);

    it('is a rail with the display group in the icon-cards look, and a grid of four without it', function (): void {
        $items = array_map(fn (int $i): array => ['title' => "Dịch vụ {$i}", 'icon' => 'cog', 'url' => "/dich-vu-{$i}"], range(1, 4));

        $rail = featuresHtml(['variant' => 'icon-cards', 'layout' => 'auto', 'items' => $items]);
        $grid = featuresHtml(['variant' => 'icon-cards', 'items' => $items]);
        $other = featuresHtml(['variant' => 'top-rule', 'layout' => 'auto', 'items' => $items]);

        expect($rail)->toContain('data-sf-rail')->toContain('sf-rail__list sf-rail__list--grid sf-rail__list--c4 sf-rail__list--m-swipe')
            ->and(substr_count($rail, 'class="sf-card sf-card--icon"'))->toBe(4)
            ->and($grid)->toContain('<ul class="sf-grid sf-grid--4">')->not->toContain('sf-rail')
            ->and($other)->toContain('<ul class="sf-grid sf-grid--4">')->not->toContain('sf-rail');
    });
});

describe('saving', function (): void {
    beforeEach(fn () => seedTenancy());

    it('takes a page, a safe address, an older path or no link, and refuses an unsafe one', function (): void {
        expect(featuresErrors(['items' => [['title' => 'A', 'url' => '/gia-cong-phay-tien-cnc']]]))->toBe([])
            ->and(featuresErrors(['items' => [['title' => 'A']]]))->toBe([])
            ->and(featuresErrors(['variant' => 'icon-cards', 'layout' => 'auto', 'items' => [['title' => 'A', 'url_do' => ['k' => 'url', 'url' => 'https://example.com']]]]))->toBe([])
            ->and(featuresErrors(['items' => [['title' => 'A', 'url' => 'javascript:alert(1)']]]))->toHaveCount(1)
            ->and(featuresErrors(['items' => [['title' => 'A', 'url_do' => ['k' => 'url', 'url' => 'javascript:alert(1)']]]]))->toHaveCount(1)
            ->and(featuresErrors(['items' => [['title' => 'A', 'url_do' => ['k' => 'page', 'page' => '42']]]]))->toHaveCount(1)
            ->and(featuresErrors(['variant' => 'carousel', 'items' => [['title' => 'A']]]))->not->toBe([]);
    });
});

describe('the builder', function (): void {
    beforeEach(function (): void {
        truncateRbac();
        seedTenancy();
        $this->artisan('rbac:sync')->assertSuccessful();
        app(TeamContext::class)->forget();
        app()->setLocale('vi');
    });

    it('shows the quick links\' "Khi bấm nút" control for each card and saves the chosen page', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $post = featuresPage('gia-cong-phay-tien-cnc', '/gia-cong-phay-tien-cnc');
            $editor = User::factory()->create();
            grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'media.view']);

            Livewire::actingAs($editor->fresh())->test(Editor::class)
                ->set('title', 'Trang chủ')
                ->set('slug', 'trang-chu-dich-vu')
                ->set('blocks', [['type' => 'features', 'props' => ['heading' => 'Dịch vụ', 'variant' => 'icon-cards', 'items' => [['title' => 'Phay & Tiện CNC', 'text' => 'Theo bản vẽ.', 'icon' => 'adjust']]]]])
                ->assertSeeHtml('id="btn-act-0-props-items-0-url"')
                ->assertSee('Khi bấm nút “Phay & Tiện CNC”')
                ->assertDontSeeHtml('wire:model="blocks.0.props.items.0.url"')
                ->call('setButtonKind', '0.props.items.0.url', 'page')
                ->call('pickButtonPage', '0.props.items.0.url', $post->ulid)
                ->call('save')
                ->assertHasNoErrors();

            $body = EntryTranslation::query()->where('slug', 'trang-chu-dich-vu')->value('body');
            expect($body['blocks'][0]['props']['items'][0]['url_do'])->toBe(['k' => 'page', 'page' => $post->ulid]);
        });
    });
});

describe('on a page', function (): void {
    beforeEach(function (): void {
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $this->swap(Vite::class, new class extends Vite
        {
            public function __invoke($entrypoints, $buildDirectory = null): HtmlString
            {
                return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
            }
        });
    });

    it('loads the slider script for an icon-cards rail only', function (): void {
        $items = array_map(fn (int $i): array => ['title' => "Dịch vụ {$i}", 'icon' => 'cog'], range(1, 4));
        $page = function (array $props): string {
            StorefrontCards::reset();
            $siteId = StorefrontCards::site();
            app(Tenancy::class)->enter($siteId, fn () => StorefrontCards::page('/trang', 'Trang thử ạ', [['type' => 'features', 'props' => $props]]));

            return test()->get('http://mau.platform.test/trang')->assertOk()->getContent();
        };

        expect($page(['variant' => 'icon-cards', 'layout' => 'auto', 'items' => $items]))->toContain('src="/resources/js/sf-slider.js"')
            ->and($page(['variant' => 'top-rule', 'layout' => 'auto', 'items' => $items]))->not->toContain('sf-slider.js')
            ->and($page(['variant' => 'icon-cards', 'items' => $items]))->not->toContain('sf-slider.js');
    });
});

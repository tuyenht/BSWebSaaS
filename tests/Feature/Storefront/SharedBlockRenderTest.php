<?php

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Support\ReusableBlockExpander;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\BlockReference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\StorefrontCards;

/*
 * A shared block (Khối dùng chung) placed on a page: the storefront puts the block's own blocks in
 * its place before the page is drawn, in the page's language or else the website's default, read in
 * one query for the whole page. A draft block, one that is gone or one of another website leaves its
 * place empty and the page answers 200. The renderer sees the shared block's blocks as the page's own
 * (the block that leads the page, the FAQ data); one level only, at most BlockReference::PAGE_MAX
 * blocks on a page, an anchor printed once. A change to a shared block purges the website's pages.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
    seedHomepage(2, 'Bình An');
});

/** A shared block of $siteId with a body per language (locale → blocks); a block with none has no translation. */
function sbrBlock(int $siteId, string $key, array $bodies, string $status = 'published'): ContentBlock
{
    return app(Tenancy::class)->enter($siteId, function () use ($key, $bodies, $status): ContentBlock {
        $block = ContentBlock::query()->create(['key' => $key, 'name' => 'Khối '.$key, 'region' => 'inline', 'status' => $status]);
        foreach ($bodies as $locale => $blocks) {
            ContentBlockTranslation::query()->create(['content_block_id' => $block->id, 'locale' => $locale, 'body' => ['blocks' => $blocks]]);
        }

        return $block;
    });
}

function sbrCta(string $heading, array $props = []): array
{
    return ['type' => 'cta', 'props' => ['heading' => $heading, ...$props]];
}

/** @return array{type: string, props: array{block: string}} */
function sbrRef(string $ulid): array
{
    return ['type' => BlockReference::TYPE, 'props' => [BlockReference::PROP => $ulid]];
}

/** Write the home page body of $siteId as stored (no save check: the storefront must cope with anything). */
function sbrHome(int $siteId, array $blocks): void
{
    app(Tenancy::class)->enter($siteId, fn () => EntryTranslation::query()->update(['body' => json_encode(['blocks' => $blocks])]));
    sbrFresh();
}

/** Start the next request as Octane does: no scoped instance and no cached controller carried over. */
function sbrFresh(): void
{
    app()->forgetScopedInstances();
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
}

function sbrPage(string $host = 'a.test', string $path = '/'): string
{
    sbrFresh();

    return (string) test()->get("http://{$host}{$path}")->assertOk()->getContent();
}

it('draws a published shared block in its place, between the page\'s own blocks', function (): void {
    $block = sbrBlock(1, 'loi-moi', ['vi' => [sbrCta('Lời mời dùng chung')]]);
    sbrHome(1, [sbrCta('Đầu trang'), sbrRef($block->ulid), sbrCta('Cuối trang')]);

    $html = sbrPage();

    expect($html)->toContain('Lời mời dùng chung')
        ->and(strpos($html, 'Đầu trang'))->toBeLessThan(strpos($html, 'Lời mời dùng chung'))
        ->and(strpos($html, 'Lời mời dùng chung'))->toBeLessThan(strpos($html, 'Cuối trang'));
});

it('leaves the place empty for a draft block, one that is gone, one of another website or a bad code, and answers 200', function (): void {
    $draft = sbrBlock(1, 'nhap', ['vi' => [sbrCta('Khối còn nháp')]], 'draft');
    $gone = sbrBlock(1, 'da-xoa', ['vi' => [sbrCta('Khối đã xoá')]]);
    $foreign = sbrBlock(2, 'loi-moi', ['vi' => [sbrCta('Khối của website khác')]]);
    $empty = sbrBlock(1, 'khong-noi-dung', []);
    app(Tenancy::class)->enter(1, fn () => $gone->delete());
    sbrHome(1, [sbrCta('Đầu trang'), sbrRef($draft->ulid), sbrRef($gone->ulid), sbrRef($foreign->ulid), sbrRef($empty->ulid), sbrRef('not-a-ulid'), ['type' => BlockReference::TYPE]]);

    $html = sbrPage();

    expect($html)->toContain('Đầu trang')
        ->not->toContain('Khối còn nháp')->not->toContain('Khối đã xoá')->not->toContain('Khối của website khác')
        ->and(sbrPage('b.test'))->not->toContain('Khối của website khác'); // b's home does not place it
});

it('takes the page\'s language, else the website\'s default, else nothing', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $both = sbrBlock(1, 'hai-ngon-ngu', ['vi' => [sbrCta('Tiếng Việt')], 'en' => [sbrCta('English')]]);
    $viOnly = sbrBlock(1, 'chi-tieng-viet', ['vi' => [sbrCta('Chỉ tiếng Việt')]]);
    $enOnly = sbrBlock(1, 'chi-tieng-anh', ['en' => [sbrCta('Only English')]]);
    $body = ['blocks' => [sbrRef($both->ulid), sbrRef($viOnly->ulid), sbrRef($enOnly->ulid)]];

    $headings = fn (string $locale): array => app(Tenancy::class)->enter(1, fn (): array => array_map(
        fn (array $b): string => $b['props']['heading'],
        app(ReusableBlockExpander::class)->expand($body, [], $locale)['body']['blocks'],
    ));

    expect($headings('en'))->toBe(['English', 'Chỉ tiếng Việt', 'Only English'])
        ->and($headings('vi'))->toBe(['Tiếng Việt', 'Chỉ tiếng Việt']);
});

it('reads every shared block of a page in one query, and none for a page without any', function (): void {
    $blocks = array_map(fn (int $n): ContentBlock => sbrBlock(1, "khoi-{$n}", ['vi' => [sbrCta("Khối số {$n}")]]), range(1, 3));
    sbrPage();
    $base = countQueries(fn () => sbrPage());

    sbrHome(1, [['type' => 'hero', 'props' => ['heading' => 'An Vinh']], ...array_map(fn (ContentBlock $b): array => sbrRef($b->ulid), $blocks)]);
    sbrPage();
    $with = countQueries(fn () => sbrPage());

    expect($with - $base)->toBe(1)
        ->and($with)->toBeLessThanOrEqual(12) // the home budget (QueryBudgetTest) + the one read of the shared blocks
        ->and(sbrPage())->toContain('Khối số 1')->toContain('Khối số 3');

    // read once per request: a second expansion of the same blocks asks the database nothing
    app(Tenancy::class)->enter(1, function () use ($blocks): void {
        $expander = app(ReusableBlockExpander::class);
        $body = ['blocks' => [sbrRef($blocks[0]->ulid)]];
        $expander->expand($body, [], 'vi');
        expect(countQueries(fn () => $expander->expand($body, [], 'vi')))->toBe(0);
    });
});

it('lets a shared block lead the page: its title band carries the page\'s one h1', function (): void {
    $block = sbrBlock(1, 'dau-trang', ['vi' => [['type' => 'page_header', 'props' => ['title' => 'Băng đầu dùng chung']], sbrCta('Sau băng')]]);
    sbrHome(1, [sbrRef($block->ulid)]);

    $html = sbrPage();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h1 class="page-band__title">Băng đầu dùng chung</h1>');
});

it('gives the page FAQ data from the questions of a shared block', function (): void {
    $block = sbrBlock(1, 'hoi-dap', ['vi' => [['type' => 'faq', 'props' => ['heading' => 'Câu hỏi', 'items' => [['q' => 'Bảo hành bao lâu?', 'a' => 'Mười hai tháng.']]]]]]);
    sbrHome(1, [sbrCta('Đầu trang'), sbrRef($block->ulid)]);

    $faq = collect(ldNodes(sbrPage()))->firstWhere('@type', 'FAQPage');

    expect($faq['mainEntity'][0]['name'] ?? null)->toBe('Bảo hành bao lâu?');
});

it('keeps one level: a reference inside a shared block, and a block only a slot may hold, are left out of a body', function (): void {
    $inner = sbrBlock(1, 'ben-trong', ['vi' => [sbrCta('Khối lồng bên trong')]]);
    // written past the save check, which refuses both
    $outer = sbrBlock(1, 'ben-ngoai', ['vi' => [sbrCta('Khối bên ngoài'), sbrRef($inner->ulid), ['type' => 'recent_posts', 'props' => []]]]);

    $out = app(Tenancy::class)->enter(1, fn (): array => app(ReusableBlockExpander::class)->expand(['blocks' => [sbrRef($outer->ulid)]], ['post_aside' => [sbrRef($outer->ulid)]], 'vi'));

    expect(array_column($out['body']['blocks'], 'type'))->toBe(['cta'])
        ->and(array_column($out['slots']['post_aside'], 'type'))->toBe(['cta', 'recent_posts']);
});

it('prints a shared block\'s anchor once when the block is placed twice', function (): void {
    $block = sbrBlock(1, 'lien-he', ['vi' => [sbrCta('Liên hệ ngay', ['anchor_id' => 'lien-he'])]]);
    sbrHome(1, [sbrRef($block->ulid), sbrCta('Giữa'), sbrRef($block->ulid)]);

    $html = sbrPage();

    expect(substr_count($html, 'Liên hệ ngay'))->toBe(2)
        ->and(substr_count($html, 'id="lien-he"'))->toBe(1);
});

it('draws at most the page cap, the page\'s own blocks always kept, and logs what it left out', function (): void {
    Log::spy();
    $block = sbrBlock(1, 'nhieu', ['vi' => array_map(fn (int $n): array => sbrCta("Chung {$n}"), range(1, 20))]);
    $own = array_map(fn (int $n): array => sbrCta("Riêng {$n}"), range(1, BlockReference::PAGE_MAX - 5));

    $out = app(Tenancy::class)->enter(1, fn (): array => app(ReusableBlockExpander::class)->expand(['blocks' => [sbrRef($block->ulid), ...$own]], [], 'vi'));
    $headings = array_map(fn (array $b): string => $b['props']['heading'], $out['body']['blocks']);

    expect(count($headings))->toBe(BlockReference::PAGE_MAX)
        ->and(array_slice($headings, 0, 5))->toBe(['Chung 1', 'Chung 2', 'Chung 3', 'Chung 4', 'Chung 5'])
        ->and(end($headings))->toBe('Riêng '.(BlockReference::PAGE_MAX - 5));
    Log::shouldHaveReceived('warning')->once();
});

it('draws nothing for a reference the storefront did not expand', function (): void {
    expect(trim(view('theme::sections.'.BlockReference::TYPE, ['props' => [BlockReference::PROP => '01J00000000000000000000000']])->render()))->toBe('');
});

it('never shows another website\'s block, with the same reference code and two sites interleaved', function (): void {
    $a = sbrBlock(1, 'chan-trang', ['vi' => [sbrCta('Chân trang của A')]]);
    $b = sbrBlock(2, 'chan-trang', ['vi' => [sbrCta('Chân trang của B')]]);
    sbrHome(1, [sbrRef($a->ulid), sbrRef($b->ulid)]);
    sbrHome(2, [sbrRef($b->ulid), sbrRef($a->ulid)]);

    foreach (['a.test', 'b.test', 'a.test', 'b.test'] as $host) {
        $html = sbrPage($host);
        $mine = $host === 'a.test' ? 'Chân trang của A' : 'Chân trang của B';
        $theirs = $host === 'a.test' ? 'Chân trang của B' : 'Chân trang của A';
        expect($html)->toContain($mine)->not->toContain($theirs);
    }
});

it('purges the website\'s pages once when a shared block is published, edited or deleted', function (Closure $write): void {
    $block = sbrBlock(1, 'loi-moi', ['vi' => [sbrCta('Lời mời')]], 'draft');
    $edge = edgeCacheRecorder();

    app(Tenancy::class)->enter(1, fn () => $write($block));

    expect($edge->calls)->toBe([['hosts', ['a.test']]]);
})->with([
    'published' => [fn (ContentBlock $block) => $block->update(['status' => ContentBlock::PUBLISHED])],
    'edited' => [function (ContentBlock $block): void {
        $translation = ContentBlockTranslation::query()->where('content_block_id', $block->id)->firstOrFail();
        $translation->body = ['blocks' => [sbrCta('Lời mời mới')]];
        $translation->save();
    }],
    'deleted' => [fn (ContentBlock $block) => $block->delete()],
]);

describe('in the slots around posts, lists and products', function (): void {
    it('draws shared blocks in a post, list and product slot, and none in the product information tabs', function (): void {
        // A provisioned site of its own (another test of the run may have left one on this host).
        StorefrontCards::reset();
        StorefrontCards::pinMediaConfig();
        $siteId = StorefrontCards::site();
        app(Tenancy::class)->enter($siteId, function (): void {
            $photo = StorefrontCards::media('anh', 960, 720);
            StorefrontCards::post(StorefrontCards::news(), 'Ống đang đọc', 'ong-dang-doc', CarbonImmutable::parse('2026-09-01 08:00', 'UTC'), ['image_url' => $photo->storage_key]);
            $catalog = StorefrontCards::category('Sản phẩm', 'san-pham');
            StorefrontCards::catalog($catalog);
            StorefrontCards::product('Đồ gá kiểm', 'do-ga-kiem', $catalog, $photo, CarbonImmutable::parse('2026-10-02 08:00', 'UTC'));
        });
        $block = sbrBlock($siteId, 'bao-gia', ['vi' => [sbrCta('Báo giá dùng chung')]]);
        $tabs = sbrBlock($siteId, 'the-san-pham', ['vi' => [sbrCta('Thẻ dùng chung')]]);
        app(Tenancy::class)->enter($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'storefront', 'key' => 'layout_blocks'], ['value' => [
            'after_post' => ['vi' => ['blocks' => [sbrRef($block->ulid)]]],
            'after_list' => ['vi' => ['blocks' => [sbrRef($block->ulid)]]],
            'after_product' => ['vi' => ['blocks' => [sbrRef($block->ulid)]]],
            'product_tabs' => ['vi' => ['blocks' => [sbrRef($tabs->ulid)]]],
        ]]));

        expect(sbrPage('mau.platform.test', '/tin-tuc/ong-dang-doc'))->toContain('Báo giá dùng chung')
            ->and(sbrPage('mau.platform.test', '/tin-tuc'))->toContain('Báo giá dùng chung')
            ->and(sbrPage('mau.platform.test', '/do-ga-kiem'))->toContain('Báo giá dùng chung')->not->toContain('Thẻ dùng chung');
    });
});

it('expands the shared blocks on every storefront road that draws blocks', function (): void {
    // Each storefront file that hands blocks to the page renderer also puts the shared blocks in first.
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Modules/Storefront'))) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/->(render|renderCategory|renderProduct)\(/', $source) === 1 && str_contains($source, 'PageRenderer')) {
                $files[basename($file->getPathname())] = str_contains($source, 'ReusableBlockExpander');
            }
        }
    }

    expect($files)->toHaveKeys(['StorefrontController.php', 'ProductPage.php'])
        ->and(array_keys(array_filter($files, fn (bool $expands): bool => ! $expands)))->toBe([]);
});

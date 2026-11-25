<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * «Tuyển dụng» becomes a category of «Tin tức» on a site that holds the pack as An Vinh does today
 * (the job category at the top, with its own «careers» section the loader created): one load moves
 * the category under the news and removes the section. Every route stays as it was (the job posts
 * and /tuyen-dung included), the job posts belong to the news section and show in its admin list
 * under their category, the breadcrumb runs through the news, the job page keeps its JobPosting,
 * and the news list shows no job offer.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $this->siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
});

afterEach(fn () => ContentPacks::cleanup());

/** The pack as An Vinh holds it before the move: the job category at the top, with its section. */
function rcBefore(string $folder): array
{
    $files = [];
    foreach (['taxonomies.json', 'posts.json', 'menus.json', 'sections.json'] as $file) {
        $files[$file] = (string) file_get_contents($folder.'/'.$file);
    }
    foreach (['posts.json', 'menus.json'] as $file) {
        file_put_contents($folder.'/'.$file, str_replace('"tax:post_cat/tin-tuc/tuyen-dung"', '"tax:post_cat/tuyen-dung"', $files[$file]));
    }
    ContentPacks::item($folder, 'taxonomies.json', 'tax:post_cat/tin-tuc/tuyen-dung', function (array $item): array {
        unset($item['parent'], $item['moved_from'], $item['position']);

        return ['key' => 'tax:post_cat/tuyen-dung'] + $item;
    });
    file_put_contents($folder.'/sections.json', json_encode(['items' => [[
        'key' => 'section:careers', 'label' => 'Tuyển dụng', 'entry_type' => 'post', 'category' => 'tax:post_cat/tuyen-dung', 'icon' => 'ri-user-search', 'sort_order' => 1,
    ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $files;
}

/** @param array<string, string> $files */
function rcRestore(string $folder, array $files): void
{
    foreach ($files as $file => $content) {
        file_put_contents($folder.'/'.$file, $content);
    }
}

/** @return list<string> every route of the site */
function rcRoutes(int $siteId): array
{
    return ContentPacks::in($siteId, fn (): array => SiteRoute::query()->orderBy('locale')->orderBy('path')->get(['locale', 'path', 'entry_id', 'taxonomy_id', 'product_id'])
        ->map(fn (SiteRoute $route): string => "{$route->locale}:{$route->path}={$route->entry_id}|{$route->taxonomy_id}|{$route->product_id}")->all());
}

function rcSectionOf(int $siteId, string $slug): ?string
{
    return ContentPacks::in($siteId, function () use ($slug): ?string {
        app(SectionResolver::class)->flush();
        $post = Entry::query()->findOrFail(EntryTranslation::query()->where('locale', 'vi')->where('slug', $slug)->value('entry_id'));

        return app(SectionResolver::class)->forEntry($post)['key'] ?? null;
    });
}

it('moves the job category under the news and removes its section in one load, with no route changing', function (): void {
    $files = rcBefore($this->folder);
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output)->and(rcSectionOf($this->siteId, 'ke-toan-noi-bo'))->toBe('careers');
    $before = rcRoutes($this->siteId);
    expect(array_filter($before, fn (string $line): bool => str_starts_with($line, 'vi:/ky-su-thiet-ke-co-khi-do-ga=') || str_starts_with($line, 'vi:/ke-toan-noi-bo=') || str_starts_with($line, 'vi:/tuyen-dung=')))->toHaveCount(3);

    rcRestore($this->folder, $files);
    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'tax:post_cat/tin-tuc/tuyen-dung'))->toBe('update')
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('remove')
        ->and($output)->toContain('removes the section «careers» (built on tax:post_cat/tin-tuc/tuyen-dung); its posts join «posts»; 0 post addresses move')
        ->and($output)->not->toContain('URL /tuyen-dung →')
        ->and($output)->toMatch('/conflict 0\b/');

    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output)
        ->and(rcRoutes($this->siteId))->toBe($before)
        ->and(rcSectionOf($this->siteId, 'ke-toan-noi-bo'))->toBe('posts')
        ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'careers')->exists()))->toBeFalse();

    // The job page: same address, the trail runs through the news, the JobPosting stays.
    $job = $this->get('http://'.ContentPacks::HOST.'/ke-toan-noi-bo')->assertOk()->getContent();
    expect(ldNode($job, 'JobPosting'))->not->toBeNull()
        ->and(array_column(ldNode($job, 'BreadcrumbList')['itemListElement'] ?? [], 'name'))->toBe(['Trang chủ', 'Tin tức & sự kiện', 'Gia nhập đội ngũ An Vinh', 'Kế toán nội bộ – hợp đồng, ngân hàng, công nợ']);
    $careers = $this->get('http://'.ContentPacks::HOST.'/tuyen-dung')->assertOk()->assertSee('Kế toán nội bộ')->assertSee('Kỹ sư cơ khí')->getContent();
    expect(array_column(ldNode($careers, 'BreadcrumbList')['itemListElement'] ?? [], 'name'))->toBe(['Trang chủ', 'Tin tức & sự kiện', 'Gia nhập đội ngũ An Vinh']);
    $this->get('http://'.ContentPacks::HOST.'/ky-su-thiet-ke-co-khi-do-ga')->assertOk();
    // The news list: no job offer, a chip to the jobs.
    $this->get('http://'.ContentPacks::HOST.'/tin-tuc')->assertOk()
        ->assertDontSee('href="/ke-toan-noi-bo"', false)->assertDontSee('href="/ky-su-thiet-ke-co-khi-do-ga"', false)
        ->assertSee('href="/tuyen-dung"', false);

    // The admin: no «Tuyển dụng» entry any more; the news list holds the job posts under their category.
    $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();
    $keys = ContentPacks::in($this->siteId, fn (): array => array_map(fn (NavItem $item): string => $item->key, (new AdminNav)->items($owner)));
    expect($keys)->not->toContain('careers')->and($keys)->toContain('posts');
    $test = ContentPacks::in($this->siteId, fn () => Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']));
    $titles = collect($test->viewData('rows')->items())->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)->all();
    expect($titles)->toContain('Kế toán nội bộ – hợp đồng, ngân hàng, công nợ', 'Kỹ sư cơ khí – thiết kế jig, đồ gá ô tô & xe máy')
        ->and(collect($test->viewData('catOptions'))->pluck('label')->all())->toContain('Gia nhập đội ngũ An Vinh');

    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('unchanged')
        ->and(ContentPacks::verdict($output, 'tax:post_cat/tin-tuc/tuyen-dung'))->toBe('unchanged');
});

it('refuses the removal on a site whose news addresses follow their category', function (): void {
    // The An Vinh seeder's preset: under the news section the job posts would move to /tuyen-dung/….
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'posts'], ['value' => ['pattern' => 'category']]));
    $files = rcBefore($this->folder);
    ContentPacks::load('an-vinh');
    $before = rcRoutes($this->siteId);

    rcRestore($this->folder, $files);
    [$exit, $output] = ContentPacks::run('an-vinh');

    expect($exit)->toBe(1, $output)
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('conflict')
        ->and($output)->toContain('/ke-toan-noi-bo → /tuyen-dung/ke-toan-noi-bo');
    expect(fn () => ContentPacks::load('an-vinh'))->toThrow(RuntimeException::class);
    expect(rcRoutes($this->siteId))->toBe($before)
        ->and(rcSectionOf($this->siteId, 'ke-toan-noi-bo'))->toBe('careers');
});

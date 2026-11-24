<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * The real pack's «Tuyển dụng» section, loaded onto a site that already holds the pack without it
 * (as An Vinh does): the plan creates it on the job category and moves no address, the load leaves
 * every route as it was (the job posts and /tuyen-dung included), and the admin then lists the job
 * posts under their own section, right after the news.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

afterEach(fn () => ContentPacks::cleanup());

it('adds the careers section on the job category with no route changing, and lists the job posts under it', function (): void {
    $folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $sections = (string) file_get_contents($folder.'/sections.json');
    $siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
    // The site's own permalinks, as the An Vinh seeder set them: news under its category.
    ContentPacks::in($siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'posts'], ['value' => ['pattern' => 'category']]));

    unlink($folder.'/sections.json');
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);
    $routes = fn (): array => ContentPacks::in($siteId, fn (): array => SiteRoute::query()->orderBy('locale')->orderBy('path')->get(['locale', 'path', 'entry_id', 'taxonomy_id', 'product_id'])
        ->map(fn (SiteRoute $route): string => "{$route->locale}:{$route->path}={$route->entry_id}|{$route->taxonomy_id}|{$route->product_id}")->all());
    $before = $routes();

    file_put_contents($folder.'/sections.json', $sections);
    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('create')
        ->and($output)->toContain('built on tax:post_cat/tuyen-dung; 0 post addresses move');

    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output)
        ->and($routes())->toBe($before);
    expect(array_filter($before, fn (string $line): bool => str_starts_with($line, 'vi:/ky-su-thiet-ke-co-khi-do-ga=') || str_starts_with($line, 'vi:/ke-toan-noi-bo=') || str_starts_with($line, 'vi:/tuyen-dung=')))->toHaveCount(3);

    $this->get('http://'.ContentPacks::HOST.'/tuyen-dung')->assertOk()->assertSee('Kế toán nội bộ');
    $this->get('http://'.ContentPacks::HOST.'/ke-toan-noi-bo')->assertOk();

    $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();
    $keys = ContentPacks::in($siteId, fn (): array => array_map(fn (NavItem $item): string => $item->key, (new AdminNav)->items($owner)));
    $careers = ContentPacks::in($siteId, fn (): ?array => ContentSection::query()->where('key', 'careers')->first()?->only(['label', 'entry_type', 'permission_prefix', 'icon']));
    expect($careers)->toBe(['label' => 'Tuyển dụng', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'icon' => 'ri-user-search'])
        ->and(array_search('careers', $keys, true))->toBe(array_search('posts', $keys, true) + 1);

    $titles = ContentPacks::in($siteId, fn (): array => collect(
        Livewire::actingAs($owner)->test(Index::class, ['section' => 'careers'])->viewData('rows')->items()
    )->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)->all());
    // Every job post, drafts included (the list shows all statuses), and nothing else.
    sort($titles);
    expect($titles)->toBe([
        'Kế toán nội bộ – hợp đồng, ngân hàng, công nợ',
        'Kỹ sư cơ khí – thiết kế jig, đồ gá ô tô & xe máy',
        'Kỹ thuật viên vận hành máy CNC',
        'Nhân viên kiểm tra chất lượng (QC)',
    ]);

    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)->and(ContentPacks::verdict($output, 'section:careers'))->toBe('unchanged');
});

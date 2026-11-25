<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\TaxonomyTranslation;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\ContentPack\Ledger;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use Livewire\Livewire;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * «Tin tức» and «Tuyển dụng» are two categories side by side at the top of the tree, and the admin
 * keeps one news list for both: on a site that holds the pack as An Vinh does today (the job
 * category with its own «careers» section, created by a loader whose ledger lines did not record
 * that), one load, with the run naming the section, makes the news section cover the job category
 * too and removes «careers». No route changes and no category moves: /tuyen-dung keeps its own
 * trail («Trang chủ › Tuyển dụng»), the job posts keep their addresses and their JobPosting, the
 * news list on the website shows no job offer, and the admin's news list holds the job posts, the
 * job category a choice at the top of its filter beside the news categories.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use('an-vinh', database_path('content-packs'));
    ContentPacks::edit($this->folder, 'pack.json', fn (array $pack): array => ['hosts' => [ContentPacks::HOST]] + $pack);
    $this->siteId = StorefrontCards::site('Demo', ContentPacks::HOST);
});

afterEach(fn () => ContentPacks::cleanup());

/** The pack as An Vinh holds it now: the job category with its «careers» section, loaded; the ledger line as the older loader wrote it. */
function rcLoadedBefore(string $folder, int $siteId): void
{
    $sections = (string) file_get_contents($folder.'/sections.json');
    file_put_contents($folder.'/sections.json', json_encode(['items' => [[
        'key' => 'section:careers', 'label' => 'Tuyển dụng', 'entry_type' => 'post', 'category' => 'tax:post_cat/tuyen-dung', 'icon' => 'ri-user-search', 'sort_order' => 1,
    ]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    [$exit, $output] = ContentPacks::load('an-vinh');
    expect($exit)->toBe(0, $output);
    ContentPacks::in($siteId, function (): void {
        $row = SiteSetting::query()->where('group_name', Ledger::GROUP)->where('key', 'an-vinh')->firstOrFail();
        $value = is_string($row->value) ? json_decode($row->value, true) : $row->value;
        unset($value['items']['section:careers']['created']);
        $row->update(['value' => $value]);
    });
    file_put_contents($folder.'/sections.json', $sections);
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

it('makes the news section cover the job category beside its own and removes «careers», with no route or category moving', function (): void {
    rcLoadedBefore($this->folder, $this->siteId);
    expect(rcSectionOf($this->siteId, 'ke-toan-noi-bo'))->toBe('careers');
    $before = rcRoutes($this->siteId);
    expect(array_filter($before, fn (string $line): bool => str_starts_with($line, 'vi:/ky-su-thiet-ke-co-khi-do-ga=') || str_starts_with($line, 'vi:/ke-toan-noi-bo=') || str_starts_with($line, 'vi:/tuyen-dung=')))->toHaveCount(3);

    // The older ledger line does not say who created the section: the run has to name it.
    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(1, $output)
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('conflict')
        ->and($output)->toContain('--force-item=section:careers to remove it');

    [$exit, $output] = ContentPacks::run('an-vinh', ['--force-item' => ['section:careers']]);
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('remove (forced)')
        ->and($output)->toContain('removes the section «careers» (built on tax:post_cat/tuyen-dung); its posts join «posts»; 0 post addresses move')
        ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('adopt')
        ->and($output)->toContain('adds tax:post_cat/tuyen-dung; 0 post addresses move')
        ->and(ContentPacks::verdict($output, 'tax:post_cat/tuyen-dung'))->toBe('unchanged')
        ->and($output)->not->toContain('URL /tuyen-dung →')
        ->and($output)->toMatch('/conflict 0\b/');

    [$exit, $output] = ContentPacks::load('an-vinh', ['section:careers']);
    expect($exit)->toBe(0, $output)
        ->and(rcRoutes($this->siteId))->toBe($before)
        ->and(rcSectionOf($this->siteId, 'ke-toan-noi-bo'))->toBe('posts')
        ->and(ContentPacks::in($this->siteId, fn () => ContentSection::query()->where('key', 'careers')->exists()))->toBeFalse();
    // The job category stays at the top of the tree.
    $jobs = ContentPacks::in($this->siteId, fn () => TaxonomyTranslation::query()->where('locale', 'vi')->where('slug', 'tuyen-dung')->firstOrFail()->taxonomy);
    expect($jobs->parent_id)->toBeNull();

    // The job page: same address, its own trail (no «Tin tức» in it), the JobPosting stays.
    $job = $this->get('http://'.ContentPacks::HOST.'/ke-toan-noi-bo')->assertOk()->getContent();
    expect(ldNode($job, 'JobPosting'))->not->toBeNull()
        ->and(array_column(ldNode($job, 'BreadcrumbList')['itemListElement'] ?? [], 'name'))->toBe(['Trang chủ', 'Gia nhập đội ngũ An Vinh', 'Kế toán nội bộ – hợp đồng, ngân hàng, công nợ']);
    $careers = $this->get('http://'.ContentPacks::HOST.'/tuyen-dung')->assertOk()->assertSee('Kế toán nội bộ')->assertSee('Kỹ sư cơ khí')->getContent();
    expect(array_column(ldNode($careers, 'BreadcrumbList')['itemListElement'] ?? [], 'name'))->toBe(['Trang chủ', 'Gia nhập đội ngũ An Vinh']);
    $this->get('http://'.ContentPacks::HOST.'/ky-su-thiet-ke-co-khi-do-ga')->assertOk();
    // The news list on the website: no job offer.
    $this->get('http://'.ContentPacks::HOST.'/tin-tuc')->assertOk()
        ->assertDontSee('href="/ke-toan-noi-bo"', false)->assertDontSee('href="/ky-su-thiet-ke-co-khi-do-ga"', false);

    // The admin: no «Tuyển dụng» entry any more; the news list holds the job posts, every one of them.
    $owner = User::query()->where('email', 'owner@'.ContentPacks::HOST)->firstOrFail();
    $keys = ContentPacks::in($this->siteId, fn (): array => array_map(fn (NavItem $item): string => $item->key, (new AdminNav)->items($owner)));
    expect($keys)->not->toContain('careers')->and($keys)->toContain('posts');
    $test = ContentPacks::in($this->siteId, fn () => Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']));
    $titles = collect($test->viewData('rows')->items())->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)->all();
    expect($titles)->toContain(
        'Kế toán nội bộ – hợp đồng, ngân hàng, công nợ',
        'Kỹ sư cơ khí – thiết kế jig, đồ gá ô tô & xe máy',
        'Kỹ thuật viên vận hành máy CNC',
        'Nhân viên kiểm tra chất lượng (QC)',
    );
    // Its category filter: the job category at the top beside the news categories, the news root not a choice.
    $options = collect($test->viewData('catOptions'));
    expect($options->firstWhere('label', 'Gia nhập đội ngũ An Vinh')['depth'] ?? null)->toBe(0)
        ->and($options->pluck('label')->all())->not->toContain('Tin tức & sự kiện');

    [$exit, $output] = ContentPacks::run('an-vinh');
    expect($exit)->toBe(0, $output)
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('unchanged')
        ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('unchanged');
});

it('refuses on a site whose news addresses follow their category, and changes nothing', function (): void {
    // Under the news section the job posts would move to /tuyen-dung/…: the news section does not take the job category, so «careers» stays.
    ContentPacks::in($this->siteId, fn () => SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'posts'], ['value' => ['pattern' => 'category']]));
    rcLoadedBefore($this->folder, $this->siteId);
    $before = rcRoutes($this->siteId);

    [$exit, $output] = ContentPacks::run('an-vinh', ['--force-item' => ['section:careers']]);

    expect($exit)->toBe(1, $output)
        ->and(ContentPacks::verdict($output, 'section:posts'))->toBe('conflict')
        ->and($output)->toContain('/ke-toan-noi-bo → /tuyen-dung/ke-toan-noi-bo')
        ->and(ContentPacks::verdict($output, 'section:careers'))->toBe('conflict');
    expect(fn () => ContentPacks::load('an-vinh', ['section:careers']))->toThrow(RuntimeException::class);
    expect(rcRoutes($this->siteId))->toBe($before)
        ->and(rcSectionOf($this->siteId, 'ke-toan-noi-bo'))->toBe('careers');
});

<?php

use App\Modules\Cms\Components\EntryGridComponent;
use Tests\Fixtures\ContentPacks;
use Tests\Fixtures\StorefrontCards;

/*
 * A category below a list that stores a list layout of a look set of its own (jobs under the news)
 * keeps its posts to its own list: the news list and the news block's tabs leave the job offers out,
 * while /tuyen-dung lists them (its own sub-categories included), and the chips still lead to
 * «Tuyển dụng». A sub-category with no layout of its own, or one of the list's own set, stays in.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
    $this->folder = ContentPacks::use();
    $this->siteId = ContentPacks::site();

    // Tin tức › Cập nhật, Sự kiện (no layout of their own) and Tuyển dụng (the job set).
    ContentPacks::edit($this->folder, 'taxonomies.json', fn (array $data): array => ['items' => [...$data['items'],
        ['key' => 'tax:post_cat/tin-tuc/su-kien', 'parent' => 'tax:post_cat/tin-tuc', 'name' => ['vi' => 'Sự kiện'], 'position' => 2],
        ['key' => 'tax:post_cat/tin-tuc/tuyen-dung', 'parent' => 'tax:post_cat/tin-tuc', 'name' => ['vi' => 'Tuyển dụng'], 'list_template' => 'grid-job', 'detail_template' => 'job', 'position' => 3],
        ['key' => 'tax:post_cat/tin-tuc/tuyen-dung/ha-noi', 'parent' => 'tax:post_cat/tin-tuc/tuyen-dung', 'name' => ['vi' => 'Việc ở Hà Nội'], 'position' => 1],
    ]] + $data);
    $post = fn (string $slug, string $title, string $category): array => [
        'key' => 'post:'.$slug, 'verified' => true, 'status' => 'published', 'categories' => [$category], 'image' => 'cog',
        'title' => ['vi' => $title], 'excerpt' => ['vi' => $title.'.'],
        'body' => ['vi' => [['type' => 'richtext', 'props' => ['paragraphs' => [$title.'.']]]]],
    ];
    ContentPacks::edit($this->folder, 'posts.json', fn (array $data): array => ['items' => [...$data['items'],
        $post('hoi-cho-may', 'Hội chợ máy công cụ', 'tax:post_cat/tin-tuc/su-kien'),
        $post('ky-su-co-khi', 'Kỹ sư cơ khí chế tạo', 'tax:post_cat/tin-tuc/tuyen-dung'),
        $post('ke-toan-kho', 'Kế toán kho vận', 'tax:post_cat/tin-tuc/tuyen-dung/ha-noi'),
    ]] + $data);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);
});

afterEach(fn () => ContentPacks::cleanup());

/** @param list<array<string, mixed>> $cards @return list<string> */
function lsTitles(array $cards): array
{
    $titles = array_map(fn (array $card): string => (string) $card['title'], $cards);
    sort($titles);

    return $titles;
}

it('leaves the job offers out of the news list, which still offers their chip, and lists them on their own', function (): void {
    $news = $this->get('http://'.ContentPacks::HOST.'/tin-tuc')->assertOk()
        ->assertSee('Ra mắt website')->assertSee('Hội chợ máy công cụ')
        ->assertDontSee('Kỹ sư cơ khí chế tạo')->assertDontSee('Kế toán kho vận');
    expect($news->getContent())->toContain('Tuyển dụng');   // the chip

    $this->get('http://'.ContentPacks::HOST.'/tuyen-dung')->assertOk()
        ->assertSee('Kỹ sư cơ khí chế tạo')->assertSee('Kế toán kho vận')->assertDontSee('Hội chợ máy công cụ');
    $this->get('http://'.ContentPacks::HOST.'/su-kien')->assertOk()->assertSee('Hội chợ máy công cụ');
});

it('gives the job category no tab in the news block', function (): void {
    $data = ContentPacks::in($this->siteId, fn (): array => app(EntryGridComponent::class)->data(['taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'tabs' => 'auto', 'limit' => 6]));

    expect(array_column($data['tabs'] ?? [], 'name'))->toBe(['Cập nhật', 'Sự kiện']);
});

it('keeps a sub-category of the list\'s own look in it', function (): void {
    // Dự án (projects) › Ô tô (projects too): the projects list shows the car projects.
    ContentPacks::edit($this->folder, 'taxonomies.json', fn (array $data): array => ['items' => [...$data['items'],
        ['key' => 'tax:post_cat/du-an', 'name' => ['vi' => 'Dự án'], 'list_template' => 'grid-project', 'detail_template' => 'project'],
        ['key' => 'tax:post_cat/du-an/o-to', 'parent' => 'tax:post_cat/du-an', 'name' => ['vi' => 'Dự án ô tô'], 'list_template' => 'grid-project', 'position' => 1],
    ]] + $data);
    ContentPacks::item($this->folder, 'posts.json', 'post:hoi-cho-may', fn (array $item): array => ['categories' => ['tax:post_cat/du-an/o-to'], 'title' => ['vi' => 'Đồ gá dây chuyền ô tô']] + $item);
    [$exit, $output] = ContentPacks::load();
    expect($exit)->toBe(0, $output);

    $this->get('http://'.ContentPacks::HOST.'/du-an')->assertOk()->assertSee('Đồ gá dây chuyền ô tô');
});

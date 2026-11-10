<?php

use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Components\SectionProps;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/*
 * The job look of the article grid (variant `job`): the card with its short facts and badge, the
 * filter buttons by sub-category (two or more with posts, `tabs: filter`), the count line of the
 * whole branch ("{n}", "100+" past 100) and the text of an empty list.
 */

beforeEach(function (): void {
    seedTenancy();
    app()->setLocale('vi');
});

/** A "tuyen-dung" category with sub-categories, each holding the given number of published job posts. */
function jobBranch(array $children = ['Trung Giã' => 1, 'Thạch Thất' => 1], int $onParent = 0): void
{
    $parent = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
    $parent->translations()->create(['locale' => 'vi', 'name' => 'Tuyển dụng', 'slug' => 'tuyen-dung']);

    $n = 0;
    $post = function (Taxonomy $category) use (&$n): void {
        $n++;
        $entry = Entry::query()->create([
            'type' => 'post', 'status' => 'published', 'published_at' => '2026-10-01 08:00:00',
            'meta' => ['facts' => [['text' => ['vi' => "Nơi {$n}"], 'tone' => 'plain'], ['text' => ['vi' => 'Lương: Thỏa thuận'], 'tone' => 'accent']], 'badge' => ['vi' => 'Tuyển gấp']],
        ]);
        $entry->translations()->create(['locale' => 'vi', 'title' => "Vị trí {$n}", 'slug' => "vi-tri-{$n}", 'status' => 'published', 'excerpt' => "Tóm tắt {$n}", 'body' => ['blocks' => []]]);
        $entry->assignCategories([$category->id]);
        app(RouteSync::class)->sync($entry, 'vi', "vi-tri-{$n}");
    };

    foreach ($children as $name => $count) {
        $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parent->id, 'position' => 1]);
        $child->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => str($name)->slug()->toString()]);
        for ($i = 0; $i < $count; $i++) {
            $post($child);
        }
    }
    for ($i = 0; $i < $onParent; $i++) {
        $post($parent);
    }
}

/** @return array{0: array<string, mixed>, 1: string} the block data and its rendered markup */
function jobGrid(array $props = []): array
{
    $data = app(EntryGridComponent::class)->data($props + ['taxonomy_slug' => 'tuyen-dung', 'variant' => 'job', 'limit' => 20, 'locale' => 'vi']);

    return [$data, View::make('theme::sections.entry_grid', $data)->render()];
}

it('draws the job card exactly as the golden file', function (): void {
    $html = View::make('theme::partials.sf-card', [
        'kind' => 'job', 'level' => 'h3',
        'card' => [
            'title' => 'Kỹ sư thiết kế cơ khí – đồ gá', 'url' => '/tuyen-dung/ky-su', 'excerpt' => 'Thiết kế 3D jig, đồ gá.', 'badge' => 'Tuyển gấp',
            'facts' => [['text' => 'Trụ sở – Nhà máy 1, Trung Giã', 'tone' => 'plain'], ['text' => 'Lương: Thỏa thuận', 'tone' => 'accent'], ['text' => 'Hạn nộp: 31/12/2026', 'tone' => 'plain']],
        ],
    ])->render();

    expect($html)->toBe(file_get_contents(__DIR__.'/golden/job-card.html'));
});

it('shows the filter buttons from two sub-categories that hold posts, and only with tabs set to filter', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 2, 'Thạch Thất' => 1, 'Chưa có tin' => 0]);

        [$data, $html] = jobGrid(['tabs' => 'filter']);

        expect($data['jobs']['chips'])->toBe([['key' => 'g0', 'name' => 'Trung Giã'], ['key' => 'g1', 'name' => 'Thạch Thất']])
            ->and($html)->toContain('data-sf-pick="g0"')->toContain('data-sf-pick="g1"')->not->toContain('Chưa có tin')
            ->and($html)->toContain('data-sf-group="g1"')->toContain('role="group"')
            ->and(substr_count($html, 'class="sf-card sf-card--job'))->toBe(3);

        expect(jobGrid(['tabs' => 'off'])[1])->not->toContain('data-sf-pick');
    });
});

it('draws no filter buttons for a single sub-category with posts', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 3, 'Thạch Thất' => 0]);

        expect(jobGrid(['tabs' => 'filter'])[0]['jobs']['chips'])->toBe([])
            ->and(jobGrid(['tabs' => 'filter'])[1])->not->toContain('data-sf-pick');
    });
});

it('counts the whole branch, not what is shown or chosen', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 2, 'Thạch Thất' => 1], onParent: 1);

        [$short] = jobGrid(['count_label' => '{n} vị trí đang mở', 'limit' => 2]);
        [$data, $html] = jobGrid(['count_label' => '{n} vị trí đang mở', 'tabs' => 'filter']);

        // Two shown of four: the line still says four. With the buttons, "all" says four and the line does not change.
        expect($short['jobs']['count'])->toBe('4 vị trí đang mở')->and(count($short['items']))->toBe(2)
            ->and($data['jobs']['count'])->toBe('4 vị trí đang mở')
            ->and($html)->toContain('4 vị trí đang mở')->toContain('Tất cả (4)');
    });
});

it('draws no count line without a label', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch();

        expect(jobGrid()[1])->not->toContain('sf-jobs__count');
    });
});

it('stops counting at 101 and prints 100+', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 101]);

        $reads = [];
        DB::listen(function ($query) use (&$reads): void {
            $reads[] = $query->sql;
        });
        [$data] = jobGrid(['count_label' => '{n} vị trí', 'limit' => 5]);

        expect($data['jobs']['count'])->toBe('100+ vị trí')
            ->and(count(array_filter($reads, fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, 'limit 101'))))->toBe(1);
    });
});

it('prints the empty text when the branch has no post, the standard line without one', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 0, 'Thạch Thất' => 0]);

        [$data, $html] = jobGrid(['empty_text' => 'Chưa có vị trí, xin quay lại sau.', 'count_label' => '{n} vị trí']);

        expect($data['items'])->toBe([])
            ->and($html)->toContain('Chưa có vị trí, xin quay lại sau.')->not->toContain('sf-jobs__count')
            ->and(jobGrid()[1])->toContain(__('storefront.no_jobs'));
    });
});

it('prints the empty text for a category that does not exist', function (): void {
    [, $html] = jobGrid(['taxonomy_slug' => 'khong-co', 'empty_text' => 'Trống']);

    expect($html)->toContain('Trống');
});

it('leaves out a draft post and one outside the branch', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 1]);
        $other = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 2]);
        $other->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        $stray = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => '2026-10-02 08:00:00']);
        $stray->translations()->create(['locale' => 'vi', 'title' => 'Tin lạc', 'slug' => 'tin-lac', 'status' => 'published', 'body' => ['blocks' => []]]);
        $stray->assignCategories([$other->id]);
        $draft = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $draft->translations()->create(['locale' => 'vi', 'title' => 'Nháp', 'slug' => 'nhap', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $draft->assignCategories([Taxonomy::query()->where('parent_id', '!=', null)->value('id')]);

        [$data, $html] = jobGrid(['count_label' => '{n}']);

        expect($data['jobs']['count'])->toBe('1')->and($html)->not->toContain('Tin lạc')->not->toContain('Nháp');
    });
});

it('does not draw an unknown key or a stray tone of the stored facts', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        jobBranch(['Trung Giã' => 1]);
        $entry = Entry::query()->firstOrFail();
        $entry->meta = ['facts' => [['text' => ['vi' => 'Nơi'], 'tone' => 'evil" onmouseover="x', 'html' => '<script>x</script>']], 'badge' => ['vi' => '<i>Gấp</i>'], 'injected' => '<script>y</script>'];
        $entry->save();

        $html = jobGrid()[1];

        expect($html)->toContain('sf-card__fact">Nơi<')->not->toContain('onmouseover')->not->toContain('<script>')
            ->and($html)->toContain('&lt;i&gt;Gấp&lt;/i&gt;');
    });
});

it('keeps the old variants unchanged: the job look only adds a choice', function (): void {
    expect(array_slice(SectionProps::ENTRY_GRID_VARIANTS, 0, 5))->toBe(['list', 'icon-cards', 'project-cards', 'news-cards', 'zigzag'])
        ->and(SectionProps::ENTRY_GRID_VARIANTS)->toContain('job');
});

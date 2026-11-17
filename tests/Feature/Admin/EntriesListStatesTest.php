<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/*
 * The posts and pages list's states (design 05): a section with nothing in it yet shows one card
 * («Chưa có bài nào», the add button only for who may add); a search or filters that match nothing say
 * so with «Bỏ lọc» (filters alone too); an empty status group and an empty Trash each have their own
 * line. The rows reload under the design's loading rows, and a list request that fails gives way to
 * «Chưa tải được danh sách … Thử lại» (the shared list component, list busy=…).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** @param  array<string, mixed>  $extra */
function lsPage(User $author, string $title, array $extra = []): Entry
{
    $entry = Entry::query()->create($extra + ['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'ls-'.$entry->id, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $entry;
}

/** @param  array<string, mixed>  $extra */
function lsPost(User $author, string $title, int $categoryId, array $extra = []): Entry
{
    $entry = Entry::query()->create($extra + ['type' => 'post', 'status' => 'published', 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'lsp-'.$entry->id, 'status' => 'published', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$categoryId]);

    return $entry;
}

function lsSection(string $key = 'tin-tuc'): ContentSection
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => $key]);

    return ContentSection::query()->create(['key' => $key, 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
}

/**
 * The listed titles of the component's rows.
 *
 * @return list<string>
 */
function lsTitlesOf(mixed $component): array
{
    return collect($component->viewData('rows')->items())
        ->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)
        ->all();
}

describe('nothing yet', function (): void {
    it('shows one card with «Chưa có bài nào» and the add button to whoever may add', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.create']);
            lsSection();

            $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc']);
            $html = $list->html();

            expect($html)->toContain('data-guide="list-empty"')
                ->and($html)->toContain(e(__('entries.state_empty_title_post')))
                ->and($html)->toContain(e(__('entries.state_empty_create_post')))
                ->and($html)->toContain('href="'.e(route('admin.entries.create', ['section' => 'tin-tuc'])).'"')
                // the list itself (groups, search, rows) gives way to the card
                ->and($html)->not->toContain('id="entries-search"')
                ->and($html)->not->toContain('role="table"');
        });
    });

    it('tells a member who may not add where the list will be, with no add button', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'viewer', ['entries.view']);

            $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

            expect($html)->toContain(e(__('entries.state_empty_title_page')))
                ->and($html)->toContain(e(__('entries.state_empty_view_page')))
                ->and($html)->not->toContain(e(__('entries.state_empty_create_page')))
                ->and($html)->not->toContain('href="'.e(route('admin.entries.create')).'"');
        });
    });

    it('is not the case while the Trash still holds something: the group says it has nothing', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.delete.any']);
            lsPage($user, 'Đã bỏ')->delete();

            $list = Livewire::actingAs($user->fresh())->test(Index::class);
            $html = $list->html();

            expect($html)->not->toContain('data-guide="list-empty"')
                ->and($html)->toContain('data-guide="list-none"')
                ->and($html)->toContain(e(__('entries.state_group_empty_page')));

            $list->set('tab', 'trash');
            expect(lsTitlesOf($list))->toBe(['Đã bỏ']);
        });
    });
});

describe('no row on this view', function (): void {
    it('says a search matches nothing, with «Bỏ lọc», which clears it', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            $section = lsSection();
            lsPost($user, 'Bảng giá', (int) $section->taxonomy_id);

            $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])->set('search', 'không có đâu');
            $html = $list->html();

            expect($html)->toContain(e(__('entries.state_no_match_post')))
                ->and($html)->toContain('data-k="nomatch"')
                ->and($html)->toContain('wire:click="clearAllFilters"')
                ->and($html)->toContain(e(__('admin.list.clear_filters')))
                ->and($html)->not->toContain('data-guide="list-empty"');

            $list->call('clearAllFilters')->assertSet('search', '');
            expect(lsTitlesOf($list))->toBe(['Bảng giá']);
        });
    });

    it('says filters alone match nothing, with «Bỏ lọc», as a search does', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            $section = lsSection();
            lsPost($user, 'Tin cũ', (int) $section->taxonomy_id, ['published_at' => now()->subDays(40)]);

            $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])->set('when', 'today');
            $html = $list->html();

            expect(lsTitlesOf($list))->toBe([])
                ->and($html)->toContain(e(__('entries.state_no_match_post')))
                ->and($html)->toContain(e(__('admin.list.clear_filters')))
                ->and($html)->not->toContain(e(__('entries.state_group_empty_post')))
                ->and($html)->not->toContain('data-guide="list-empty"');
        });
    });

    it('says an empty status group has nothing, without «Bỏ lọc»', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lsPage($user, 'Nháp');

            $list = Livewire::actingAs($user->fresh())->test(Index::class)->set('tab', 'published');
            $html = $list->html();

            expect(lsTitlesOf($list))->toBe([])
                ->and($html)->toContain(e(__('entries.state_group_empty_page')))
                ->and($html)->toContain('data-k="none"')
                ->and($html)->not->toContain(e(__('entries.state_no_match_page')));
            // the in-row state has no clear button (the filter line above still offers one for the group)
            $at = strpos($html, 'data-guide="list-none"');
            expect($at)->not->toBeFalse()
                ->and(substr($html, (int) $at, 700))->not->toContain('clearAllFilters');
        });
    });

    it('says the Trash is empty', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view', 'entries.delete.any']);
            lsPage($user, 'Còn đây');

            $html = Livewire::actingAs($user->fresh())->test(Index::class)->set('tab', 'trash')->html();

            expect($html)->toContain(e(__('entries.state_trash_empty')));
        });
    });
});

describe('loading and a request that fails', function (): void {
    it('covers the rows with the loading rows on the list requests, and offers Thử lại when one fails', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lsPage($user, 'Một');

            $html = Livewire::actingAs($user->fresh())->test(Index::class)->html();

            expect($html)->toContain('x-data="bsListState(')
                ->and($html)->toContain('wire:loading.delay.short.flex')
                ->and($html)->toContain('class="list-busy hidden"')
                ->and($html)->toContain('wire:loading.delay.short.attr="aria-busy"')
                ->and($html)->toContain(e(__('admin.list.loading')))
                ->and($html)->toContain(e(__('admin.list.load_failed')))
                ->and($html)->toContain(e(__('admin.list.load_failed_text')))
                ->and($html)->toContain('x-on:click="retry()"');

            preg_match('/wire:loading\.delay\.short\.flex wire:target="([^"]+)"/', $html, $t);
            $targets = explode(',', $t[1] ?? '');
            // the search, group, filters, sort and page reload the rows; a row's own action does not
            expect($targets)->toContain('search', 'tab', 'catF', 'when', 'sortBy', 'setSort', 'gotoPage', 'turnPage', 'setPerPage', 'clearAllFilters', '$refresh')
                ->and($targets)->not->toContain('trash', 'changeStatus', 'saveTitle', 'duplicate');
        });
    });

    it('leaves a list without busy as it was: no loading cover, no error state', function (): void {
        $html = Blade::render('<x-bs-admin::list label="Danh sách"><x-bs-admin::list.row><x-bs-admin::list.cell>Một</x-bs-admin::list.cell></x-bs-admin::list.row></x-bs-admin::list>');

        expect($html)->toContain('role="table"')
            ->and($html)->not->toContain('bsListState')
            ->and($html)->not->toContain('list-busy')
            ->and($html)->not->toContain('wire:loading');
    });
});

<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The header of the posts and pages list: the status groups, the Filter sheet's sections (Bản dịch,
 * Thời gian for posts, Người tạo with the member first), the Sort menu with each type's default and
 * each order's natural direction, the Lọc count, "Bỏ lọc" (which keeps the sort), and what each member
 * chooses to show (Cột / Hiện trên thẻ) kept in their own preferences — never anyone else's.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** @param  array<string, mixed>  $extra */
function lhPage(User $author, string $title, array $extra = []): Entry
{
    $entry = Entry::query()->create($extra + ['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'lh-'.$entry->id, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $entry;
}

/** @param  array<string, mixed>  $extra */
function lhPost(User $author, string $title, int $categoryId, array $extra = []): Entry
{
    $entry = Entry::query()->create($extra + ['type' => 'post', 'status' => 'published', 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'lhp-'.$entry->id, 'status' => 'published', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$categoryId]);

    return $entry;
}

function lhSection(string $key = 'tin-tuc'): ContentSection
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => $key]);

    return ContentSection::query()->create(['key' => $key, 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
}

function lhTranslate(Entry $entry, string ...$codes): void
{
    foreach ($codes as $code) {
        $entry->translations()->create(['locale' => $code, 'title' => 'Bản '.$code, 'slug' => 'lht-'.$code.'-'.$entry->id, 'status' => 'draft', 'body' => ['blocks' => []]]);
    }
}

/**
 * The listed titles, in order, of the component's rows.
 *
 * @return list<string>
 */
function lhTitles(mixed $component): array
{
    return collect($component->viewData('rows')->items())
        ->map(fn (array $row): string => (string) $row['entry']->translations->firstWhere('locale', 'vi')?->title)
        ->all();
}

describe('sort', function (): void {
    it('lists pages in their order on the website by default, and posts last edited first', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lhPage($user, 'Ba', ['position' => 2]);
            lhPage($user, 'Một', ['position' => 0]);
            lhPage($user, 'Hai', ['position' => 1]);
            $section = lhSection();
            lhPost($user, 'Cũ', (int) $section->taxonomy_id)->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
            lhPost($user, 'Mới', (int) $section->taxonomy_id);

            $pages = Livewire::actingAs($user->fresh())->test(Index::class);
            expect(lhTitles($pages))->toBe(['Một', 'Hai', 'Ba'])
                ->and($pages->viewData('sortOn'))->toBe('order')
                ->and($pages->viewData('sortIsDefault'))->toBeTrue()
                // the Sort button and its menu: the order in force checked, nothing lit
                ->and($pages->html())->toContain('data-sort="order" data-act="0"')
                ->and($pages->html())->toContain('role="menuitemradio" aria-checked="true"');

            $posts = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc']);
            expect(lhTitles($posts))->toBe(['Mới', 'Cũ'])
                ->and($posts->viewData('sortOn'))->toBe('updated')
                ->and($posts->viewData('sortWay'))->toBe('desc');
        });
    });

    it('starts each order in its natural direction: dates and views newest or most first, names A to Z', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            $section = lhSection();
            $cat = (int) $section->taxonomy_id;
            lhPost($user, 'Bê', $cat, ['view_count' => 5, 'published_at' => now()->subDays(1)]);
            lhPost($user, 'An', $cat, ['view_count' => 50, 'published_at' => now()->subDays(9)]);
            lhPost($user, 'Cê', $cat, ['view_count' => 1, 'published_at' => now()->subDays(4)]);

            $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc']);

            $list->call('setSort', 'views')->assertSet('sortKey', 'views')->assertSet('sortDir', '');
            expect(lhTitles($list))->toBe(['An', 'Bê', 'Cê'])
                ->and($list->viewData('sortWay'))->toBe('desc')
                // a changed order lights the button and adds a chip that puts the default back
                ->and($list->html())->toContain('data-sort="views" data-act="1"')
                ->and($list->html())->toContain(e(__('entries.sort_chip_views_desc')))
                ->and($list->html())->toContain('wire:click="resetSort"');

            $list->call('setSort', 'pub');
            expect(lhTitles($list))->toBe(['Bê', 'Cê', 'An']);

            $list->call('setSort', 'name');
            expect(lhTitles($list))->toBe(['An', 'Bê', 'Cê'])->and($list->viewData('sortWay'))->toBe('asc');

            // the column header turns the same order round
            $list->call('sortBy', 'name')->assertSet('sortDir', 'desc');
            expect(lhTitles($list))->toBe(['Cê', 'Bê', 'An']);

            $list->call('resetSort')->assertSet('sortKey', '')->assertSet('sortDir', '');
            expect($list->viewData('sortOn'))->toBe('updated');
        });
    });

    it('offers each type only its own orders and ignores any other', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lhSection();

            $pages = Livewire::actingAs($user->fresh())->test(Index::class);
            expect($pages->viewData('sorts'))->toBe(['order', 'name', 'updated', 'status', 'views', 'creator']);
            $pages->call('setSort', 'pub')->assertSet('sortKey', '');
            $pages->set('sortKey', 'nonsense')->assertSet('sortKey', '');

            $posts = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc']);
            expect($posts->viewData('sorts'))->toBe(['name', 'updated', 'pub', 'status', 'views', 'creator']);
            $posts->call('setSort', 'order')->assertSet('sortKey', '');
        });
    });
});

describe('filters', function (): void {
    it('filters by translation state on a site with several languages', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            foreach (['vi', 'en', 'ja'] as $i => $code) {
                SiteLocale::query()->firstOrCreate(['locale' => $code], ['is_default' => $i === 0, 'enabled' => true]);
            }
            lhPage($user, 'Chỉ Việt', ['position' => 0]);
            lhTranslate(lhPage($user, 'Có Anh', ['position' => 1]), 'en');
            lhTranslate(lhPage($user, 'Đủ cả', ['position' => 2]), 'en', 'ja');

            $list = Livewire::actingAs($user->fresh())->test(Index::class);
            expect(collect($list->viewData('facets')['trans'])->pluck('count', 'value')->all())->toBe(['full' => 1, 'partial' => 1, 'none' => 1]);

            $list->set('transF', ['partial']);
            expect(lhTitles($list))->toBe(['Có Anh']);

            $list->call('toggleFilterValue', 'trans', 'none');
            expect(lhTitles($list))->toBe(['Chỉ Việt', 'Có Anh'])->and($list->viewData('filterCount'))->toBe(2);

            // values the screen does not know are dropped
            $list->set('transF', ['full', 'bogus'])->assertSet('transF', ['full']);
            expect(lhTitles($list))->toBe(['Đủ cả']);
        });
    });

    it('does not offer the translation filter on a one-language site', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lhPage($user, 'Trang');

            $list = Livewire::actingAs($user->fresh())->test(Index::class)->set('transF', ['none']);
            expect($list->viewData('facets')['trans'])->toBe([])
                ->and(lhTitles($list))->toBe(['Trang'])
                ->and($list->html())->not->toContain('data-fsec="trans"');
        });
    });

    it('filters posts by publish date, presets and a range, leaving out posts never published', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            $cat = (int) lhSection()->taxonomy_id;
            Carbon::setTestNow('2026-10-31 10:00:00');
            lhPost($user, 'Hôm nay', $cat, ['published_at' => now()]);
            lhPost($user, 'Tuần trước', $cat, ['published_at' => now()->subDays(5)]);
            lhPost($user, 'Tháng trước', $cat, ['published_at' => now()->subDays(40)]);
            lhPost($user, 'Nháp', $cat, ['status' => 'draft', 'published_at' => null]);

            $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc']);
            $list->set('when', 'today');
            expect(lhTitles($list))->toBe(['Hôm nay'])->and($list->viewData('filterCount'))->toBe(1);

            $list->set('when', '7');
            expect(lhTitles($list))->toEqualCanonicalizing(['Hôm nay', 'Tuần trước']);

            // a date picks the range; the preset gives way
            $list->set('from', '2026-09-01')->assertSet('when', Index::RANGE);
            $list->set('to', '2026-10-01');
            expect(lhTitles($list))->toBe(['Tháng trước']);

            // dates picked the wrong way round mean the same span
            $list->set('from', '2026-10-01')->set('to', '2026-09-01')->assertSet('from', '2026-09-01')->assertSet('to', '2026-10-01');

            $list->call('clearWhen')->assertSet('when', '')->assertSet('from', '')->assertSet('to', '');
            expect(lhTitles($list))->toHaveCount(4);

            $list->set('when', 'tomorrow')->assertSet('when', '');
            Carbon::setTestNow();
        });
    });

    it('never applies the time filter to pages', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            lhPage($user, 'Trang');

            $list = Livewire::actingAs($user->fresh())->test(Index::class)->set('when', '7');
            $list->assertSet('when', '');
            expect(lhTitles($list))->toBe(['Trang'])->and($list->html())->not->toContain('data-fsec="when"');
        });
    });

    it('puts the member first among the authors, named as their own', function (): void {
        $me = User::factory()->create(['name' => 'Lan']);
        $other = User::factory()->create(['name' => 'An']);

        app(Tenancy::class)->enter(1, function () use ($me, $other): void {
            grantRole($me, 'editor', ['entries.view']);
            lhPage($other, 'Của An');
            lhPage($me, 'Của Lan');

            $list = Livewire::actingAs($me->fresh())->test(Index::class);
            $creators = $list->viewData('facets')['creator'];
            expect($creators[0]['value'])->toBe($me->ulid)
                ->and($creators[0]['label'])->toBe(__('entries.f_mine', ['name' => 'Lan']))
                ->and($list->html())->toContain('data-fsec="creator"');
        });
    });

    it('counts each filter value and a chosen group for Lọc, and Bỏ lọc clears them but keeps the sort', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            foreach (['vi', 'en'] as $i => $code) {
                SiteLocale::query()->firstOrCreate(['locale' => $code], ['is_default' => $i === 0, 'enabled' => true]);
            }
            lhPage($user, 'Nháp');
            lhPage($user, 'Đăng', ['status' => 'published']);

            $list = Livewire::actingAs($user->fresh())->test(Index::class)
                ->call('setSort', 'name')
                ->set('tab', 'draft')
                ->set('transF', ['none'])
                ->set('langF', ['vi'])
                ->set('search', 'Nh');
            expect($list->viewData('filterCount'))->toBe(3)
                ->and(lhTitles($list))->toBe(['Nháp'])
                // the active-filter line: the group, "N trong M trang" and Bỏ lọc
                ->and($list->html())->toContain(e(__('entries.match_of', ['n' => 1, 'm' => 1, 'unit' => __('entries.noun_page')])))
                ->and($list->html())->toContain('wire:click="clearAllFilters"');

            $list->call('clearAllFilters')
                ->assertSet('tab', 'all')
                ->assertSet('transF', [])
                ->assertSet('langF', [])
                ->assertSet('search', '')
                ->assertSet('sortKey', 'name');
            expect($list->viewData('filterCount'))->toBe(0)->and(lhTitles($list))->toEqualCanonicalizing(['Đăng', 'Nháp']);
        });
    });
});

describe('groups', function (): void {
    it('shows Chờ duyệt only once something waits, and Thùng rác only to who may empty it', function (): void {
        $boss = User::factory()->create();
        $writer = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($boss, $writer): void {
            grantRole($boss, 'editor', ['entries.view', 'entries.delete.any']);
            grantRole($writer, 'author', ['entries.view', 'entries.delete.own']);
            lhPage($boss, 'Trang');

            $html = Livewire::actingAs($boss->fresh())->test(Index::class)->html();
            expect($html)->toContain('data-g="trash"')->and($html)->not->toContain('data-g="pending"')
                ->and($html)->toContain('data-g="archived"');
            expect(Livewire::actingAs($writer->fresh())->test(Index::class)->html())->not->toContain('data-g="trash"');

            lhPage($boss, 'Chờ', ['status' => 'pending']);
            $list = Livewire::actingAs($boss->fresh())->test(Index::class);
            expect($list->html())->toContain('data-g="pending"');
            $list->set('tab', 'pending');
            expect(lhTitles($list))->toBe(['Chờ']);
        });
    });

    it('drops a group the screen does not know, and the selection when the group changes', function (): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);
            $page = lhPage($user, 'Trang');

            Livewire::actingAs($user->fresh())->test(Index::class)
                ->set('selected', [$page->ulid])
                ->set('tab', 'nonsense')
                ->assertSet('tab', 'all')
                ->assertSet('selected', []);
        });
    });
});

describe('view choices', function (): void {
    it('keeps Cột and Hiện trên thẻ in the member\'s own preferences, never another member\'s', function (): void {
        $lan = User::factory()->create();
        $an = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($lan, $an): void {
            grantRole($lan, 'editor', ['entries.view']);
            grantRole($an, 'editor', ['entries.view']);
            lhSection();

            Livewire::actingAs($lan->fresh())->test(Index::class)
                ->assertSet('cols', ['path', 'langs', 'creator'])
                ->assertSet('card', [])
                ->set('cols', ['creator', 'bogus', 'path'])
                ->assertSet('cols', ['path', 'creator'])
                ->set('card', ['langs', 'tpl']);

            $saved = $lan->fresh()->preferences['lists']['entries.page'] ?? null;
            expect($saved)->toEqual(['cols' => ['path', 'creator'], 'card' => ['tpl', 'langs']]);

            // the next visit opens with the member's choice; another member keeps the defaults
            Livewire::actingAs($lan->fresh())->test(Index::class)
                ->assertSet('cols', ['path', 'creator'])
                ->assertSet('card', ['tpl', 'langs']);
            Livewire::actingAs($an->fresh())->test(Index::class)
                ->assertSet('cols', ['path', 'langs', 'creator'])
                ->assertSet('card', []);
            expect($an->fresh()->preferences['lists'] ?? null)->toBeNull();

            // each list keeps its own choice: the posts section starts from the defaults
            Livewire::actingAs($lan->fresh())->test(Index::class, ['section' => 'tin-tuc'])
                ->assertSet('cols', ['path', 'langs', 'creator'])
                ->set('cols', []);
            expect($lan->fresh()->preferences['lists'])->toHaveKeys(['entries.page', 'entries.post.tin-tuc'])
                ->and($lan->fresh()->preferences['lists']['entries.post.tin-tuc']['cols'])->toBe([]);
        });
    });

    it('keeps the member\'s other preferences when saving a view choice', function (): void {
        $user = User::factory()->create();
        $user->forceFill(['preferences' => ['theme' => ['mode' => 'dark']]])->save();

        app(Tenancy::class)->enter(1, function () use ($user): void {
            grantRole($user, 'editor', ['entries.view']);

            Livewire::actingAs($user->fresh())->test(Index::class)->set('card', ['path']);
            expect($user->fresh()->preferences['theme'])->toBe(['mode' => 'dark'])
                ->and($user->fresh()->preferences['lists']['entries.page']['card'])->toBe(['path']);
        });
    });
});

describe('authorization', function (): void {
    it('refuses every new entry point once the member may no longer view entries', function (string $step): void {
        $user = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($user, $step): void {
            grantRole($user, 'editor', ['entries.view']);
            lhSection();
            $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc']);

            $user->roles()->firstOrFail()->revokePermissionTo('entries.view');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            auth()->setUser($user->fresh()); // the next request reads the member afresh

            $answer = match ($step) {
                'setSort' => $list->call('setSort', 'name'),
                'resetSort' => $list->call('resetSort'),
                'clearWhen' => $list->call('clearWhen'),
                'cols' => $list->set('cols', ['path']),
                'card' => $list->set('card', ['path']),
                'filter' => $list->set('transF', ['full']),
                'when' => $list->set('when', '7'),
                'tab' => $list->set('tab', 'draft'),
            };
            $answer->assertForbidden();
            expect($user->fresh()->preferences['lists'] ?? null)->toBeNull();
        });
    })->with(['setSort', 'resetSort', 'clearWhen', 'cols', 'card', 'filter', 'when', 'tab']);
});

describe('query budget', function (): void {
    it('keeps the filtered and re-sorted lists on the flat budget', function (): void {
        $editor = User::factory()->create();

        app(Tenancy::class)->enter(1, function () use ($editor): void {
            grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
            foreach (['vi', 'en'] as $i => $code) {
                SiteLocale::query()->firstOrCreate(['locale' => $code], ['is_default' => $i === 0, 'enabled' => true]);
            }
            $cat = (int) lhSection()->taxonomy_id;
            foreach (range(1, 12) as $i) {
                lhTranslate(lhPage($editor, "Trang {$i}", ['position' => $i]), 'en');
                lhPost($editor, "Bài {$i}", $cat, ['published_at' => now()->subDays($i), 'view_count' => $i]);
            }
        });

        foreach ([
            '/admin/content?sort=views&tr[]=partial&tab=draft',
            '/admin/content?section=tin-tuc&when=30&sort=pub&dir=asc&tr[]=partial',
            '/admin/content?section=tin-tuc&from=2026-01-01&to=2026-12-31&author[]=x',
        ] as $url) {
            $this->actingAs($editor)->get('http://a.test'.$url)->assertOk(); // warm caches
            $count = countQueries(fn () => $this->actingAs($editor)->get('http://a.test'.$url)->assertOk());
            expect($count)->toBeLessThanOrEqual(22);
        }
    });
});

describe('shared parts', function (): void {
    it('renders the Sort menu as a menu of radio items, the order in force named on the button', function (): void {
        $html = (string) $this->blade('<x-bs-admin::sort-menu :options="$o" active="views" :lit="true" set="setSort" />', [
            'o' => [['key' => 'updated', 'label' => 'Mới sửa gần đây'], ['key' => 'views', 'label' => 'Lượt xem nhiều nhất', 'icon' => 'ri-eye']],
        ]);

        expect($html)->toContain('role="menu" aria-label="'.e(__('admin.list.sort_by')).'"')
            ->and(substr_count($html, 'role="menuitemradio"'))->toBe(2)
            ->and(substr_count($html, 'aria-checked="true"'))->toBe(1)
            ->and($html)->toContain('aria-label="'.e(__('admin.list.sort_named', ['name' => 'Lượt xem nhiều nhất'])).'"')
            ->and($html)->toContain('aria-haspopup="menu"')
            ->and($html)->toContain('data-sort="views" data-act="1"')
            ->and($html)->toContain('<span data-sort-text>'.e(__('admin.list.sort')).'</span>')
            ->and($html)->toContain("wire:click=\"setSort('views')\"");
    });

    it('lets the tools row drop the Sort word first, then the Filter word, from the shared stylesheet', function (): void {
        $html = (string) $this->blade('<x-bs-admin::tool-row><span>x</span></x-bs-admin::tool-row>');
        $css = (string) file_get_contents(base_path('resources/css/admin-list.css'));

        expect($html)->toContain('class="list-tools-row"')
            ->and($html)->toContain("for (const step of ['1', '2', '3'])")
            ->and($css)->toContain(".list-tools-row:is([data-fit='1'], [data-fit='2'], [data-fit='3']) [data-sort-text],")
            ->and($css)->toContain(".list-tools-row:is([data-fit='2'], [data-fit='3']) [data-lf-text] {")
            ->and($css)->not->toMatch('/\b4[04]px\b/');
    });

    it('keeps a sheet-only section out of the section buttons, with its note and its own width class', function (): void {
        $html = (string) $this->blade('<x-bs-admin::filter-panel :sections="$s" :active="0" />', ['s' => [
            ['key' => 'status', 'label' => 'Trạng thái', 'model' => 'tab', 'value' => 'all', 'sheet' => true, 'class' => 'min-[900px]:hidden', 'options' => [['value' => 'all', 'label' => 'Tất cả']]],
            ['key' => 'when', 'label' => 'Thời gian', 'model' => 'when', 'value' => '', 'note' => 'Theo ngày đăng', 'options' => [['value' => '', 'label' => 'Mọi lúc']]],
        ]]);

        expect($html)->not->toContain('data-fsec="status"')
            ->and($html)->toContain('data-fsec="when"')
            ->and($html)->toContain('<legend data-sec="status"')
            ->and($html)->toContain('data-fsheet="status"')
            ->and($html)->toMatch('/class="[^"]*min-\[900px\]:hidden[^"]*" data-fsheet="status"/')
            ->and($html)->toContain('Theo ngày đăng')
            ->and($html)->toContain('data-lf');
    });
});

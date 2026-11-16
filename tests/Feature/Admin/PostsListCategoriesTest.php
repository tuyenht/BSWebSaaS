<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\FirstHints;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * A post list's categories, cover and role card. The Chuyên mục filter lists the section's own
 * categories (never the section's category itself, never another section's or website's) and
 * filters by a post's MAIN category, a category bringing everything under it. Each row names its
 * main category as a path from the top of the section's tree, and shows its cover small (or an
 * empty frame). Above the list a card tells the member their role, how the section publishes and
 * what they may do — from their real permissions, limits and the section's workflow — until they
 * press "Đã hiểu", which is remembered for them alone.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function plcatCategory(string $name, ?Taxonomy $parent = null, int $position = 0): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parent?->id, 'position' => $position]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name).'-'.$category->id]);

    return $category;
}

/**
 * The "Tin tức" section on its own category, with a small tree under it:
 *   Tin tức (the section) › Tin công ty › Tuyển dụng
 *                         › Kiến thức  › Gia công CNC › Dung sai
 * and a "Dịch vụ" section built on a sub-category of Kiến thức (its posts are not news).
 *
 * @return array<string, Taxonomy>
 */
function plcatTree(): array
{
    $root = plcatCategory('Tin tức');
    ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $root->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
    $company = plcatCategory('Tin công ty', $root, 1);
    $jobs = plcatCategory('Tuyển dụng', $company, 1);
    $know = plcatCategory('Kiến thức', $root, 2);
    $cnc = plcatCategory('Gia công CNC', $know, 1);
    $tolerance = plcatCategory('Dung sai', $cnc, 1);
    $services = plcatCategory('Dịch vụ', $know, 2);
    ContentSection::query()->create(['key' => 'services', 'label' => 'Dịch vụ', 'entry_type' => 'post', 'taxonomy_id' => $services->id, 'permission_prefix' => 'entries', 'sort_order' => 2]);

    return compact('root', 'company', 'jobs', 'know', 'cnc', 'tolerance', 'services');
}

/** @param  list<int>  $categories  main category first */
function plcatPost(string $title, array $categories, array $meta = []): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => $meta]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title), 'status' => 'draft', 'body' => ['blocks' => []]]);
    if ($categories !== []) {
        $entry->assignCategories($categories);
    }

    return $entry;
}

function plcatEditor(): User
{
    $user = User::factory()->create();
    grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);

    return $user->fresh();
}

/** @return list<string> */
function plcatTitles(mixed $test): array
{
    return collect($test->viewData('rows')->items())->map(fn (array $row): string => $row['entry']->translations->first()->title)->sort()->values()->all();
}

it('filters by a main category and everything under it, never by a second category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $user = plcatEditor();
        $t = plcatTree();
        plcatPost('Tuyển kỹ sư', [$t['jobs']->id]);
        plcatPost('Tin họp mặt', [$t['company']->id]);
        plcatPost('Bảng dung sai', [$t['tolerance']->id]);
        plcatPost('Phay CNC', [$t['cnc']->id]);
        // filed under Kiến thức, also tagged Tin công ty: it is not company news
        plcatPost('Đọc bản vẽ', [$t['know']->id, $t['company']->id]);
        plcatPost('Chung chung', [$t['root']->id]);

        $test = Livewire::actingAs($user)->test(Index::class, ['section' => 'posts']);
        expect(plcatTitles($test))->toHaveCount(6);

        $test->set('catF', [$t['company']->ulid]);
        expect(plcatTitles($test))->toBe(['Tin họp mặt', 'Tuyển kỹ sư'])
            ->and($test->get('catF'))->toEqualCanonicalizing([$t['company']->ulid, $t['jobs']->ulid])
            ->and($test->viewData('filterCount'))->toBe(1);

        // a deep branch: Gia công CNC brings Dung sai
        $test->call('resetFilterGroup', 'cat')->call('toggleFilterValue', 'cat', $t['cnc']->ulid);
        expect(plcatTitles($test))->toBe(['Bảng dung sai', 'Phay CNC'])
            ->and($test->viewData('activeChips'))->toBe([['group' => 'cat', 'value' => $t['cnc']->ulid, 'label' => 'Chuyên mục: Gia công CNC']]);

        // unticking a child unticks the parent above it: only the child's sibling-free rest stays
        $test->call('toggleFilterValue', 'cat', $t['tolerance']->ulid);
        expect($test->get('catF'))->toBe([])
            ->and(plcatTitles($test))->toHaveCount(6);
    });
});

it('offers the section categories only, indented, with the posts filed in each branch', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $user = plcatEditor();
        $t = plcatTree();
        plcatPost('Tuyển kỹ sư', [$t['jobs']->id]);
        plcatPost('Tin họp mặt', [$t['company']->id]);
        plcatPost('Phay CNC', [$t['cnc']->id]);
        plcatPost('Dịch vụ phay', [$t['services']->id]);

        $test = Livewire::actingAs($user)->test(Index::class, ['section' => 'posts']);
        $options = collect($test->viewData('catOptions'))->map(fn (array $o): array => [$o['label'], $o['depth'], $o['count'] ?? null])->all();
        expect($options)->toBe([
            ['Tin công ty', 0, 2],
            ['Tuyển dụng', 1, 1],
            ['Kiến thức', 0, 1],
            ['Gia công CNC', 1, 1],
            ['Dung sai', 2, 0],
        ]);

        // no number where it would not be what the list holds (another group, a search)
        $test->call('setTab', 'draft');
        expect(collect($test->viewData('catOptions'))->every(fn (array $o): bool => ! isset($o['count'])))->toBeTrue();
    });
});

it('drops a category key of another section or another website from the link', function (): void {
    $foreign = null;
    app(Tenancy::class)->enter(2, function () use (&$foreign): void {
        $foreign = plcatCategory('Khác')->ulid;
    });

    app(Tenancy::class)->enter(1, function () use ($foreign): void {
        $user = plcatEditor();
        $t = plcatTree();
        plcatPost('Tin họp mặt', [$t['company']->id]);

        $test = Livewire::withQueryParams(['cat' => [$foreign, $t['services']->ulid, $t['root']->ulid, 'rác']])
            ->actingAs($user)->test(Index::class, ['section' => 'posts']);
        expect($test->get('catF'))->toBe([])
            ->and(plcatTitles($test))->toBe(['Tin họp mặt']);
    });
});

it('names each row by its main category path, and shows its cover small or an empty frame', function (): void {
    config(['media.driver' => 'cloudflare']);
    app(Tenancy::class)->enter(1, function (): void {
        $user = plcatEditor();
        $t = plcatTree();
        plcatPost('Bảng dung sai', [$t['tolerance']->id], ['image_url' => 'site-1/bang-dung-sai-0123456789ab.jpg']);
        plcatPost('Chung chung', [$t['root']->id]);

        $test = Livewire::actingAs($user)->test(Index::class, ['section' => 'posts']);
        $byTitle = fn (string $key): array => collect($test->viewData('rows')->items())
            ->mapWithKeys(fn (array $row): array => [$row['entry']->translations->first()->title => $test->viewData($key)[$row['entry']->ulid] ?? null])->all();

        expect($byTitle('rowCategory'))->toEqualCanonicalizing([
            'Bảng dung sai' => 'Kiến thức › Gia công CNC › Dung sai',
            'Chung chung' => 'Tin tức',
        ]);
        expect($byTitle('rowCover')['Bảng dung sai'])->toContain('bang-dung-sai-0123456789ab.jpg')
            ->and($byTitle('rowCover')['Chung chung'])->toBeNull();

        $test->assertSeeHtml('loading="lazy"')
            ->assertSee('Kiến thức › Gia công CNC › Dung sai')
            ->assertSee(__('entries.cover_none'));
    });
});

it('offers no category filter where there are no categories, and says a post has none', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $user = plcatEditor();
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Bài viết', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]);
        $post = plcatPost('Không chuyên mục', []);

        $test = Livewire::actingAs($user)->test(Index::class, ['section' => 'posts']);
        expect($test->viewData('catOptions'))->toBe([])
            ->and($test->viewData('rowCategory'))->toBe([$post->ulid => null]);
        $test->assertSee(__('entries.cat_none'))->assertDontSeeHtml('data-fsec="cat"');
    });
});

it('shows the category column by default, also to a member who saved their columns before it existed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $fresh = plcatEditor();
        $old = plcatEditor();
        $old->preferences = ['lists' => ['entries.post.posts' => ['cols' => ['path', 'creator'], 'card' => []]]];
        $old->save();
        plcatTree();

        expect(Livewire::actingAs($fresh)->test(Index::class, ['section' => 'posts'])->get('cols'))->toContain('cat')
            ->and(Livewire::actingAs($old->fresh())->test(Index::class, ['section' => 'posts'])->get('cols'))->toBe(['cat', 'path', 'creator']);

        // hidden once by the member, it stays hidden
        Livewire::actingAs($old->fresh())->test(Index::class, ['section' => 'posts'])->set('cols', ['path']);
        expect(Livewire::actingAs($old->fresh())->test(Index::class, ['section' => 'posts'])->get('cols'))->toBe(['path']);
    });
});

it('words the role card from the member permissions, limits and the section workflow', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $t = plcatTree();
        $member = function (string $role, array $permissions): User {
            $user = User::factory()->create();
            grantRole($user, $role, ['entries.view', ...$permissions]);

            return $user->fresh();
        };
        $card = fn (User $user): ?array => Livewire::actingAs($user)->test(Index::class, ['section' => 'posts'])->viewData('roleNote');

        $editor = $member('Biên tập', ['entries.edit.any', 'entries.publish']);
        $writer = $member('Cộng tác viên', ['entries.edit.own', 'entries.submit']);
        $reader = $member('Người xem', []);
        $limited = $member('Biên tập mảng', ['entries.edit.any', 'entries.publish']);
        MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $t['know']->id]);

        expect($card($editor))->toBe(['role' => 'Biên tập', 'governed' => false, 'text' => 'Website đang đăng thẳng, không qua bước duyệt.', 'summary' => 'Được đăng · sửa được mọi bài'])
            ->and($card($writer)['summary'])->toBe('Được gửi duyệt · chỉ sửa bài của mình')
            ->and($card($reader)['summary'])->toBe('Chỉ xem · không sửa bài')
            ->and($card($limited->fresh())['summary'])->toBe('Được đăng · chỉ sửa bài trong phạm vi của bạn');

        $workflow = Workflow::query()->create(['key' => 'editorial', 'name' => 'Biên tập trước khi đăng', 'applies_to_type' => 'posts', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true, 'sort_order' => 1], ['key' => 'pending', 'label' => 'Chờ biên tập', 'sort_order' => 2],
            ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true, 'sort_order' => 3]] as $state) {
            $workflow->states()->create($state);
        }
        $workflow->transitions()->create(['key' => 'submit', 'label' => 'Gửi biên tập', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit']);
        $workflow->transitions()->create(['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve']);
        $approver = $member('Trưởng ban', ['entries.edit.any', 'entries.approve']);

        expect($card($writer))->toBe(['role' => 'Cộng tác viên', 'governed' => true,
            'text' => 'Website đang dùng “Biên tập trước khi đăng”: bài đi qua các bước duyệt trước khi lên website.', 'summary' => 'Được gửi biên tập · chỉ sửa bài của mình'])
            ->and($card($approver)['summary'])->toBe('Được duyệt đăng · sửa được mọi bài')
            ->and($card($editor)['summary'])->toBe('Chỉ xem · sửa được mọi bài');

        // another section's workflow does not govern this one
        $workflow->update(['applies_to_type' => 'services']);
        expect($card($writer)['governed'])->toBeFalse();
    });
});

it('keeps the role card until the member presses "Đã hiểu", for that member only', function (): void {
    $user = null;
    $other = null;
    app(Tenancy::class)->enter(1, function () use (&$user, &$other): void {
        plcatTree();
        $user = plcatEditor();
        $other = plcatEditor();
    });

    $this->actingAs($user)->get('http://a.test/admin/content?section=posts')->assertOk()
        ->assertSee('data-role-note', false)->assertSee('data-hint-ok="'.FirstHints::POSTS_ROLE.'"', false);

    $this->actingAs($user)->postJson('http://a.test/admin/hint-preference', ['key' => FirstHints::POSTS_ROLE])->assertOk();

    $this->actingAs($user->fresh())->get('http://a.test/admin/content?section=posts')->assertOk()->assertDontSee('data-role-note', false);
    $this->actingAs($other->fresh())->get('http://a.test/admin/content?section=posts')->assertOk()->assertSee('data-role-note', false);
    expect($other->fresh()->preferences)->toBe([]);
});

it('refuses to remember the role card for a guest', function (): void {
    $before = DB::connection('pgsql_admin')->table('users')->whereRaw("preferences::text like '%posts-role%'")->count();
    $this->post('http://a.test/admin/hint-preference', ['key' => FirstHints::POSTS_ROLE])->assertRedirect();
    expect(DB::connection('pgsql_admin')->table('users')->whereRaw("preferences::text like '%posts-role%'")->count())->toBe($before);
});

it('reads a categorised posts list on a flat query budget, whatever the number of rows', function (): void {
    $user = null;
    $t = [];
    app(Tenancy::class)->enter(1, function () use (&$user, &$t): void {
        $user = plcatEditor();
        $t = plcatTree();
    });
    $budget = function (int $rows, string $query) use ($user, $t): int {
        app(Tenancy::class)->enter(1, function () use ($rows, $t): void {
            DB::connection('pgsql_admin')->statement('truncate entries restart identity cascade');
            foreach (range(1, $rows) as $i) {
                plcatPost('Bài '.$i, [[$t['jobs']->id, $t['cnc']->id, $t['tolerance']->id, $t['root']->id][$i % 4]], $i % 2 ? ['image_url' => "site-1/anh-{$i}-0123456789ab.jpg"] : []);
            }
        });

        // the first request of a member fills what is kept between requests (permissions): measured on the second
        $this->actingAs($user)->get('http://a.test/admin/content?section=posts'.$query)->assertOk();

        return countQueries(fn () => $this->actingAs($user)->get('http://a.test/admin/content?section=posts'.$query)->assertOk());
    };

    foreach (['', '&cat[]='.$t['know']->ulid, '&cat[]='.$t['know']->ulid.'&q=bai'] as $query) {
        $few = $budget(3, $query);
        $many = $budget(40, $query);
        expect($many)->toBe($few, "query string {$query}")
            ->and($many)->toBeLessThanOrEqual(27, "query string {$query}");
    }
});

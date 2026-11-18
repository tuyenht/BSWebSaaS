<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The «Cột» menu of the posts and pages list, as design board 05 draws it: Đường dẫn · Mô tả · Bản dịch ·
 * Chuyên mục (Mẫu trang) · Bước duyệt (only under an approval workflow) · Lượt xem · Người tạo, then
 * «Nút trên dòng» with «Hiện thêm nút khi đủ chỗ», off until the member turns it on.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function cmSection(): ContentSection
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    return ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id, 'permission_prefix' => 'entries', 'sort_order' => 1]);
}

/** @param  array<string, mixed>  $extra */
function cmPost(User $author, string $title, ContentSection $section, array $extra = [], ?string $excerpt = null): Entry
{
    $status = $extra['status'] ?? 'published';
    $entry = Entry::query()->create($extra + ['type' => 'post', 'status' => $status, 'author_id' => $author->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => 'cm-'.$entry->id, 'status' => $status === 'published' ? 'published' : 'draft', 'excerpt' => $excerpt, 'body' => ['blocks' => []]]);
    $entry->assignCategories([(int) $section->taxonomy_id]);

    return $entry;
}

function cmWorkflow(): Workflow
{
    $workflow = Workflow::query()->create(['key' => 'editorial', 'name' => 'Biên tập', 'applies_to_type' => 'tin-tuc', 'is_active' => true]);
    foreach ([['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true, 'sort_order' => 1], ['key' => 'pending', 'label' => 'Chờ biên tập', 'sort_order' => 2],
        ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true, 'sort_order' => 3]] as $state) {
        $workflow->states()->create($state);
    }
    $workflow->transitions()->create(['key' => 'submit', 'label' => 'Gửi biên tập', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit']);
    $workflow->transitions()->create(['key' => 'return', 'label' => 'Trả lại', 'from_state' => 'pending', 'to_state' => 'draft', 'required_permission' => 'entries.approve', 'requires_note' => true]);
    $workflow->transitions()->create(['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve']);

    return $workflow;
}

it('offers the board\'s columns in its order, Bước duyệt only while the section runs an approval workflow', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $section = cmSection();
        cmPost($user, 'Bài một', $section);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $user->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang một', 'slug' => 'cm-trang', 'status' => 'draft', 'body' => ['blocks' => []]]);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertSet('cols', ['path', 'langs', 'cat', 'step', 'views', 'creator'])
            ->assertSeeInOrder([__('entries.col_path'), __('entries.col_desc'), __('entries.col_category'), __('entries.col_views'), __('entries.col_creator')])
            ->assertDontSee(__('entries.col_step'));

        $pages = Livewire::actingAs($user->fresh())->test(Index::class)
            ->assertSet('cols', ['path', 'langs', 'views', 'creator'])
            ->assertDontSee(__('entries.col_step'));
        // the menu's own choices, in its order
        $menu = Str::after($pages->html(), 'id="entries-cols"');
        expect(Str::before($menu, 'role="separator"'))->toContain(__('entries.title_label'));
        $opts = Str::after($menu, __('admin.list.cols_optional'));
        $at = array_map(fn (string $label): int|false => strpos($opts, e($label)), [__('entries.col_path'), __('entries.col_desc'), __('entries.filter_template'), __('entries.col_views'), __('entries.col_creator')]);
        expect($at)->not->toContain(false)->and($at)->toBe(collect($at)->sort()->values()->all());

        cmWorkflow();
        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertSeeInOrder([__('entries.col_category'), __('entries.col_step'), __('entries.col_views')])
            ->assertSee('list-c-step', false);
    });
});

it('names each row\'s workflow step and what comes next, read once for the page', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $section = cmSection();
        cmWorkflow();
        cmPost($user, 'Nháp mới', $section, ['status' => 'draft']);
        cmPost($user, 'Đang chờ', $section, ['status' => 'pending', 'workflow_state' => 'pending']);
        cmPost($user, 'Đã lên', $section, ['workflow_state' => 'published']);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertSee(__('entries.step_of', ['n' => 1, 'm' => 3]))->assertSee(__('entries.step_next', ['label' => 'Gửi biên tập']))
            ->assertSee(__('entries.step_of', ['n' => 2, 'm' => 3]))->assertSee(__('entries.step_next', ['label' => 'Duyệt đăng']))
            ->assertSee(__('entries.step_of', ['n' => 3, 'm' => 3]))->assertSee(__('entries.step_last'))
            ->set('cols', ['path'])
            ->assertDontSee(__('entries.step_of', ['n' => 1, 'm' => 3]));
    });
});

it('shows a post\'s description only once the member picks Mô tả', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $section = cmSection();
        cmPost($user, 'Có mô tả', $section, [], 'Tóm tắt ngắn của bài viết');

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertDontSee('Tóm tắt ngắn của bài viết')
            ->set('cols', ['path', 'desc'])
            ->assertSee('Tóm tắt ngắn của bài viết');
    });
});

it('counts views only on a published post, under the member\'s Lượt xem choice', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $section = cmSection();
        cmPost($user, 'Đang lên', $section, ['view_count' => 1234]);
        cmPost($user, 'Còn nháp', $section, ['status' => 'draft', 'view_count' => 777]);
        cmPost($user, 'Riêng tư', $section, ['status' => 'private', 'view_count' => 555]);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertSee(__('entries.views_count', ['count' => Number::format(1234, locale: app()->getLocale())]))
            ->assertSee('x-show="vViews"', false)
            ->assertDontSee(__('entries.views_count', ['count' => '777']))
            ->assertDontSee(__('entries.views_count', ['count' => '555']));
    });
});

it('draws Nhân bản and Thùng rác on the rows only while the member has «Hiện thêm nút khi đủ chỗ» on, kept for them', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user, $other): void {
        grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any']);
        grantRole($other, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any']);
        $section = cmSection();
        cmPost($user, 'Bài một', $section);

        $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertSet('xbtn', false)
            ->assertSee(__('entries.xbtn'))
            ->assertSee(__('entries.xbtn_note', ['list' => __('entries.quoted', ['text' => __('entries.action_duplicate')]).', '.__('entries.quoted', ['text' => __('entries.row_trash')])]))
            ->assertDontSee('data-x="xd"', false)
            ->assertDontSee('data-x="xt"', false);

        $list->set('xbtn', true)
            ->assertSee('data-x="xd"', false)
            ->assertSee('data-x="xt"', false)
            ->assertSee('data-xm="xd"', false);
        expect($user->fresh()->preferences['lists']['entries.post.tin-tuc']['xbtn'])->toBeTrue();

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])->assertSet('xbtn', true);
        Livewire::actingAs($other->fresh())->test(Index::class, ['section' => 'tin-tuc'])->assertSet('xbtn', false);
    });
});

it('leaves «Nút trên dòng» out for a member who may neither copy nor remove a row, and in the Trash', function (): void {
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view']);
        $section = cmSection();
        cmPost($user, 'Bài một', $section);

        Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'tin-tuc'])
            ->assertDontSee(__('entries.xbtn'));
    });
});

it('adds no query per row for Mô tả, Bước duyệt, Lượt xem and the row buttons', function (array $on, bool $xbtn): void {
    $user = User::factory()->create();
    $all = ['path', 'desc', 'langs', 'cat', 'step', 'views', 'creator'];
    $prefs = fn (bool $with): array => ['lists' => ['entries.post.tin-tuc' => ['cols' => $with ? ['path', ...$on] : ['path'], 'card' => [], 'xbtn' => $with && $xbtn, 'offered' => $all]]];

    app(Tenancy::class)->enter(1, function () use ($user): void {
        grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.delete.any']);
        cmPost($user, 'Bài 0', cmSection(), [], 'Mô tả 0');
        cmWorkflow();
    });
    $count = function (bool $on) use ($user, $prefs): int {
        $user->forceFill(['preferences' => $prefs($on)])->save();

        return countQueries(fn () => $this->actingAs($user->fresh())->get('http://a.test/admin/content?section=tin-tuc')->assertOk());
    };
    $count(false); // the first draw after rows change warms what later draws reuse
    $few = $count(true) - $count(false);
    app(Tenancy::class)->enter(1, function () use ($user): void {
        $section = ContentSection::query()->where('key', 'tin-tuc')->firstOrFail();
        foreach (range(1, 15) as $i) {
            cmPost($user, 'Bài '.$i, $section, ['status' => $i % 2 ? 'published' : 'draft', 'view_count' => $i, 'workflow_state' => $i % 2 ? 'published' : 'draft'], 'Mô tả '.$i);
        }
    });

    // what the choices cost stays the same for 1 row and for 16: at most the workflow's steps and moves, read once
    $count(false);
    expect($count(true) - $count(false))->toBe($few)->and($few)->toBeLessThanOrEqual(2);
})->with(['desc' => [['desc'], false], 'step' => [['step'], false], 'views' => [['views'], false], 'buttons' => [[], true], 'others' => [['langs', 'cat', 'creator'], false]]);

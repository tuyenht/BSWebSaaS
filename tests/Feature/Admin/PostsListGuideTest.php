<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\FirstHints;
use App\Modules\Admin\Support\Guide\Guide;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Livewire\Livewire;

/*
 * The posts and pages lists' «Hướng dẫn»: one button beside the title, and the member's own guide
 * printed with it — the parts and steps worded from their real permissions and the section's
 * workflow, the steps they cannot use left out — in the admin's language, every line from lang/*.json.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
    $this->withoutMiddleware(PreventRequestForgery::class);
});

function pgSection(): void
{
    ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]);
}

/** @param  list<string>  $permissions */
function pgMember(string $role, array $permissions): User
{
    $user = User::factory()->create();
    grantRole($user, $role, ['entries.view', ...$permissions]);

    return $user->fresh();
}

/** @return array<string, mixed> */
function pgGuide(User $user): array
{
    return Livewire::actingAs($user)->test(Index::class, ['section' => 'posts'])->viewData('guide');
}

/**
 * Each part's steps, as `id => first variant's text`.
 *
 * @param  array<string, mixed>  $guide
 * @return array<string, array<string, string>>
 */
function pgSteps(array $guide): array
{
    $parts = [];
    foreach ($guide['parts'] as $part) {
        foreach ($part['steps'] as $step) {
            $parts[$part['id']][$step['id']] = $step['v'][0]['text'];
        }
    }

    return $parts;
}

it('guides an owner on a website that publishes straight, naming who sends posts for review', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pgSection();
        $owner = User::factory()->create();
        grantRole($owner, 'owner', PermissionRegistry::TENANT_PERMISSIONS);
        pgMember('Cộng tác viên', ['entries.create', 'entries.edit.own', 'entries.submit']);

        $guide = pgGuide($owner->fresh());
        $steps = pgSteps($guide);

        expect(array_keys($steps))->toBe(['main', 'row', 'find', 'bulk'])
            ->and(array_keys($steps['main']))->toBe(['intro', 'add', 'tabs', 'review', 'open', 'status'])
            ->and($steps['main']['add'])->toBe('Bấm [[Thêm bài viết]] để mở trình soạn. Nhập tiêu đề và nội dung rồi đăng là bài viết lên website. Chưa xong thì bài viết tự lưu thành bản nháp, khách chưa thấy.')
            ->and($steps['main']['review'])->toContain('do nhóm Cộng tác viên gửi nằm ở tab [[Chờ duyệt]]')
            ->and($steps['main']['status'])->toContain('[[@]]')
            ->and($steps['find'])->toHaveKey('trash')
            // the group row's «Khác» opens part 4, naming what its menu holds when the step shows
            ->and(array_key_first($steps['find']))->toBe('more-tabs')
            ->and($steps['find']['more-tabs'])->toBe('Nút này gom các trạng thái ít dùng: [[@menu]]. Chọn một mục thì mục đó hiện lên hàng tab.')
            ->and($steps['row'])->toHaveKey('more')
            ->and($guide['role']['name'])->not->toBe('')
            ->and($guide['scenario'])->toMatch('/^[0-9a-f]{12}$/')
            ->and($guide['member'])->toMatch('/^[0-9a-f]{16}$/')
            ->and(array_keys($guide['ui']))->toBe(array_keys(Guide::ui()));

        // the step the owner's phone shows instead of the tabs, and the review step's phone wording
        $main = collect($guide['parts'][0]['steps'])->keyBy('id');
        expect(array_column($main['tabs']['v'], 't'))->toBe(['tabs', 'filter-button'])
            ->and(array_column($main['review']['v'], 't'))->toBe(['tab-pending', 'filter-button']);
    });
});

it('guides an editor under an approval workflow through its steps, without what the workflow does not let them do', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pgSection();
        $workflow = Workflow::query()->create(['key' => 'editorial', 'name' => 'Biên tập trước khi đăng', 'applies_to_type' => 'posts', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true, 'sort_order' => 1], ['key' => 'pending', 'label' => 'Chờ biên tập', 'sort_order' => 2],
            ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true, 'sort_order' => 3]] as $state) {
            $workflow->states()->create($state);
        }
        $workflow->transitions()->create(['key' => 'submit', 'label' => 'Gửi biên tập', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit']);
        $workflow->transitions()->create(['key' => 'return', 'label' => 'Trả lại', 'from_state' => 'pending', 'to_state' => 'draft', 'required_permission' => 'entries.approve', 'requires_note' => true]);
        $workflow->transitions()->create(['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve']);
        $writer = pgMember('Biên tập viên', ['entries.create', 'entries.edit.own', 'entries.submit']);

        $guide = pgGuide($writer);
        $steps = pgSteps($guide);

        expect($steps['main']['add'])->toBe('Bấm [[Thêm bài viết]] để mở trình soạn. Viết xong bạn gửi đi duyệt; bài viết qua 1 bước duyệt rồi mới lên website. Bạn nhận thông báo khi có kết quả.')
            // the workflow reviews in its own inbox; the member may not change a post's status here
            ->and($steps['main'])->not->toHaveKey('review')
            ->and($steps['main']['status'])->toBe('Nhãn này cho biết bài viết đang ở trạng thái nào. Nhóm quyền của bạn không đổi trạng thái được.')
            ->and($steps['find'])->not->toHaveKey('trash');

        // a workflow is a scenario of its own: the parts seen are remembered apart from the straight one
        $workflow->update(['is_active' => false]);
        expect(pgGuide($writer)['scenario'])->not->toBe($guide['scenario']);
    });
});

it('guides a reader to read, leaving out adding, editing, deleting and the row menu', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pgSection();
        $reader = pgMember('Người xem', []);

        $guide = pgGuide($reader);
        $steps = pgSteps($guide);
        $targets = collect($guide['parts'])->flatMap(fn (array $p) => collect($p['steps'])->flatMap(fn (array $s) => array_column($s['v'], 't')))->all();

        expect($guide['parts'][0]['title'])->toBe('Xem bài viết của website')
            ->and(array_keys($steps['main']))->toBe(['intro', 'tabs', 'open', 'status'])
            ->and($steps['main']['open'])->toStartWith('Bấm [[Xem]]')
            ->and($steps['row'])->not->toHaveKey('more')
            ->and($steps['find'])->not->toHaveKey('trash')
            ->and($targets)->not->toContain('add', 'row-edit', 'row-title-qe', 'row-path-edit', 'row-more', 'tab-trash');
    });
});

it('prints the button with its name, and the guide in each admin language without a missing line', function (string $locale): void {
    $user = null;
    app(Tenancy::class)->enter(1, function () use (&$user): void {
        pgSection();
        $user = User::factory()->create();
        grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS);
    });
    $lines = json_decode((string) file_get_contents(base_path("lang/{$locale}.json")), true);

    $page = $this->actingAs($user->fresh())->withSession(['locale' => $locale])->get('http://a.test/admin/content?section=posts')->assertOk();

    $page->assertSee('data-guide-open aria-haspopup="dialog" aria-expanded="false"', false)
        ->assertSee('aria-label="'.e($lines['guide.ui.open_aria']).'"', false)
        ->assertSee('data-guide-ctx', false)
        ->assertDontSee('guide.ui.', false)->assertDontSee('guide.posts.', false)->assertDontSee('guide.list.', false);

    app()->setLocale($locale);
    foreach (Guide::ui() as $key => $text) {
        expect($text)->toBe($lines['guide.ui.'.$key]);
    }
})->with(['vi', 'en', 'ja', 'zh']);

it('guides the pages list with the same parts, worded for pages, and no part on the page tree', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $owner = User::factory()->create();
        grantRole($owner, 'owner', PermissionRegistry::TENANT_PERMISSIONS);

        $guide = Livewire::actingAs($owner->fresh())->test(Index::class)->viewData('guide');
        $steps = pgSteps($guide);
        $find = collect(collect($guide['parts'])->firstWhere('id', 'find')['steps'])->keyBy('id');
        $row = collect(collect($guide['parts'])->firstWhere('id', 'row')['steps'])->keyBy('id');

        expect($guide['screen'])->toBe('pages')
            ->and($guide['title'])->toBe('Trang')
            ->and(array_keys($steps))->toBe(['main', 'row', 'find', 'bulk'])
            // the role card is a post list's: the page list's opening step has nothing to point at
            ->and($steps['main'])->not->toHaveKey('intro')
            ->and($steps['main']['add'])->toBe('Bấm [[Thêm trang]] để mở trình soạn. Nhập tiêu đề và nội dung rồi đăng là trang lên website. Chưa xong thì trang tự lưu thành bản nháp, khách chưa thấy.')
            ->and($steps['main']['tabs'])->toContain('số bên cạnh là số trang. Trang mới viết nằm ở [[Bản nháp]]')
            ->and($guide['role']['note'])->toBe('Mục Trang đang đăng thẳng, không qua bước duyệt.')
            ->and($guide['role']['may'])->toBe('được đăng · sửa được mọi trang')
            ->and($guide['purpose'])->toContain('Mọi trang của website nằm ở đây')
            ->and(array_column($row['path']['v'], 't'))->toBe(['row-path-edit', 'row-path-home', 'row-path'])
            ->and($row['path']['v'][1]['text'])->toContain('Trang chủ luôn ở địa chỉ gốc / nên không đổi đường dẫn được.')
            ->and($find['more-tabs']['v'][0]['t'])->toBe('tabs-more')
            ->and($find['more-tabs']['v'][0]['title'])->toBe('Khác')
            ->and($find['filter']['v'][1]['text'])->toStartWith('[[Mẫu trang]] lọc theo mẫu của trang')
            ->and($find['cols']['v'][0]['text'])->toContain('mẫu trang');
    });
});

it('guides a page editor who may only edit their own pages: no adding, own-pages wording, a status they cannot change', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = pgMember('Sửa trang', ['entries.edit.own']);

        $guide = Livewire::actingAs($editor)->test(Index::class)->viewData('guide');
        $steps = pgSteps($guide);
        $targets = collect($guide['parts'])->flatMap(fn (array $p) => collect($p['steps'])->flatMap(fn (array $s) => array_column($s['v'], 't')))->all();

        expect($guide['screen'])->toBe('pages')
            ->and($steps['main'])->not->toHaveKey('add')
            ->and($targets)->not->toContain('add')
            ->and($guide['role']['may'])->toContain('chỉ sửa trang của mình')
            ->and($steps['main']['status'])->toContain('Nhóm quyền của bạn không đổi trạng thái được');
    });
});

it('puts the guide button on the pages list in place of its old help', function (): void {
    $user = null;
    app(Tenancy::class)->enter(1, function () use (&$user): void {
        $user = User::factory()->create();
        grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS);
    });

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')->assertOk()
        ->assertSee('data-guide-open', false)->assertSee('data-guide-ctx', false)
        ->assertDontSee('entries-help', false)->assertDontSee('guide.pages.', false)->assertDontSee('data-role-copy', false);
});

it('prints a put-away role card once more, hidden, for the tour to open, and only on the first draw', function (): void {
    $user = null;
    app(Tenancy::class)->enter(1, function () use (&$user): void {
        pgSection();
        $user = User::factory()->create();
        grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS);
    });
    $user = $user->fresh();

    // the card on show: no copy
    $this->actingAs($user)->get('http://a.test/admin/content?section=posts')->assertOk()
        ->assertSee('data-role-note', false)->assertDontSee('data-role-copy', false);

    $this->actingAs($user)->postJson('http://a.test/admin/hint-preference', ['key' => FirstHints::POSTS_ROLE])->assertOk();
    $html = $this->actingAs($user->fresh())->get('http://a.test/admin/content?section=posts')->assertOk()
        ->assertDontSee('data-role-note', false)->getContent();

    expect($html)->toMatch('/<div wire:ignore class="contents" data-guide-role-box>\s*(?:<!--.*?-->\s*)*<section aria-label="[^"]+"\s+hidden data-role-copy\s+data-guide="role"/')
        ->and($html)->toContain('Mục Tin tức đang đăng thẳng, không qua bước duyệt.')
        ->and($html)->not->toContain('data-hint-ok="'.FirstHints::POSTS_ROLE.'"');

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $list = Livewire::actingAs($user->fresh())->test(Index::class, ['section' => 'posts']);
        expect($list->viewData('tourRoleNote'))->not->toBeNull()
            ->and(collect($list->viewData('guide')['parts'][0]['steps'])->pluck('id')->first())->toBe('intro')
            // a redraw prints it no more (the part redraws leave alone keeps the first)
            ->and($list->set('search', 'x')->viewData('tourRoleNote'))->toBeNull();
    });
});

it('words a single review step in the singular', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        app()->setLocale('en');
        pgSection();
        $workflow = Workflow::query()->create(['key' => 'single', 'name' => 'Duyệt', 'applies_to_type' => 'posts', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Draft', 'is_initial' => true, 'sort_order' => 1], ['key' => 'pending', 'label' => 'Pending', 'sort_order' => 2],
            ['key' => 'published', 'label' => 'Published', 'is_published' => true, 'sort_order' => 3]] as $state) {
            $workflow->states()->create($state);
        }
        // a withdraw back to the draft, needing no note and older than the forward step, must not be walked as a review step
        $workflow->transitions()->create(['key' => 'submit', 'label' => 'Send', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit']);
        $workflow->transitions()->create(['key' => 'withdraw', 'label' => 'Withdraw', 'from_state' => 'pending', 'to_state' => 'draft', 'required_permission' => 'entries.submit']);
        $workflow->transitions()->create(['key' => 'approve', 'label' => 'Approve', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve']);

        $steps = pgSteps(pgGuide(pgMember('Writer', ['entries.create', 'entries.edit.own', 'entries.submit'])));

        expect($steps['main']['add'])->toContain('goes through one review step before')
            ->not->toContain('review steps');
    });
});

it('builds the guide on the first draw only, at a query count that does not grow with the rows', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        pgSection();
        $owner = User::factory()->create();
        grantRole($owner, 'owner', PermissionRegistry::TENANT_PERMISSIONS);
        $owner = $owner->fresh();
        $post = fn (int $i) => Entry::query()->create(['type' => 'post', 'status' => 'draft'])->translations()->create([
            'locale' => 'vi', 'title' => "Bài {$i}", 'slug' => "bai-{$i}", 'status' => 'draft', 'body' => ['blocks' => []],
        ]);
        $post(0);
        // a first draw warms the request-wide caches (permissions, settings), so both counts start alike
        Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']);
        $one = countQueries(fn () => Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']));
        foreach (range(1, 10) as $i) {
            $post($i);
        }
        $eleven = countQueries(fn () => Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']));

        $list = Livewire::actingAs($owner)->test(Index::class, ['section' => 'posts']);
        expect($list->viewData('guide'))->not->toBeNull();
        // a redraw keeps the printed guide (wire:ignore) and does not build it again
        expect($list->set('search', 'Bài')->viewData('guide'))->toBeNull()
            ->and($eleven)->toBe($one);
    });
});

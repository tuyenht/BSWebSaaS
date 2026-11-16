<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\Guide\Guide;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Livewire\Livewire;

/*
 * The posts list's «Hướng dẫn»: one button beside the title, and the member's own guide printed
 * with it — the parts and steps worded from their real permissions and the section's workflow,
 * the steps they cannot use left out — in the admin's language, every line from lang/*.json.
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

it('keeps the old help on the pages list, which has no guide yet', function (): void {
    $user = null;
    app(Tenancy::class)->enter(1, function () use (&$user): void {
        $user = User::factory()->create();
        grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS);
    });

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')->assertOk()
        ->assertDontSee('data-guide-open', false)->assertSee('entries-help', false);
});

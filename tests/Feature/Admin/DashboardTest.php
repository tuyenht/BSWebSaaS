<?php

use App\Modules\Admin\Livewire\Dashboard;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Entitlement\Entitlement;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * "Tổng quan" — the website admin's first screen. Each card shows only what the member may act
 * on (the same permissions the screens it links to ask for), reads only the current website
 * (BelongsToSite + RLS), and stays on a flat query budget however much content the site holds.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->table('site_modules')->insert([
        ['site_id' => 1, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ['site_id' => 2, 'module_key' => 'contacts', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
});

/** A member of site 1 holding exactly $permissions. */
function dashMember(array $permissions, string $name = 'Thành viên'): User
{
    $user = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'role-'.md5(implode(',', $permissions)), $permissions));

    return $user->fresh() ?? $user;
}

/** A post on the current site, written by $author, in the posts section. */
function dashPost(int $categoryId, User $author, string $title, string $slug, string $status = 'draft'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'author_id' => $author->id, 'published_at' => $status === 'published' ? now() : null]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => $status, 'body' => ['blocks' => []]]);
    $entry->assignCategories([$categoryId]);

    return $entry->fresh() ?? $entry;
}

/** A `posts` section, under the bundled approval flow unless told otherwise; returns its category id. */
function dashCollection(bool $approvalFlow = true): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
    if ($approvalFlow) {
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
    }

    return $category->id;
}

/** A post by $author, sent for review (it sits at `pending`). */
function dashSubmitted(int $categoryId, User $author, string $title, string $slug): Entry
{
    $entry = dashPost($categoryId, $author, $title, $slug);
    app(ApplyEntryWorkflowTransition::class)->handle($entry, 'vi', $author->fresh() ?? $author, 'submit');

    return $entry->fresh() ?? $entry;
}

function dashLead(string $name, string $status = 'new'): FormSubmission
{
    $form = Form::query()->firstOrCreate(['key' => 'contact'], ['name' => ['vi' => 'Liên hệ'], 'status' => 'active']);

    return FormSubmission::query()->create(['form_id' => $form->id, 'data' => ['message' => 'x'], 'name' => $name, 'status' => $status]);
}

it('shows a reviewer the posts waiting for them — and nothing from another website', function (): void {
    $author = dashMember(['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit'], 'Người viết');
    $reviewer = dashMember(['entries.view', 'entries.review', 'entries.approve'], 'Người duyệt');

    app(Tenancy::class)->enter(2, function (): void {
        $other = User::factory()->create();
        grantRole($other, 'author', ['entries.edit.own', 'entries.submit']);
        dashSubmitted(dashCollection(), $other->fresh(), 'Bài site khác', 'bai-site-khac');
    });

    app(Tenancy::class)->enter(1, function () use ($author): void {
        dashSubmitted(dashCollection(), $author, 'Bài chờ duyệt', 'bai-cho-duyet');
    });

    $this->actingAs($reviewer)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'))
        ->assertSee(__('dash.rev_title'))
        ->assertSee('Bài chờ duyệt')
        ->assertSee(__('dash.todo_review', ['n' => 1]))
        ->assertSee(__('dash.todo_review_sub', ['title' => 'Bài chờ duyệt']))
        ->assertDontSee('Bài site khác')
        // a reviewer who does not write gets no "Bài của tôi"
        ->assertDontSee(__('dash.wr_title'));
});

it('hides the review card when no approval flow is switched on', function (): void {
    $reviewer = dashMember(['entries.view', 'entries.review', 'entries.approve']);
    app(Tenancy::class)->enter(1, fn () => dashCollection(approvalFlow: false));

    $this->actingAs($reviewer)->get('http://a.test/admin')
        ->assertOk()
        ->assertDontSee(__('dash.rev_title'))
        // approving is not writing: the member sees what is published
        ->assertSee(__('dash.rec_title_view'));
});

it('gives a writer their own posts by status and the ones sent back, with the reason', function (): void {
    $writer = dashMember(['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit'], 'Người viết');
    $reviewer = dashMember(['entries.view', 'entries.review', 'entries.approve'], 'Chị Duyệt');
    $someoneElse = dashMember(['entries.view', 'entries.create', 'entries.edit.own'], 'Người khác');

    app(Tenancy::class)->enter(1, function () use ($writer, $reviewer, $someoneElse): void {
        $category = dashCollection();
        dashPost($category, $writer, 'Nháp của tôi', 'nhap-cua-toi');
        dashPost($category, $someoneElse, 'Nháp người khác', 'nhap-nguoi-khac');
        $returned = dashSubmitted($category, $writer, 'Bài bị trả', 'bai-bi-tra');
        app(ApplyEntryWorkflowTransition::class)->handle($returned, 'vi', $reviewer, 'reject', 'Thiếu ảnh minh hoạ');
    });

    $html = $this->actingAs($writer)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('dash.wr_title'))
        ->assertSee(__('dash.wr_returned_title'))
        ->assertSee('Bài bị trả')
        ->assertSee(__('dash.wr_reason', ['reason' => 'Thiếu ảnh minh hoạ']))
        ->assertSee('Chị Duyệt')
        ->assertSee(__('dash.todo_returned', ['n' => 1]))
        ->getContent();

    // one draft of mine that was not sent back (the returned one counts under "Bị trả lại")
    expect($html)->toContain(__('dash.wr_tile_aria', ['label' => __('entries.status_draft'), 'n' => 1]))
        ->and($html)->toContain(__('dash.wr_tile_aria', ['label' => __('dash.wr_returned'), 'n' => 1]))
        // links go by the member's code, never a raw id
        ->and($html)->toContain('author%5B0%5D='.$writer->ulid);
});

it('shows a new writer the empty state and the way to the list', function (): void {
    $writer = dashMember(['entries.view', 'entries.create', 'entries.edit.own']);
    app(Tenancy::class)->enter(1, fn () => dashCollection(approvalFlow: false));

    $this->actingAs($writer)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('dash.wr_empty'))
        ->assertSee(__('dash.rec_empty_create'));
});

it('shows a read-only member only what is published, with no edit buttons', function (): void {
    $viewer = dashMember(['entries.view'], 'Người xem');
    $author = dashMember(['entries.view', 'entries.create', 'entries.edit.own']);

    app(Tenancy::class)->enter(1, function () use ($author): void {
        $category = dashCollection(approvalFlow: false);
        dashPost($category, $author, 'Bài đã đăng', 'bai-da-dang', 'published');
        dashPost($category, $author, 'Bài còn nháp', 'bai-con-nhap');
    });

    $this->actingAs($viewer)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('dash.rec_title_view'))
        ->assertSee('Bài đã đăng')
        ->assertDontSee('Bài còn nháp')
        ->assertSee(__('dash.view_aria', ['title' => 'Bài đã đăng']))
        ->assertDontSee(__('dash.edit_aria', ['title' => 'Bài đã đăng']))
        ->assertDontSee(__('dash.quick_title'))
        ->assertDontSee(__('dash.start_title'));
});

it('lists the unread leads for a member who may read them, never another website’s', function (): void {
    $manager = dashMember(['forms.view', 'forms.manage']);
    app(Tenancy::class)->enter(2, fn () => dashLead('Khách site B'));
    app(Tenancy::class)->enter(1, function (): void {
        dashLead('Khách đã đọc', 'read');
        dashLead('Nguyễn Văn An');
    });

    $this->actingAs($manager)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('dash.todo_leads', ['n' => 1]))
        ->assertSee('Nguyễn Văn An')
        ->assertDontSee('Khách site B')
        ->assertDontSee('Khách đã đọc');
});

it('keeps the leads out for a member without forms.view or without the contacts module', function (): void {
    $member = dashMember(['entries.view']);
    $manager = dashMember(['forms.view']);
    app(Tenancy::class)->enter(1, fn () => dashLead('Nguyễn Văn An'));

    $this->actingAs($member)->get('http://a.test/admin')->assertOk()->assertDontSee('Nguyễn Văn An');

    DB::connection('pgsql_admin')->table('site_modules')->where('site_id', 1)->delete();
    app()->forgetInstance(Entitlement::class);
    $this->actingAs($manager)->get('http://a.test/admin')->assertOk()->assertDontSee('Nguyễn Văn An');
});

it('counts a long queue only up to 99, then reads "99+"', function (): void {
    $manager = dashMember(['forms.view']);
    app(Tenancy::class)->enter(1, function (): void {
        $form = Form::query()->create(['key' => 'contact', 'name' => ['vi' => 'Liên hệ'], 'status' => 'active']);
        $rows = [];
        foreach (range(1, 101) as $i) {
            $rows[] = ['form_id' => $form->id, 'data' => ['message' => 'x'], 'name' => "Khách {$i}", 'status' => 'new'];
        }
        foreach ($rows as $row) {
            FormSubmission::query()->create($row);
        }
    });

    $this->actingAs($manager)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('dash.todo_leads', ['n' => '99+']));
});

it('puts the first-steps list away for this member only, and brings it back', function (): void {
    $admin = dashMember(['settings.manage', 'entries.view']);
    $other = dashMember(['settings.manage', 'entries.view']);

    app(Tenancy::class)->enter(1, function () use ($admin): void {
        Livewire::actingAs($admin)->test(Dashboard::class)
            ->assertSee(__('dash.start_title'))
            ->assertSee(__('dash.step_general'))
            ->call('hideStart')
            ->assertDontSee(__('dash.start_hide'))
            ->assertSee(__('dash.start_hidden_toast'))
            ->assertSee(__('dash.start_show'))
            ->call('showStart')
            ->assertSee(__('dash.start_hide'));

        Livewire::actingAs($admin->fresh())->test(Dashboard::class)
            ->call('hideStart');
    });

    expect(data_get($admin->fresh()->preferences, 'dashboard.start_hidden.SITEA000000000000000000001'))->toBeTrue()
        ->and(data_get($other->fresh()->preferences, 'dashboard.start_hidden'))->toBeNull();

    $this->actingAs($other)->get('http://a.test/admin')->assertOk()->assertSee(__('dash.start_hide'));
});

it('refuses to put the list away for a member who does not run the settings', function (): void {
    $member = dashMember(['entries.view']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member)->test(Dashboard::class)
            ->assertDontSee(__('dash.start_title'))
            ->call('hideStart')
            ->assertForbidden();
    });
});

it('retries a known card and refuses an unknown one', function (): void {
    $member = dashMember(['entries.view']);

    app(Tenancy::class)->enter(1, function () use ($member): void {
        Livewire::actingAs($member)->test(Dashboard::class)->call('retry', 'recent')->assertOk();
        Livewire::actingAs($member)->test(Dashboard::class)->call('retry', 'nope')->assertNotFound();
    });
});

it('offers only the shortcuts the member can use', function (): void {
    $member = dashMember(['entries.view', 'entries.create']);

    $this->actingAs($member)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('dash.quick_page'))
        ->assertDontSee(__('dash.quick_invite'))
        ->assertDontSee(__('dash.quick_menus'))
        ->assertDontSee(__('dash.quick_product'));
});

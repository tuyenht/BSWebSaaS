<?php

use App\Modules\Admin\Livewire\WorkflowSettings;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\Plan;
use App\Modules\Tenancy\Models\PlanFeature;
use App\Modules\Tenancy\Models\SiteFeature;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The approval-workflow screen (design 18 "Quy trình duyệt"). Operator-only: the gate is
 * platform.workflows.manage, which no tenant role template grants, so only platform operators reach
 * it (Gate::before). The page stages picks and switches and sends them together to save(): the batch
 * is validated whole and applied in one transaction. Per section, no template takes the workflow out
 * of service (its stages go, in-flight posts are released, the trail stays), a different template
 * rebuilds it and sends in-flight posts back to its first stage, the same template only switches it.
 * The editorial template is entitlement-locked (workflow.editorial, default false).
 */

beforeEach(function (): void {
    // `plans` sits on the far side of tenants.plan_id, so seedTenancy's truncate-cascade from
    // sites/tenants never reaches it and rows would survive into the next run. Clear it first,
    // then let seedTenancy rebuild the tenant it cascades away.
    DB::connection('pgsql_admin')->statement('truncate plans restart identity cascade');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A platform operator (super_admin on the platform team). */
function wfOperator(): User
{
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    return $operator;
}

/** A governable post collection: root category + the ContentSection bound to it. */
function wfSection(string $key, string $label, string $slug): ContentSection
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $label, 'slug' => $slug]);

    return ContentSection::query()->create([
        'key' => $key,
        'label' => $label,
        'entry_type' => 'post',
        'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries',
        'sort_order' => 1,
    ]);
}

/** A post filed under a category (the section's root by default), optionally mid-pipeline. */
function wfPost(ContentSection $section, string $status = 'draft', ?string $state = null, ?int $author = null, ?int $category = null): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'workflow_state' => $state, 'author_id' => $author]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài '.$entry->id, 'slug' => 'bai-'.$entry->id, 'status' => $status, 'body' => ['blocks' => []]]);
    $entry->assignCategories([$category ?? (int) $section->taxonomy_id]);

    return $entry;
}

/** A sub-category under the section's own category. */
function wfChildCategory(ContentSection $section, string $slug): Taxonomy
{
    $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $section->taxonomy_id, 'position' => 1]);
    $child->translations()->create(['locale' => 'vi', 'name' => 'Mục con '.$slug, 'slug' => $slug]);

    return $child;
}

/** @return array{key: string, template: string|null, active: bool} */
function wfChange(string $key, ?string $template, bool $active = true): array
{
    return ['key' => $key, 'template' => $template, 'active' => $active];
}

it('opens for a platform operator and stays closed to every tenant role, on mount and on save', function (): void {
    $operator = wfOperator();
    $owner = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        grantRole($owner, 'site-owner', ['settings.manage', 'entries.publish']);
        wfSection('posts', 'Tin tức', 'tin-tuc');
    });

    $this->actingAs($operator->fresh())->get('http://a.test/admin/settings/workflows')->assertOk();
    $this->actingAs($owner->fresh())->get('http://a.test/admin/settings/workflows')->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($operator, $owner): void {
        // The livewire/update endpoint bypasses route middleware — boot() closes it on mount...
        Livewire::actingAs($owner->fresh())->test(WorkflowSettings::class)->assertForbidden();

        // ...and on every later request: a page opened by an operator, saved by someone else.
        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $this->actingAs($owner->fresh());
        $page->call('save', [wfChange('posts', 'admin_approval')])->assertForbidden();

        expect(Workflow::query()->count())->toBe(0);
    });
});

it('saves every staged section in one call, switched on or left off', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');
        wfSection('jobs', 'Tuyển dụng', 'tuyen-dung');

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('save', [wfChange('posts', 'admin_approval'), wfChange('jobs', 'admin_approval', false)])
            ->assertHasNoErrors()
            ->assertReturned(true)
            ->assertSet('toast', __('admin.wf.saved'));

        expect(Workflow::query()->orderBy('applies_to_type')->get(['applies_to_type', 'key', 'template', 'is_active'])->toArray())->toBe([
            ['applies_to_type' => 'jobs', 'key' => 'jobs', 'template' => 'admin_approval', 'is_active' => false],
            ['applies_to_type' => 'posts', 'key' => 'posts', 'template' => 'admin_approval', 'is_active' => true],
        ]);
        $posts = Workflow::query()->where('applies_to_type', 'posts')->firstOrFail();
        expect($posts->states()->count())->toBe(4)
            ->and($posts->transitions()->count())->toBe(5);
    });
});

it('saves nothing when one entry of the batch is invalid', function (array $bad): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator, $bad): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');
        wfSection('jobs', 'Tuyển dụng', 'tuyen-dung');

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('save', [wfChange('posts', 'admin_approval'), $bad])
            ->assertReturned(false)
            ->assertHasErrors('changes')
            ->assertSet('toast', '');

        expect(Workflow::query()->count())->toBe(0);
    });
})->with([
    'unknown section' => [['key' => 'khong-ton-tai', 'template' => 'admin_approval', 'active' => true]],
    'unknown template' => [['key' => 'jobs', 'template' => 'khong-co-template', 'active' => true]],
    'the same section twice' => [['key' => 'posts', 'template' => null, 'active' => false]],
    'a missing field' => [['key' => 'jobs', 'active' => true]],
    'an extra field' => [['key' => 'jobs', 'template' => 'admin_approval', 'active' => true, 'site_id' => 2]],
    'a non-boolean switch' => [['key' => 'jobs', 'template' => 'admin_approval', 'active' => 'maybe']],
]);

it('refuses the editorial flow without its entitlement even when sent directly, and opens it when granted', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');
        wfSection('jobs', 'Tuyển dụng', 'tuyen-dung');

        // Default entitlement is false → refused with a plain message; the valid entry beside it
        // is not saved either.
        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('save', [wfChange('jobs', 'admin_approval'), wfChange('posts', 'editorial')])
            ->assertReturned(false)
            ->assertHasErrors(['changes' => __('admin.wf.locked_error')]);
        expect(Workflow::query()->count())->toBe(0);

        // A per-site feature grant opens it.
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('save', [wfChange('posts', 'editorial')])
            ->assertHasNoErrors()
            ->assertReturned(true);
        expect(Workflow::query()->where('template', 'editorial')->value('is_active'))->toBeTrue();
    });
});

it('takes a section back to publishing directly: the stages go, waiting posts are released, the trail stays', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        $news = wfSection('posts', 'Tin tức', 'tin-tuc');
        $jobs = wfSection('jobs', 'Tuyển dụng', 'tuyen-dung');
        $child = wfChildCategory($news, 'tin-noi-bo');

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $page->call('save', [wfChange('posts', 'admin_approval'), wfChange('jobs', 'admin_approval')])->assertReturned(true);

        $workflow = Workflow::query()->where('applies_to_type', 'posts')->firstOrFail();
        $atRoot = wfPost($news, 'pending', 'pending');
        $inChild = wfPost($news, 'pending', 'pending', category: $child->id);
        $elsewhere = wfPost($jobs, 'pending', 'pending');
        WorkflowLog::query()->create([
            'workflow_id' => $workflow->id, 'entry_id' => $atRoot->id, 'locale' => 'vi',
            'transition_key' => 'submit', 'from_state' => 'draft', 'to_state' => 'pending',
        ]);

        $page->call('save', [wfChange('posts', null, false)])->assertHasNoErrors()->assertReturned(true);

        $workflow->refresh();
        expect($workflow->template)->toBe('none')
            ->and($workflow->is_active)->toBeFalse()
            ->and($workflow->states()->count())->toBe(0)
            ->and($workflow->transitions()->count())->toBe(0)
            ->and($atRoot->refresh()->workflow_state)->toBeNull()
            ->and($inChild->refresh()->workflow_state)->toBeNull()
            ->and($elsewhere->refresh()->workflow_state)->toBe('pending')
            ->and(WorkflowLog::query()->where('entry_id', $atRoot->id)->count())->toBe(1);

        // The page shows it as having no flow at all.
        $row = collect($page->viewData('rows'))->firstWhere('key', 'posts');
        expect($row['template'])->toBeNull()
            ->and($row['active'])->toBeFalse();

        // And a flow can be picked again later.
        $page->call('save', [wfChange('posts', 'admin_approval')])->assertReturned(true);
        expect($workflow->refresh()->template)->toBe('admin_approval')
            ->and($workflow->is_active)->toBeTrue()
            ->and($workflow->states()->count())->toBe(4);
    });
});

it('sends waiting posts back to the first stage when the flow is swapped, sub-categories included', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        $news = wfSection('posts', 'Tin tức', 'tin-tuc');
        $services = wfSection('services', 'Dịch vụ', 'dich-vu');
        $child = wfChildCategory($news, 'tin-noi-bo');
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $page->call('save', [wfChange('posts', 'editorial'), wfChange('services', 'editorial')])->assertReturned(true);

        // admin_approval defines no `reviewing`: a post left holding it would be invisible to every
        // review inbox and stuck for its author.
        $atRoot = wfPost($news, 'pending', 'reviewing');
        $inChild = wfPost($news, 'pending', 'reviewing', category: $child->id);
        $elsewhere = wfPost($services, 'pending', 'reviewing');

        $page->call('save', [wfChange('posts', 'admin_approval')])->assertReturned(true);

        expect($atRoot->refresh()->workflow_state)->toBeNull()
            ->and($atRoot->status)->toBe('pending')
            ->and($inChild->refresh()->workflow_state)->toBeNull()
            ->and($elsewhere->refresh()->workflow_state)->toBe('reviewing');
    });
});

it('switches a flow on and off without rebuilding it', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        $news = wfSection('posts', 'Tin tức', 'tin-tuc');

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $page->call('save', [wfChange('posts', 'admin_approval')])->assertReturned(true);
        $workflow = Workflow::query()->where('applies_to_type', 'posts')->firstOrFail();
        $stateIds = $workflow->states()->orderBy('id')->pluck('id')->all();
        $waiting = wfPost($news, 'pending', 'pending');

        $page->call('save', [wfChange('posts', 'admin_approval', false)])->assertReturned(true);
        expect($workflow->refresh()->is_active)->toBeFalse();

        $page->call('save', [wfChange('posts', 'admin_approval')])->assertReturned(true);
        expect($workflow->refresh()->is_active)->toBeTrue()
            ->and($workflow->states()->orderBy('id')->pluck('id')->all())->toBe($stateIds)
            ->and($waiting->refresh()->workflow_state)->toBe('pending');
    });
});

it('counts posts, writers and waiting posts of every section in one query', function (): void {
    $operator = wfOperator();
    $writers = User::factory()->count(2)->create();

    app(Tenancy::class)->enter(1, function () use ($operator, $writers): void {
        $news = wfSection('posts', 'Tin tức', 'tin-tuc');
        $child = wfChildCategory($news, 'tin-noi-bo');
        wfSection('jobs', 'Tuyển dụng', 'tuyen-dung');

        wfPost($news, 'published', null, $writers[0]->id);
        wfPost($news, 'pending', 'pending', $writers[0]->id);
        wfPost($news, 'pending', 'pending', $writers[1]->id);
        // filed under a sub-category: the counts follow the section's root category only
        wfPost($news, 'draft', null, $writers[1]->id, $child->id);

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $rows = collect($page->viewData('rows'))->keyBy('key');

        expect($rows['posts'])->toMatchArray(['total' => 3, 'writers' => 2, 'pending' => 2])
            ->and($rows['jobs'])->toMatchArray(['total' => 0, 'writers' => 0, 'pending' => 0]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $tallies = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'entry_taxonomies'))->values();
        DB::disableQueryLog();

        expect($tallies)->toHaveCount(1)
            ->and($tallies[0])->toContain('count(distinct');
    });
});

it('keeps the page at a flat query count however many sections the site has', function (): void {
    $operator = wfOperator();

    $measure = function () use ($operator): int {
        return app(Tenancy::class)->enter(1, function () use ($operator): int {
            $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);

            return countQueries(fn () => $page->call('$refresh'));
        });
    };

    app(Tenancy::class)->enter(1, fn () => wfSection('posts', 'Tin tức', 'tin-tuc'));
    $one = $measure();

    app(Tenancy::class)->enter(1, function (): void {
        foreach (['jobs' => 'Tuyển dụng', 'services' => 'Dịch vụ', 'projects' => 'Dự án'] as $key => $label) {
            wfSection($key, $label, $key);
        }
    });
    $four = $measure();

    expect($four)->toBe($one);
});

it('shows the site, its plan and how many sections have a flow', function (): void {
    $operator = wfOperator();
    $pro = Plan::query()->create(['key' => 'pro', 'name' => 'Chuyên nghiệp', 'is_active' => true, 'sort_order' => 2]);
    DB::table('tenants')->where('id', 1)->update(['plan_id' => $pro->id]);
    DB::table('sites')->where('id', 1)->update(['name' => 'Cơ khí An Vinh']);

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');
        wfSection('jobs', 'Tuyển dụng', 'tuyen-dung');

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $page->call('save', [wfChange('posts', 'admin_approval'), wfChange('jobs', 'admin_approval', false)]);

        $page->assertSee('Cơ khí An Vinh')
            ->assertSee(__('admin.wf.plan_line', ['plan' => 'Chuyên nghiệp']))
            ->assertSee(__('admin.wf.summary', ['on' => 1, 'total' => 2]))
            ->assertSee(__('admin.wf.save'))
            ->assertSee('role="switch"', false);
    });
});

it('points a locked flow at the plan that opens it, from real plan rows', function (): void {
    $operator = wfOperator();

    $starter = Plan::query()->create(['key' => 'starter', 'name' => 'Khởi đầu', 'is_active' => true, 'sort_order' => 1]);
    $pro = Plan::query()->create(['key' => 'pro', 'name' => 'Chuyên nghiệp', 'is_active' => true, 'sort_order' => 2]);
    PlanFeature::query()->create(['plan_id' => $pro->id, 'feature_key' => 'workflow.editorial', 'value' => true]);
    // A plan that lists the feature as NOT granted must not be advertised as the way out.
    PlanFeature::query()->create(['plan_id' => $starter->id, 'feature_key' => 'workflow.editorial', 'value' => false]);
    DB::table('tenants')->where('id', 1)->update(['plan_id' => $starter->id]);

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class)
            ->assertViewHas('editorialLocked', true)
            ->assertViewHas('upgradeUrl', route('platform.tenants.show', DB::table('tenants')->where('id', 1)->value('ulid')))
            ->assertSee(__('admin.wf.up_current', ['plan' => 'Khởi đầu']))
            ->assertSee(__('admin.wf.up_go', ['plan' => 'Chuyên nghiệp']));

        $editorial = collect($page->viewData('templates'))->firstWhere('key', 'editorial');
        expect($editorial)->toMatchArray(['locked' => true, 'need' => 'Chuyên nghiệp']);
    });
});

it('offers no plan page when no plan opens the flow, and none once the site has it', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        // No plan catalog at all → nothing to point at; the dialog says so instead.
        Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class)
            ->assertViewHas('upgradeUrl', null)
            ->assertSee(__('admin.wf.up_current_none'))
            ->assertSee(__('admin.wf.up_need_none'));

        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class)
            ->assertViewHas('editorialLocked', false)
            ->assertViewHas('upgradeUrl', null);
        expect(collect($page->viewData('templates'))->firstWhere('key', 'editorial'))->toMatchArray(['locked' => false, 'need' => null]);
    });
});

it('only notes a staged pick, writing nothing', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        $name = wfSection('posts', 'Tin tức', 'tin-tuc')->localizedLabel();

        $page = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $page->call('staged', 'posts', 'admin_approval')
            ->assertSet('toast', __('admin.wf.staged_pick', ['name' => $name, 'wf' => __('workflow.template_admin_approval')]));
        $page->call('staged', 'posts')
            ->assertSet('toast', __('admin.wf.staged_none', ['name' => $name]));
        $page->call('staged', 'khong-ton-tai', 'admin_approval')
            ->assertSet('toast', __('admin.wf.staged_none', ['name' => $name]));

        $seq = $page->get('toastSeq');
        $page->call('dismissToast', $seq)->assertSet('toast', '');

        expect(Workflow::query()->count())->toBe(0);
    });
});

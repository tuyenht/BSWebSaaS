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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The approval-workflow switchboard (design_handoff_workflow — screen 1). Operator-only: the
 * gate is platform.workflows.manage, which no tenant role template grants, so a site owner or
 * admin never reaches it — only platform operators via Gate::before. Applying a template
 * materializes it through InstantiateWorkflow; the editorial template is entitlement-locked
 * (workflow.editorial, default false); switching templates re-enters in-flight entries at the
 * new flow's first stage so none are stranded on a stage the rebuilt flow no longer defines.
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

/** A post filed under the section's category, optionally mid-pipeline. */
function wfPost(ContentSection $section, string $status = 'draft', ?string $state = null): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'workflow_state' => $state]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Bài '.$entry->id, 'slug' => 'bai-'.$entry->id, 'status' => $status, 'body' => ['blocks' => []]]);
    $entry->assignCategories([(int) $section->taxonomy_id]);

    return $entry;
}

it('opens for a platform operator and stays closed to every tenant role', function (): void {
    $operator = wfOperator();
    $owner = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        grantRole($owner, 'site-owner', ['settings.manage', 'entries.publish']);
        wfSection('posts', 'Tin tức', 'tin-tuc');
    });

    $this->actingAs($operator->fresh())->get('http://a.test/admin/settings/workflows')->assertOk();
    $this->actingAs($owner->fresh())->get('http://a.test/admin/settings/workflows')->assertForbidden();

    // The livewire/update endpoint bypasses route middleware — boot() must close it too.
    app(Tenancy::class)->enter(1, function () use ($owner): void {
        Livewire::actingAs($owner->fresh())->test(WorkflowSettings::class)->assertForbidden();
    });
});

it('applies a template — staged off by default, or switched on immediately', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('apply', 'posts', 'admin_approval')
            ->assertHasNoErrors();

        $workflow = Workflow::query()->where('applies_to_type', 'posts')->firstOrFail();
        expect($workflow->is_active)->toBeFalse()
            ->and($workflow->states()->count())->toBe(4)
            ->and($workflow->transitions()->count())->toBe(5);

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('apply', 'posts', 'admin_approval', true);

        expect($workflow->refresh()->is_active)->toBeTrue();
    });
});

it('locks the editorial template behind its entitlement and opens it when granted', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        // Default entitlement is false → refused with a human error, nothing created.
        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('apply', 'posts', 'editorial')
            ->assertHasErrors('template');
        expect(Workflow::query()->count())->toBe(0);

        // A per-site feature grant opens it.
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('apply', 'posts', 'editorial')
            ->assertHasNoErrors();
        expect(Workflow::query()->where('template', 'editorial')->exists())->toBeTrue();
    });
});

it('explains the plan gap from real plan rows, and says so when there is no plan at all', function (): void {
    $operator = wfOperator();

    $starter = Plan::query()->create(['key' => 'starter', 'name' => 'Gói Khởi đầu', 'is_active' => true, 'sort_order' => 1]);
    $pro = Plan::query()->create(['key' => 'pro', 'name' => 'Gói Chuyên nghiệp', 'is_active' => true, 'sort_order' => 2]);
    PlanFeature::query()->create(['plan_id' => $pro->id, 'feature_key' => 'workflow.editorial', 'value' => true]);
    // A plan that lists the feature as NOT granted must not be advertised as the way out.
    PlanFeature::query()->create(['plan_id' => $starter->id, 'feature_key' => 'workflow.editorial', 'value' => false]);

    app(Tenancy::class)->enter(1, function () use ($operator, $starter): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        // No plan on the tenant yet → the panel says that rather than inventing a tier.
        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->assertViewHas('upgrade', ['current' => null, 'plans' => ['Gói Chuyên nghiệp']]);

        DB::table('tenants')->where('id', 1)->update(['plan_id' => $starter->id]);

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->assertViewHas('upgrade', ['current' => 'Gói Khởi đầu', 'plans' => ['Gói Chuyên nghiệp']]);
    });
});

it('drops the upgrade lookup once the feature is granted', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->assertViewHas('editorialLocked', false)
            ->assertViewHas('upgrade', null);
    });
});

it('toggles an existing workflow on and off', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        $component = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $component->call('apply', 'posts', 'admin_approval', true);

        $component->call('toggle', 'posts');
        expect(Workflow::query()->where('applies_to_type', 'posts')->value('is_active'))->toBeFalse();

        $component->call('toggle', 'posts');
        expect(Workflow::query()->where('applies_to_type', 'posts')->value('is_active'))->toBeTrue();
    });
});

it('re-enters in-flight entries at the first stage when the template is swapped', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        $news = wfSection('posts', 'Tin tức', 'tin-tuc');
        $services = wfSection('services', 'Dịch vụ', 'dich-vu');

        $component = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $component->call('apply', 'posts', 'admin_approval', true);

        $inFlight = wfPost($news, 'pending', 'pending');
        $elsewhere = wfPost($services, 'pending', 'pending');

        // Re-applying the SAME template is a rebuild, not a swap — pointers stay put.
        $component->call('apply', 'posts', 'admin_approval', true);
        expect($inFlight->refresh()->workflow_state)->toBe('pending');

        // Swapping to a different flow clears only this section's pointers; the public
        // status is untouched and other sections' entries are not.
        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);
        $component->call('apply', 'posts', 'editorial', true);

        expect($inFlight->refresh()->workflow_state)->toBeNull()
            ->and($inFlight->status)->toBe('pending')
            ->and($elsewhere->refresh()->workflow_state)->toBe('pending');
    });
});

it('rejects an unknown section or template', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        wfSection('posts', 'Tin tức', 'tin-tuc');

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('apply', 'khong-ton-tai', 'admin_approval')
            ->assertStatus(404);

        Livewire::actingAs($operator->fresh())
            ->test(WorkflowSettings::class)
            ->call('apply', 'posts', 'khong-co-template')
            ->assertStatus(404);
    });
});

it('re-enters posts filed under a SUB-category too, not just the section root', function (): void {
    $operator = wfOperator();

    app(Tenancy::class)->enter(1, function () use ($operator): void {
        $news = wfSection('posts', 'Tin tức', 'tin-tuc');

        // A sub-category of the section's own category: SectionResolver walks ancestors, so a
        // post filed here belongs to this section just as much as one filed at the root.
        $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $news->taxonomy_id, 'position' => 1]);
        $child->translations()->create(['locale' => 'vi', 'name' => 'Tin nội bộ', 'slug' => 'tin-noi-bo']);

        SiteFeature::query()->create(['feature_key' => 'workflow.editorial', 'value' => true]);

        $component = Livewire::actingAs($operator->fresh())->test(WorkflowSettings::class);
        $component->call('apply', 'posts', 'editorial', true);

        $atRoot = wfPost($news, 'pending', 'reviewing');
        $inChild = Entry::query()->create(['type' => 'post', 'status' => 'pending', 'workflow_state' => 'reviewing']);
        $inChild->translations()->create(['locale' => 'vi', 'title' => 'Bài con', 'slug' => 'bai-con', 'status' => 'pending', 'body' => ['blocks' => []]]);
        $inChild->assignCategories([$child->id]);

        // admin_approval defines no `reviewing`: a post left holding it is invisible to every
        // review inbox and stuck for its author — which is what happened to sub-category posts.
        $component->call('apply', 'posts', 'admin_approval', true);

        expect($atRoot->refresh()->workflow_state)->toBeNull()
            ->and($inChild->refresh()->workflow_state)->toBeNull();
    });
});

<?php

use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;

/*
 * Scheduling is the one remaining road to `published` that skips the approval gate: a scheduled
 * entry is published by `entries:publish-due` with no actor and no engine. Where a workflow is
 * active, both ends are closed — a governed entry cannot be scheduled, and an entry that was
 * scheduled BEFORE the workflow was switched on is left alone by the scheduler rather than
 * silently pushed live. Tier-1 sites (no active workflow) keep scheduling exactly as before.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A `tin-tuc` post section + one draft post filed under it; returns the entry. */
function schedulableNewsPost(): Entry
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    ContentSection::query()->firstOrCreate(
        ['key' => 'tin-tuc'],
        ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id],
    );

    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []],
    ]);
    $entry->assignCategories([$category->id]);

    return $entry->fresh();
}

it('refuses to schedule a post an active workflow governs', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.publish']);
        $entry = schedulableNewsPost();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);

        expect(fn () => app(TransitionEntryStatus::class)->handle($entry, EntryStatus::Scheduled, $editor->fresh(), now()->addDay()))
            ->toThrow(WorkflowGovernedException::class);

        expect($entry->fresh()->status)->toBe('draft');
    });
});

it('still schedules a post whose section workflow exists but is switched off', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.publish']);
        $entry = schedulableNewsPost();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: false);

        app(TransitionEntryStatus::class)->handle($entry, EntryStatus::Scheduled, $editor->fresh(), now()->addDay());

        expect($entry->fresh()->status)->toBe('scheduled');
    });
});

it('stamps today as the publication date when approving a post whose old slot is still in the future', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.submit', 'entries.approve', 'entries.publish']);

        // Scheduled for next week while tier-1 was in force…
        $entry = schedulableNewsPost();
        $slot = now()->addWeek();
        app(TransitionEntryStatus::class)->handle($entry, EntryStatus::Scheduled, $editor->fresh(), $slot);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);

        // …then approved today. Post lists order by published_at, so keeping next week's date
        // would park this post above everything published since.
        $move = app(ApplyEntryWorkflowTransition::class);
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'submit');
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'approve');

        $entry->refresh();
        expect($entry->status)->toBe('published')
            ->and($entry->published_at->isFuture())->toBeFalse()
            ->and($entry->published_at->diffInMinutes(now()))->toBeLessThan(2);
    });
});

it('keeps the original publication date when live content is approved again after a retraction', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.submit', 'entries.approve', 'entries.review', 'entries.publish']);
        $entry = schedulableNewsPost();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);
        $move = app(ApplyEntryWorkflowTransition::class);

        $move->handle($entry, 'vi', $editor->fresh(), 'submit');
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'approve');
        $firstPublished = $entry->fresh()->published_at;

        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'retract', 'Sửa số liệu');
        $this->travel(2)->hours();
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'submit');
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'approve');

        // The article's date is its original one — a correction is not a new article.
        expect($entry->fresh()->published_at->equalTo($firstPublished))->toBeTrue();
    });
});

it('sends a governed post back to the flow start when the status menu takes it off the site', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.submit', 'entries.approve', 'entries.publish']);
        $entry = schedulableNewsPost();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);
        $move = app(ApplyEntryWorkflowTransition::class);
        $move->handle($entry, 'vi', $editor->fresh(), 'submit');
        $move->handle($entry->fresh(), 'vi', $editor->fresh(), 'approve');
        expect($entry->fresh()->workflow_state)->toBe('published');

        // "Chuyển nháp" from the row menu is allowed (it only hides), but the stage must not stay
        // "published" over a hidden post: it re-derives from the new status = the start.
        app(TransitionEntryStatus::class)->handle($entry->fresh(), EntryStatus::Draft, $editor->fresh());
        $entry->refresh();
        expect($entry->status)->toBe('draft')
            ->and($entry->workflow_state)->toBeNull();

        // The only road back to live is approval again — a plain "submit" is the move on offer.
        expect($move->handle($entry, 'vi', $editor->fresh(), 'submit'))->toBe('pending');
    });
});

it('leaves a pre-workflow scheduled post alone once its section is governed, while still publishing the rest', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.publish']);

        // Scheduled while tier-1 was in force…
        $post = schedulableNewsPost();
        app(TransitionEntryStatus::class)->handle($post, EntryStatus::Scheduled, $editor->fresh(), now()->addMinute());

        // …and a page due at the same time, which no workflow can ever govern.
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(TransitionEntryStatus::class)->handle($page->fresh(), EntryStatus::Scheduled, $editor->fresh(), now()->addMinute());

        // Then the operator switches the approval flow on for tin-tuc.
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);
    });

    $this->travel(2)->minutes();
    $this->artisan('entries:publish-due')
        ->expectsOutputToContain('Published 1 scheduled entr(y/ies), skipped 1')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function (): void {
        // The governed post never went live behind the gate's back…
        $post = Entry::query()->where('type', 'post')->first();
        expect($post->status)->toBe('scheduled');
        expect(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();

        // …and the page published on time as always.
        $page = Entry::query()->where('type', 'page')->first();
        expect($page->status)->toBe('published');
        expect(SiteRoute::query()->where('entry_id', $page->id)->exists())->toBeTrue();
    });
});

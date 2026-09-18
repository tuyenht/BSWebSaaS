<?php

use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Exceptions\WorkflowGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Cms as the workflow engine's first consumer (design.md §15.4 tier-2). The engine decides IF a
 * move is legal; this action decides what the move MEANS for an entry — its workflow_state, its
 * public status, its route. Tier-1 straight-through publishing stays untouched wherever no
 * workflow is active, which is every site by default.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A `tin-tuc` post section + one draft post filed under it; returns the entry. */
function newsPost(): Entry
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    ContentSection::query()->firstOrCreate(
        ['key' => 'tin-tuc'],
        ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id],
    );

    // Posts route under their category, as An Vinh's do.
    SiteSetting::query()->updateOrCreate(
        ['group_name' => 'permalinks', 'key' => 'tin-tuc'],
        ['value' => ['pattern' => 'category']],
    );

    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []],
    ]);
    $entry->assignCategories([$category->id]);

    return $entry->fresh();
}

/**
 * An active editorial workflow over `tin-tuc`: draft → pending → published, with `reject` back
 * from review and `retract` back from live.
 *
 * `published` is deliberately NOT terminal: a stage with no outgoing edge can never be left, so
 * marking the live stage terminal would make published content permanent — the engine would (and
 * did) refuse every attempt to pull it down. Terminal is for a real end of the line (e.g.
 * archived), not for "currently live".
 */
function activeNewsWorkflow(): Workflow
{
    $workflow = Workflow::query()->create([
        'key' => 'editorial', 'name' => 'Quy trình biên tập',
        'applies_to_type' => 'tin-tuc', 'template' => 'editorial', 'is_active' => true,
    ]);

    foreach ([
        ['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true],
        ['key' => 'pending', 'label' => 'Chờ duyệt'],
        ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true],
    ] as $state) {
        $workflow->states()->create($state);
    }

    foreach ([
        ['key' => 'submit', 'label' => 'Gửi duyệt', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
        ['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
        ['key' => 'reject', 'label' => 'Trả lại', 'from_state' => 'pending', 'to_state' => 'draft', 'required_permission' => 'entries.review', 'requires_note' => true],
        ['key' => 'retract', 'label' => 'Gỡ bài', 'from_state' => 'published', 'to_state' => 'draft', 'required_permission' => 'entries.review', 'requires_note' => true],
    ] as $transition) {
        $workflow->transitions()->create($transition);
    }

    return $workflow;
}

it('leaves tier-1 publishing untouched when no workflow is active', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.publish', 'entries.submit']);
        $entry = newsPost();

        // The whole point of default-OFF: existing sites keep publishing exactly as before.
        expect(app(PublishEntry::class)->handle($entry, 'vi', $editor->fresh()))->toBe('published');
        expect($entry->fresh()->status)->toBe('published');
        expect(SiteRoute::query()->where('path', '/tin-tuc/ban-tin')->exists())->toBeTrue();
    });
});

it('refuses a straight-through publish once a workflow governs the content', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.publish', 'entries.approve']);
        $entry = newsPost();
        activeNewsWorkflow();

        // Even entries.publish must not skip the stages the site configured — that hole would
        // make the approval gate decorative.
        expect(fn () => app(PublishEntry::class)->handle($entry, 'vi', $editor->fresh()))
            ->toThrow(WorkflowGovernedException::class);

        expect($entry->fresh()->status)->toBe('draft');
        expect(SiteRoute::query()->count())->toBe(0);
    });
});

it('walks a post draft → pending → published, going live only at the published stage', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($editor, 'editor', ['entries.approve']);
        $entry = newsPost();
        activeNewsWorkflow();
        $action = app(ApplyEntryWorkflowTransition::class);

        // Submit: in the pipeline, not public — and no route yet.
        expect($action->handle($entry, 'vi', $author->fresh(), 'submit'))->toBe('pending');
        $entry->refresh();
        expect($entry->workflow_state)->toBe('pending');
        expect($entry->status)->toBe('pending');
        expect(SiteRoute::query()->count())->toBe(0);

        // Approve: the is_published stage is what puts it live + routes it.
        expect($action->handle($entry, 'vi', $editor->fresh(), 'approve'))->toBe('published');
        $entry->refresh();
        expect($entry->workflow_state)->toBe('published');
        expect($entry->status)->toBe('published');
        expect($entry->published_at)->not->toBeNull();
        expect($entry->translationFor('vi')->status)->toBe('published');
        expect(SiteRoute::query()->where('path', '/tin-tuc/ban-tin')->exists())->toBeTrue();
    });

    // End-to-end: the approved post actually serves.
    $this->get('http://a.test/tin-tuc/ban-tin')->assertOk();
});

it('takes live content back offline when it is retracted', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.submit', 'entries.approve', 'entries.review']);
        $entry = newsPost();
        activeNewsWorkflow();
        $action = app(ApplyEntryWorkflowTransition::class);

        $action->handle($entry, 'vi', $editor->fresh(), 'submit');
        $action->handle($entry, 'vi', $editor->fresh(), 'approve');
        expect($entry->fresh()->status)->toBe('published');

        // An approval gate that cannot pull content back is not a gate.
        $action->handle($entry->fresh(), 'vi', $editor->fresh(), 'retract', 'Sai số liệu');
        $entry->refresh();
        expect($entry->workflow_state)->toBe('draft');
        expect($entry->status)->toBe('draft');
    });

    $this->get('http://a.test/tin-tuc/ban-tin')->assertNotFound();
});

it('enforces the engine guard through the action — no permission, no move', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.submit']);   // cannot approve
        $entry = newsPost();
        activeNewsWorkflow();
        $action = app(ApplyEntryWorkflowTransition::class);

        $action->handle($entry, 'vi', $author->fresh(), 'submit');

        expect(fn () => $action->handle($entry->fresh(), 'vi', $author->fresh(), 'approve'))
            ->toThrow(AuthorizationException::class);

        // The refused move left nothing behind — status and state both stand still.
        expect($entry->fresh()->status)->toBe('pending');
        expect($entry->fresh()->workflow_state)->toBe('pending');
    });
});

it('keeps a live post that predates the workflow at the published stage — joining the flow never hides it', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($editor, 'editor', ['entries.publish', 'entries.review']);
        $entry = newsPost();
        app(PublishEntry::class)->handle($entry, 'vi', $editor->fresh());   // live under tier-1
        activeNewsWorkflow();                                               // switched on afterwards
        $action = app(ApplyEntryWorkflowTransition::class);

        expect($entry->fresh()->workflow_state)->toBeNull();

        // "Submit" is an edge out of draft; live content stands at the published stage, so the
        // engine refuses it — the visitors' page is not pulled down by an author's click.
        expect(fn () => $action->handle($entry->fresh(), 'vi', $author->fresh(), 'submit'))
            ->toThrow(InvalidTransitionException::class);
        expect($entry->fresh()->status)->toBe('published');

        // The moves that DO exist from the published stage work, with the usual guards.
        expect($action->handle($entry->fresh(), 'vi', $editor->fresh(), 'retract', 'Sai số liệu'))->toBe('draft');
        expect($entry->fresh()->status)->toBe('draft');
    });

    $this->get('http://a.test/tin-tuc/ban-tin')->assertNotFound();
});

it('starts an entry that predates the workflow at the initial stage', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.submit']);
        $entry = newsPost();          // created with workflow_state = null
        activeNewsWorkflow();         // switched on afterwards

        expect($entry->workflow_state)->toBeNull();

        // Content that existed before the gate must not be stranded outside the machine.
        expect(app(ApplyEntryWorkflowTransition::class)->handle($entry, 'vi', $author->fresh(), 'submit'))
            ->toBe('pending');
    });
});

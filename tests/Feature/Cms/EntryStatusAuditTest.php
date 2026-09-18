<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryStatusAudit;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Facades\DB;

/*
 * Every road that changes an entry's public status leaves ONE audit line, so "who took this
 * page down, and how" is answerable weeks later. The 02/08 homepage incident was not: tier-1
 * status changes left no trace, updated_at was overwritten by the restore, and the audit trail
 * only knew auth/role events.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A draft page (no taxonomy → never governed) authored by $author. */
function auditedPage(User $author, string $slug = 'gioi-thieu'): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author->id]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page->fresh();
}

/** The status-change rows for one entry, oldest first, as [from, to, via, actor_id]. */
function statusTrail(Entry $entry): array
{
    return AuditLog::query()
        ->where('action', EntryStatusAudit::ACTION)
        ->where('auditable_id', $entry->id)
        ->orderBy('id')
        ->get()
        ->map(fn (AuditLog $row): array => [$row->meta['from'], $row->meta['to'], $row->meta['via'], $row->actor_id])
        ->all();
}

it('records publish, the status menu, scheduling and the scheduler, each with its actor and road', function (): void {
    $editor = User::factory()->create();

    $page = app(Tenancy::class)->enter(1, function () use ($editor): Entry {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = auditedPage($editor);
        $actor = $editor->fresh();

        app(PublishEntry::class)->handle($page, 'vi', $actor);                                   // draft → published
        app(TransitionEntryStatus::class)->handle($page->fresh(), EntryStatus::Private, $actor);  // → private
        app(TransitionEntryStatus::class)->handle($page->fresh(), EntryStatus::Scheduled, $actor, now()->addMinute());

        return $page->fresh();
    });

    $this->travel(2)->minutes();
    $this->artisan('entries:publish-due')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($page, $editor): void {
        expect(statusTrail($page))->toBe([
            ['draft', 'published', 'publish', $editor->id],
            ['published', 'private', 'status-menu', $editor->id],
            ['private', 'scheduled', 'status-menu', $editor->id],
            ['scheduled', 'published', 'scheduler', null],   // no user behind the cron
        ]);

        $scheduled = AuditLog::query()->where('action', EntryStatusAudit::ACTION)->where('meta->to', 'scheduled')->first();
        expect($scheduled?->meta['publish_at'] ?? null)->not->toBeNull();
    });
});

it('records a submit-only author parking a page as pending', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        $page = auditedPage($author);

        app(PublishEntry::class)->handle($page, 'vi', $author->fresh());

        expect(statusTrail($page->fresh()))->toBe([['draft', 'pending', 'submit', $author->id]]);
    });
});

it('records a workflow transition alongside its workflow_log row, naming the transition', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.submit']);

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tin-tuc', active: true);

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $post->assignCategories([$category->id]);

        app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'submit');

        expect(statusTrail($post->fresh()))->toBe([['draft', 'pending', 'workflow', $author->id]]);
        expect(AuditLog::query()->where('action', EntryStatusAudit::ACTION)->value('meta')['transition'] ?? null)->toBe('submit');
    });
});

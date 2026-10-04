<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Revision history is written on every road that puts an entry live — publish, the scheduler, a
 * workflow approval — and on every save while it is live, one row per language, never twice for
 * the same content, and never for a change that rolled back (design.md §8).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * A draft page with a vi translation (and optionally en), in the current site context.
 *
 * @param  list<string>  $locales
 */
function revisionPage(string $slug, array $locales = ['vi'], string $status = 'draft', ?User $author = null): Entry
{
    $entry = Entry::query()->create([
        'type' => 'page', 'status' => $status, 'author_id' => $author?->id, 'meta' => [],
        'published_at' => $status === 'published' ? now() : null,
    ]);

    foreach ($locales as $locale) {
        $entry->translations()->create([
            'locale' => $locale, 'title' => "Trang {$locale}", 'slug' => $slug, 'status' => $status === 'published' ? 'published' : 'draft',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => "Xin chào {$locale}"]]]],
        ]);
    }

    if ($status === 'published') {
        SiteRoute::query()->create(['locale' => 'vi', 'path' => "/{$slug}", 'entry_id' => $entry->id]);
    }

    return $entry->fresh();
}

/** @return array<string, int> revisions per "locale:kind" of an entry */
function revisionTally(Entry $entry): array
{
    $tally = [];
    foreach (EntryRevision::query()->where('entry_id', $entry->id)->get() as $revision) {
        $key = $revision->locale.':'.$revision->kind->value;
        $tally[$key] = ($tally[$key] ?? 0) + 1;
    }
    ksort($tally);

    return $tally;
}

/** A news post under an ACTIVE draft → pending → published workflow. */
function revisionGovernedPost(?User $author = null): Entry
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->firstOrCreate(
        ['key' => 'tin-tuc'],
        ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id],
    );
    SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'tin-tuc'], ['value' => ['pattern' => 'category']]);

    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author?->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []]]);
    $entry->assignCategories([$category->id]);

    $workflow = Workflow::query()->create([
        'key' => 'editorial', 'name' => 'Biên tập', 'applies_to_type' => 'tin-tuc', 'template' => 'editorial', 'is_active' => true,
    ]);
    $workflow->states()->create(['key' => 'draft', 'label' => 'Nháp', 'is_initial' => true]);
    $workflow->states()->create(['key' => 'pending', 'label' => 'Chờ duyệt']);
    $workflow->states()->create(['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true]);
    $workflow->transitions()->create(['key' => 'submit', 'label' => 'Gửi', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit']);
    $workflow->transitions()->create(['key' => 'approve', 'label' => 'Duyệt', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve']);

    return $entry->fresh();
}

it('records one publish revision per language when PublishEntry puts an entry live', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = revisionPage('gioi-thieu', ['vi', 'en']);

        app(PublishEntry::class)->handle($page, 'vi', $editor->fresh());

        expect(revisionTally($page))->toBe(['en:publish' => 1, 'vi:publish' => 1]);

        $revision = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')->firstOrFail();
        expect($revision->created_by)->toBe($editor->id)
            ->and($revision->site_id)->toBe(1)
            ->and($revision->snapshot)->toMatchArray([
                'v' => RecordEntryRevision::FORMAT,
                'title' => 'Trang vi',
                'slug' => 'gioi-thieu',
                'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Xin chào vi']]]],
                'excerpt' => null,
                'og_image_id' => null,
                'template_override' => null,
                'cover_media_id' => null,
            ])
            // Status, workflow position and publication date are never part of the content.
            ->and($revision->snapshot)->not->toHaveKeys(['status', 'workflow_state', 'published_at', 'author_id', 'position']);

        // Publishing again with nothing changed writes nothing.
        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());
        expect(revisionTally($page))->toBe(['en:publish' => 1, 'vi:publish' => 1]);
    });
});

it('records nothing when a submit parks the entry as pending', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        $page = revisionPage('cho-duyet', ['vi'], 'draft', $author);

        expect(app(PublishEntry::class)->handle($page, 'vi', $author->fresh()))->toBe('pending')
            ->and(revisionTally($page))->toBe([]);
    });
});

it('leaves no revision behind when the publish rolls back', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        revisionPage('trung-duong-dan', ['vi'], 'published');
        $clash = revisionPage('trung-duong-dan');

        expect(fn () => app(PublishEntry::class)->handle($clash, 'vi', $editor->fresh()))->toThrow(RouteConflictException::class);
        expect(revisionTally($clash))->toBe([]);
    });
});

it('records a publish revision without an author when the scheduler publishes', function (): void {
    $page = null;

    app(Tenancy::class)->enter(1, function () use (&$page): void {
        $page = revisionPage('khuyen-mai', ['vi', 'en'], 'scheduled');
        $page->update(['published_at' => now()->subMinute()]);
    });

    $this->artisan('entries:publish-due')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($page): void {
        expect(revisionTally($page))->toBe(['en:publish' => 1, 'vi:publish' => 1])
            ->and(EntryRevision::query()->where('entry_id', $page->id)->whereNotNull('created_by')->exists())->toBeFalse();
    });
});

it('records a publish revision only when a workflow transition reaches the published stage', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.approve']);
        $post = revisionGovernedPost($author);
        $action = app(ApplyEntryWorkflowTransition::class);

        $action->handle($post, 'vi', $author->fresh(), 'submit');
        expect(revisionTally($post))->toBe([]);

        $action->handle($post->fresh(), 'vi', $approver->fresh(), 'approve');
        expect(revisionTally($post))->toBe(['vi:publish' => 1])
            ->and(EntryRevision::query()->where('entry_id', $post->id)->value('created_by'))->toBe($approver->id);
    });
});

it('records nothing for the status menu, which only ever takes content off the site', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $page = revisionPage('an-bai', ['vi'], 'published');

        app(TransitionEntryStatus::class)->handle($page, EntryStatus::Private, $editor->fresh());
        app(TransitionEntryStatus::class)->handle($page->fresh(), EntryStatus::Scheduled, $editor->fresh(), now()->addDay());

        expect(revisionTally($page))->toBe([]);
    });
});

it('records an edit revision for the saved language when the editor saves live content, once per change', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $page = revisionPage('lien-he', ['vi', 'en'], 'published');

        $component = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
            ->set('title', 'Liên hệ mới')
            ->call('save')
            ->assertHasNoErrors();

        expect(revisionTally($page))->toBe(['vi:edit' => 1]);
        expect(EntryRevision::query()->where('entry_id', $page->id)->value('snapshot'))->toMatchArray(['title' => 'Liên hệ mới']);

        // Saving again without a change adds nothing.
        $component->call('save')->assertHasNoErrors();
        expect(revisionTally($page))->toBe(['vi:edit' => 1]);

        // Publish from the editor on live content: the save and the publish agree, so vi is not
        // written twice; en had no revision yet, so the publish records it.
        $component->call('publish')->assertHasNoErrors();
        expect(revisionTally($page))->toBe(['en:publish' => 1, 'vi:edit' => 1]);
    });
});

it('records nothing when the editor saves a draft, and one publish revision when it is published', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        $page = revisionPage('ban-nhap');

        $component = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
            ->set('title', 'Bản nháp đã sửa')
            ->call('save')
            ->assertHasNoErrors();
        expect(revisionTally($page))->toBe([]);

        $component->call('publish')->assertHasNoErrors();
        expect(revisionTally($page))->toBe(['vi:publish' => 1]);
    });
});

it('records an edit revision when live content is renamed inline from the list', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $live = revisionPage('dich-vu', ['vi'], 'published');
        $draft = revisionPage('nhap');

        Livewire::actingAs($editor->fresh())->test(Index::class)
            ->call('saveTitle', $live->ulid, 'Dịch vụ mới')
            ->call('saveSlug', $live->ulid, 'Dịch vụ khác')
            ->call('saveTitle', $draft->ulid, 'Nháp mới');

        expect(revisionTally($live))->toBe(['vi:edit' => 2])
            ->and(revisionTally($draft))->toBe([]);
    });
});

it('rejects a kind outside the CHECK list at the database', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = revisionPage('kiem-tra');

        foreach (RevisionKind::cases() as $kind) {
            EntryRevision::query()->create(['entry_id' => $page->id, 'locale' => 'vi', 'kind' => $kind, 'snapshot' => ['v' => 1]]);
        }

        expect(fn () => DB::transaction(fn () => DB::table('entry_revisions')->insert([
            'ulid' => (string) Str::ulid(), 'site_id' => 1, 'entry_id' => $page->id, 'locale' => 'vi',
            'kind' => 'autosave', 'snapshot' => '{}', 'created_at' => now(),
        ])))->toThrow(QueryException::class);

        expect(EntryRevision::query()->where('entry_id', $page->id)->count())->toBe(count(RevisionKind::cases()));
    });
});

it('records every language when an editor save on live content changes the shared cover', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']);
        $page = revisionPage('bia', ['vi', 'en'], 'published');

        Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid])
            ->set('coverImageKey', 'a/library/bia-moi.png')
            ->call('save')
            ->assertHasNoErrors();

        expect(revisionTally($page))->toBe(['en:edit' => 1, 'vi:edit' => 1])
            ->and(EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'en')->value('snapshot'))
            ->toMatchArray(['title' => 'Trang en', 'meta' => ['image_url' => 'a/library/bia-moi.png']]);
    });
});

it('adds at most two queries to an editor save of live content', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any']);
        $draft = revisionPage('ngan-sach-nhap');
        $live = revisionPage('ngan-sach-song', ['vi'], 'published');

        // Second save of each: the first one warms the permission cache and the component.
        $measure = function (Entry $entry) use ($editor): int {
            $component = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $entry->ulid])
                ->set('title', 'Lần một')
                ->call('save');

            return countQueries(fn () => $component->set('title', 'Lần hai')->call('save')->assertHasNoErrors());
        };

        $onDraft = $measure($draft);
        $onLive = $measure($live);

        expect(revisionTally($live))->toBe(['vi:edit' => 2])
            ->and($onLive - $onDraft)->toBeGreaterThan(0)
            ->and($onLive - $onDraft)->toBeLessThanOrEqual(2);
    });
});

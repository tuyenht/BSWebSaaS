<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Actions\DiscardEntryDraft;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\EntryNotGovernedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\EntryDraftApplier;
use App\Modules\Cms\Support\EntryDraftAudit;
use App\Modules\Cms\Support\EntryDraftFolder;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
 * Pending changes to live content (design.md §8): an edit of a live entry is held in its one
 * pending change and reaches visitors only when approved through the entry's workflow, or — with
 * no workflow — when someone with the publish right applies it. Nothing here is wired to a screen
 * yet, so the actions are exercised directly: what visitors see, what the trail and the revisions
 * record, who is told, and who is refused.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** The `tin-tuc` post category + its section; returns the category. */
function changeNewsCategory(): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->firstOrCreate(
        ['key' => 'tin-tuc'],
        ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id],
    );

    return $category;
}

/**
 * A live post under $category, written by $author, in each of $locales, with the revision its
 * publication recorded.
 *
 * @param  list<string>  $locales
 */
function changeLivePost(User $author, Taxonomy $category, array $locales = ['vi']): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now(), 'author_id' => $author->id, 'meta' => []]);
    foreach ($locales as $locale) {
        $entry->translations()->create([
            'locale' => $locale, 'title' => "Bản tin {$locale}", 'slug' => "ban-tin-{$locale}", 'status' => 'published',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => "Mở đầu {$locale}"]]]],
        ]);
    }
    $entry->assignCategories([$category->id]);
    $entry = $entry->fresh();
    foreach ($entry->translations as $translation) {
        app(RouteSync::class)->sync($entry, $translation->locale, $translation->slug);
    }
    app(RecordEntryRevision::class)->forAllLocales($entry, RevisionKind::Publish, $author);

    return $entry->fresh();
}

/** An active approval workflow over `tin-tuc`: draft → pending → published, returned with a note. */
function changeWorkflow(): Workflow
{
    $workflow = Workflow::query()->create([
        'key' => 'admin_approval', 'name' => 'Duyệt bài',
        'applies_to_type' => 'tin-tuc', 'template' => 'admin_approval', 'is_active' => true,
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
    ] as $transition) {
        $workflow->transitions()->create($transition);
    }

    return $workflow;
}

/** An author (edits own, submits) and a reviewer (edits any, approves, reviews, publishes), both on site 1. */
function changeCast(): array
{
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
    grantRole($reviewer, 'editor', ['entries.view', 'entries.edit.any', 'entries.submit', 'entries.approve', 'entries.review', 'entries.publish']);

    return [$author->fresh(), $reviewer->fresh()];
}

function changeOf(Entry $entry): ?EntryDraft
{
    return EntryDraft::query()->where('entry_id', $entry->id)->first();
}

function changeRejection(Closure $call): string
{
    try {
        $call();
    } catch (EntryDraftRejectedException $refused) {
        return $refused->reason;
    }

    throw new RuntimeException('Expected the pending change to be refused.');
}

it('holds a save on governed live content in the pending change, leaving visitors the old version', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $revisions = EntryRevision::query()->count();

        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'vi', [
            'title' => 'Bản tin mới',
            'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Mới'], 'id' => 'client-only']]],
            'not_a_field' => 'ignored',
        ]);

        $live = $entry->fresh();
        expect($live->translationFor('vi')->title)->toBe('Bản tin vi')
            ->and($live->status)->toBe('published')
            ->and($live->workflow_state)->toBeNull()
            ->and(EntryRevision::query()->count())->toBe($revisions)
            ->and($change->workflow_state)->toBeNull()
            ->and($change->created_by)->toBe($author->id)
            ->and($change->fresh()->content['locales']['vi'])->toMatchArray([
                'title' => 'Bản tin mới',
                'slug' => 'ban-tin-vi',   // the untouched field comes from what is live
                'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Mới']]]],
            ])
            ->and($change->fresh()->content['locales']['vi'])->not->toHaveKey('not_a_field')
            ->and($change->fresh()->content['shared'])->toMatchArray(['primary_category_id' => $live->primaryCategory()->id, 'meta' => []]);
    });

    $this->get('http://a.test/ban-tin-vi')->assertOk()->assertSee('Bản tin vi')->assertDontSee('Bản tin mới');
});

it('keeps one change per entry, merging each language and the shared fields into it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $save = app(SaveEntryDraft::class);

        $save->handle($entry, $author, 'vi', ['title' => 'Tiêu đề mới']);
        $save->handle($entry, $author, 'en', ['title' => 'New title', 'slug' => 'new-title'], ['meta' => ['subtitle' => 'Phụ đề']]);

        $content = changeOf($entry)->content;
        expect(EntryDraft::query()->count())->toBe(1)
            ->and($content['locales']['vi']['title'])->toBe('Tiêu đề mới')
            ->and($content['locales']['en'])->toMatchArray(['title' => 'New title', 'slug' => 'new-title', 'body' => ['blocks' => []]])
            ->and($content['shared']['meta'])->toBe(['subtitle' => 'Phụ đề'])
            // A language the live entry does not have yet is not public until the change is applied.
            ->and($entry->fresh()->translationFor('en'))->toBeNull();
    });
});

it('refuses a save it cannot hold', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author] = changeCast();
        $other = User::factory()->create();
        grantRole($other, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        $category = changeNewsCategory();
        $entry = changeLivePost($author, $category);
        changeWorkflow();
        $save = app(SaveEntryDraft::class);

        $offline = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id, 'meta' => []]);
        $elsewhere = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);   // no section, so no workflow

        expect(changeRejection(fn () => $save->handle($offline, $author, 'vi', ['title' => 'x'])))->toBe('not_live')
            ->and(changeRejection(fn () => $save->handle($entry, $author, 'fr', ['title' => 'x'])))->toBe('locale_disabled')
            ->and(changeRejection(fn () => $save->handle($entry, $author, 'vi', ['slug' => 'Không Hợp Lệ'])))->toBe('content_invalid')
            ->and(changeRejection(fn () => $save->handle($entry, $author, 'vi', [], ['primary_category_id' => $elsewhere->id])))->toBe('category_governed')
            ->and(fn () => $save->handle($entry, $other->fresh(), 'vi', ['title' => 'x']))->toThrow(AuthorizationException::class)
            ->and(EntryDraft::query()->count())->toBe(0);
    });
});

it('moves the change through the entry workflow as a change, and only an approver may approve it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'vi', ['title' => 'Bản tin mới']);
        $move = app(ApplyEntryDraftTransition::class);

        expect($move->handle($entry, 'vi', $author, 'submit', $change->version()))->toBe('pending');

        $log = WorkflowLog::query()->sole();
        expect($log->target)->toBe(WorkflowEngine::TARGET_CHANGE)
            ->and($log->from_state)->toBe('draft')
            ->and($log->to_state)->toBe('pending')
            ->and(changeOf($entry)->workflow_state)->toBe('pending')
            ->and($entry->fresh()->workflow_state)->toBeNull();   // the entry itself did not move

        $notice = SiteNotification::query()->where('user_id', $reviewer->id)->sole();
        expect($notice->kind)->toBe('workflow.pending_review')
            ->and($notice->data)->toMatchArray(['change' => true, 'title' => 'Bản tin mới'])
            ->and($notice->summary())->toBe(__('notifications.workflow.pending_review.change', ['actor' => $author->name, 'title' => 'Bản tin mới']));

        expect(fn () => $move->handle($entry, 'vi', $author, 'approve', changeOf($entry)->version()))
            ->toThrow(AuthorizationException::class);
        expect($entry->fresh()->translationFor('vi')->title)->toBe('Bản tin vi')
            ->and(changeOf($entry)->workflow_state)->toBe('pending')
            ->and(WorkflowLog::query()->count())->toBe(1);
    });
});

it('locks a submitted change against edits and discarding until it is returned', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $save = app(SaveEntryDraft::class);
        $move = app(ApplyEntryDraftTransition::class);
        $move->handle($entry, 'vi', $author, 'submit', $save->handle($entry, $author, 'vi', ['title' => 'Lần một'])->version());

        expect(changeRejection(fn () => $save->handle($entry, $author, 'vi', ['title' => 'Lần hai'])))->toBe('change_locked')
            ->and(changeRejection(fn () => app(DiscardEntryDraft::class)->handle($entry, $author, changeOf($entry)->version())))->toBe('change_pending');

        // Returning needs a note, and hands the change back to its author with it.
        expect(fn () => $move->handle($entry, 'vi', $reviewer, 'reject', changeOf($entry)->version()))
            ->toThrow(InvalidTransitionException::class);
        expect($move->handle($entry, 'vi', $reviewer, 'reject', changeOf($entry)->version(), 'Sửa số liệu'))->toBe('draft');

        $returned = SiteNotification::query()->where('user_id', $author->id)->where('kind', 'workflow.returned')->sole();
        expect($returned->data)->toMatchArray(['change' => true, 'note' => 'Sửa số liệu']);

        $save->handle($entry, $author, 'vi', ['title' => 'Lần hai']);
        expect(changeOf($entry)->content['locales']['vi']['title'])->toBe('Lần hai')
            ->and(changeOf($entry)->workflow_state)->toBe('draft');
    });
});

it('applies an approved change to the website, keeps it as a publish revision and closes the change', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory(), ['vi', 'en']);
        changeWorkflow();
        $move = app(ApplyEntryDraftTransition::class);
        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'vi', ['title' => 'Bản tin mới', 'slug' => 'ban-tin-moi']);
        $move->handle($entry, 'vi', $author, 'submit', $change->version());
        $before = EntryRevision::query()->max('id');

        expect($move->handle($entry, 'vi', $reviewer, 'approve', changeOf($entry)->version()))->toBe('published');

        $live = $entry->fresh();
        expect($live->translationFor('vi')->title)->toBe('Bản tin mới')
            ->and($live->translationFor('en')->title)->toBe('Bản tin en')
            ->and($live->status)->toBe('published')
            ->and(changeOf($entry))->toBeNull();

        // Only the language the change held: nothing every language shares changed.
        $new = EntryRevision::query()->where('id', '>', $before)->get();
        expect($new)->toHaveCount(1)
            ->and($new[0]->locale)->toBe('vi')
            ->and($new[0]->kind)->toBe(RevisionKind::Publish)
            ->and($new[0]->created_by)->toBe($reviewer->id)
            ->and($new[0]->snapshot)->toMatchArray(['title' => 'Bản tin mới', 'slug' => 'ban-tin-moi']);

        // A new address for live content: the route moves and the old one answers with a 301.
        expect(SiteRoute::query()->where('entry_id', $entry->id)->where('locale', 'vi')->value('path'))->toBe('/ban-tin-moi')
            ->and(Redirect::query()->where('from_path', '/ban-tin-vi')->where('to_path', '/ban-tin-moi')->exists())->toBeTrue();

        $audit = AuditLog::query()->where('action', EntryDraftAudit::APPLIED)->sole();
        expect($audit->actor_id)->toBe($reviewer->id)
            ->and($audit->meta)->toMatchArray(['entry_ulid' => $entry->ulid, 'locales' => ['vi'], 'workflow_state' => 'published', 'via' => 'workflow']);

        expect(SiteNotification::query()->where('user_id', $author->id)->where('kind', 'workflow.published')->sole()->data)
            ->toMatchArray(['change' => true, 'title' => 'Bản tin mới']);
        expect(WorkflowLog::query()->orderBy('id')->pluck('target')->all())->toBe(['change', 'change']);
    });

    $this->get('http://a.test/ban-tin-moi')->assertOk()->assertSee('Bản tin mới');
    $this->get('http://a.test/ban-tin-vi')->assertRedirect('http://a.test/ban-tin-moi');
});

it('applies every language of a change in locale order, recording every language when a shared field changed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory(), ['vi', 'en']);
        changeWorkflow();
        $save = app(SaveEntryDraft::class);
        $move = app(ApplyEntryDraftTransition::class);
        // Saved vi first, then en: the order written must not decide the order applied.
        $save->handle($entry, $author, 'vi', ['title' => 'Tiêu đề mới']);
        $change = $save->handle($entry, $author, 'en', ['title' => 'New title'], ['meta' => ['subtitle' => 'Phụ đề']]);
        $move->handle($entry, 'vi', $author, 'submit', $change->version());
        $before = EntryRevision::query()->max('id');

        $move->handle($entry, 'vi', $reviewer, 'approve', changeOf($entry)->version());

        $live = $entry->fresh();
        expect($live->translationFor('vi')->title)->toBe('Tiêu đề mới')
            ->and($live->translationFor('en')->title)->toBe('New title')
            ->and($live->meta)->toBe(['subtitle' => 'Phụ đề'])
            ->and(EntryRevision::query()->where('id', '>', $before)->orderBy('locale')->pluck('locale')->all())->toBe(['en', 'vi'])
            ->and(AuditLog::query()->where('action', EntryDraftAudit::APPLIED)->sole()->meta['locales'])->toBe(['en', 'vi']);
    });
});

it('writes the languages of a change in locale order, whatever order the change lists them in', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory(), ['vi', 'en']);
        $text = fn (string $title, string $slug): array => ['title' => $title, 'slug' => $slug, 'body' => ['blocks' => []]];
        // Not read back from jsonb (which happens to sort two-letter codes): listed vi first.
        $change = new EntryDraft(['entry_id' => $entry->id, 'content' => [
            'v' => 1,
            'shared' => ['template_override' => null, 'cover_media_id' => null, 'meta' => [], 'primary_category_id' => $entry->primaryCategory()->id],
            'locales' => ['vi' => $text('Tiếng Việt', 'tieng-viet'), 'en' => $text('English', 'english')],
        ]]);

        $applied = app(EntryDraftApplier::class)->apply($entry, $change);

        expect($applied['locales'])->toBe(['en', 'vi'])
            ->and(array_map(fn ($translation) => $translation->locale, $applied['translations']))->toBe(['en', 'vi'])
            ->and($applied['all_locales'])->toBeFalse();
    });
});

it('re-files the post under the change category only on approval', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $news = changeNewsCategory();
        $sub = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1, 'parent_id' => $news->id]);   // same section
        $entry = changeLivePost($author, $news, ['vi', 'en']);
        changeWorkflow();
        $move = app(ApplyEntryDraftTransition::class);
        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'vi', [], ['primary_category_id' => $sub->id]);
        $move->handle($entry, 'vi', $author, 'submit', $change->version());
        expect($entry->fresh()->primaryCategory()->id)->toBe($news->id);   // not before approval
        $before = EntryRevision::query()->max('id');

        $move->handle($entry, 'vi', $reviewer, 'approve', changeOf($entry)->version());

        // A revision holds no categories, so a change of category alone leaves no new revision
        // (each language's snapshot equals its newest one) — the audit line records the approval.
        expect($entry->fresh()->primaryCategory()->id)->toBe($sub->id)
            ->and(EntryRevision::query()->where('id', '>', $before)->count())->toBe(0)
            ->and(changeOf($entry))->toBeNull()
            ->and(AuditLog::query()->where('action', EntryDraftAudit::APPLIED)->exists())->toBeTrue();
    });
});

it('puts a language the change added online with its own address once approved', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $move = app(ApplyEntryDraftTransition::class);
        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'en', ['title' => 'News', 'slug' => 'news']);
        $move->handle($entry, 'vi', $author, 'submit', $change->version());

        $move->handle($entry, 'vi', $reviewer, 'approve', changeOf($entry)->version());

        expect($entry->fresh()->translationFor('en')->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $entry->id)->where('locale', 'en')->exists())->toBeTrue();
    });
});

it('rolls an approval back whole when the new address belongs to other content', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $category = changeNewsCategory();
        $entry = changeLivePost($author, $category);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now(), 'meta' => []]);
        $other->translations()->create(['locale' => 'vi', 'title' => 'Khác', 'slug' => 'da-dung', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RouteSync::class)->sync($other, 'vi', 'da-dung');
        changeWorkflow();
        $move = app(ApplyEntryDraftTransition::class);
        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'vi', ['title' => 'Tên mới', 'slug' => 'da-dung']);
        $move->handle($entry, 'vi', $author, 'submit', $change->version());
        $revisions = EntryRevision::query()->count();

        expect(changeRejection(fn () => $move->handle($entry, 'vi', $reviewer, 'approve', changeOf($entry)->version())))->toBe('slug_taken');

        expect(WorkflowLog::query()->count())->toBe(1)   // the submit only
            ->and(EntryRevision::query()->count())->toBe($revisions)
            ->and(changeOf($entry)->workflow_state)->toBe('pending')
            ->and(changeOf($entry)->content['locales']['vi']['title'])->toBe('Tên mới')
            ->and($entry->fresh()->translationFor('vi')->title)->toBe('Bản tin vi')
            ->and(AuditLog::query()->where('action', EntryDraftAudit::APPLIED)->exists())->toBeFalse();
    });
});

it('refuses to approve a change resubmitted since the reviewer read it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $save = app(SaveEntryDraft::class);
        $move = app(ApplyEntryDraftTransition::class);
        $move->handle($entry, 'vi', $author, 'submit', $save->handle($entry, $author, 'vi', ['title' => 'Đã đọc'])->version());
        $read = changeOf($entry)->version();   // what the reviewer's open tab shows

        // Returned, edited and sent again: the same stage, different content.
        $move->handle($entry, 'vi', $reviewer, 'reject', $read, 'Thêm nguồn');
        $move->handle($entry, 'vi', $author, 'submit', $save->handle($entry, $author, 'vi', ['title' => 'Chưa đọc'])->version());

        expect(changeRejection(fn () => $move->handle($entry, 'vi', $reviewer, 'approve', $read)))->toBe('change_stale')
            ->and($entry->fresh()->translationFor('vi')->title)->toBe('Bản tin vi')
            ->and(changeOf($entry)->workflow_state)->toBe('pending');

        $move->handle($entry, 'vi', $reviewer, 'approve', changeOf($entry)->version());
        expect($entry->fresh()->translationFor('vi')->title)->toBe('Chưa đọc');
    });
});

it('fingerprints the content, not the order jsonb stores it in', function (): void {
    $a = new EntryDraft(['content' => ['v' => 1, 'shared' => ['meta' => [], 'cover_media_id' => null], 'locales' => ['vi' => ['title' => 'A', 'slug' => 'a']]]]);
    $b = new EntryDraft(['content' => ['locales' => ['vi' => ['slug' => 'a', 'title' => 'A']], 'shared' => ['cover_media_id' => null, 'meta' => []], 'v' => 1]]);
    $c = new EntryDraft(['content' => ['v' => 1, 'shared' => ['meta' => [], 'cover_media_id' => null], 'locales' => ['vi' => ['title' => 'B', 'slug' => 'a']]]]);

    expect($a->version())->toBe($b->version())->not->toBe($c->version());
});

it('folds an open change into an entry leaving the website, skipping content that no longer passes', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory(), ['vi', 'en']);
        changeWorkflow();
        app(SaveEntryDraft::class)->handle($entry, $author, 'vi', ['title' => 'Gộp vào bài']);
        // A language whose layout uses a block the editor no longer offers.
        $change = changeOf($entry);
        $content = $change->content;
        $content['locales']['en'] = ['title' => 'Broken', 'slug' => 'broken', 'body' => ['blocks' => [['type' => 'retired-block', 'props' => []]]]];
        $change->update(['content' => $content]);
        $revisions = EntryRevision::query()->count();
        $folder = app(EntryDraftFolder::class);

        // Still live: folding now would put unapproved content in front of visitors.
        expect(fn () => DB::transaction(fn () => $folder->fold($entry->fresh(), $reviewer)))->toThrow(LogicException::class);

        $folded = DB::transaction(function () use ($entry, $folder, $reviewer): bool {
            $entry->update(['status' => 'draft']);

            return $folder->fold($entry, $reviewer);
        });

        expect($folded)->toBeTrue()
            ->and($entry->fresh()->translationFor('vi')->title)->toBe('Gộp vào bài')
            ->and($entry->fresh()->translationFor('en')->title)->toBe('Bản tin en')
            ->and(changeOf($entry))->toBeNull()
            ->and(EntryRevision::query()->count())->toBe($revisions)
            ->and(AuditLog::query()->where('action', EntryDraftAudit::FOLDED)->sole()->meta)
            ->toMatchArray(['locales' => ['vi'], 'skipped_locales' => ['en'], 'status' => 'draft']);

        expect(DB::transaction(fn () => $folder->fold($entry->fresh(), $reviewer)))->toBeFalse();
    });
});

it('lets only a publisher put a change online where no workflow governs the entry', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $publisher] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        $save = app(SaveEntryDraft::class);
        $apply = app(ApplyEntryDraft::class);

        // Without the publish right an edit of live content is held back; with it, not — until a
        // change exists, which a direct save would otherwise be overwritten by.
        expect($save->holdsBack($entry, $author))->toBeTrue()
            ->and($save->holdsBack($entry, $publisher))->toBeFalse();

        $change = $save->handle($entry, $author, 'vi', ['title' => 'Chờ cập nhật']);
        expect($save->holdsBack($entry, $publisher))->toBeTrue()
            ->and(SiteNotification::query()->where('user_id', $publisher->id)->where('kind', 'workflow.pending_review')->sole()->data)
            ->toMatchArray(['change' => true]);

        expect(fn () => $apply->handle($entry, $author, $change->version()))->toThrow(AuthorizationException::class);
        expect($entry->fresh()->translationFor('vi')->title)->toBe('Bản tin vi');

        expect($apply->handle($entry, $publisher, $change->version()))->toBe(['vi']);

        $newest = EntryRevision::query()->orderByDesc('id')->first();
        expect($entry->fresh()->translationFor('vi')->title)->toBe('Chờ cập nhật')
            ->and($newest->kind)->toBe(RevisionKind::Edit)
            ->and($newest->created_by)->toBe($publisher->id)
            ->and(changeOf($entry))->toBeNull()
            ->and(AuditLog::query()->where('action', EntryDraftAudit::APPLIED)->sole()->meta)->toMatchArray(['via' => 'direct', 'workflow_state' => null])
            ->and(SiteNotification::query()->where('user_id', $author->id)->where('kind', 'workflow.published')->exists())->toBeTrue();
    });
});

it('hands a change left mid-review by a switched-off workflow to the publishers, and back to the workflow when it returns', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $publisher] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        $workflow = changeWorkflow();
        $save = app(SaveEntryDraft::class);
        $apply = app(ApplyEntryDraft::class);
        $change = $save->handle($entry, $author, 'vi', ['title' => 'Giữa chừng']);
        app(ApplyEntryDraftTransition::class)->handle($entry, 'vi', $author, 'submit', $change->version());

        expect(changeRejection(fn () => $apply->handle($entry, $publisher, changeOf($entry)->version())))->toBe('workflow_governed');

        $workflow->update(['is_active' => false]);

        // No longer locked: its author may keep editing, and the edit leaves the old stage behind.
        $save->handle($entry, $author, 'vi', ['title' => 'Sửa tiếp']);
        expect(changeOf($entry)->workflow_state)->toBeNull();

        $apply->handle($entry, $publisher, changeOf($entry)->version());
        expect($entry->fresh()->translationFor('vi')->title)->toBe('Sửa tiếp')
            ->and(changeOf($entry))->toBeNull();
    });
});

it('throws a change away only for someone who may edit the entry, and only the version they saw', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author] = changeCast();
        $other = User::factory()->create();
        grantRole($other, 'author', ['entries.view', 'entries.edit.own']);
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $save = app(SaveEntryDraft::class);
        $discard = app(DiscardEntryDraft::class);
        $seen = $save->handle($entry, $author, 'vi', ['title' => 'Bỏ đi'])->version();

        expect(fn () => $discard->handle($entry, $other->fresh(), $seen))->toThrow(AuthorizationException::class);

        $save->handle($entry, $author, 'vi', ['title' => 'Sửa sau đó']);
        expect(changeRejection(fn () => $discard->handle($entry, $author, $seen)))->toBe('change_stale');

        $discard->handle($entry, $author, changeOf($entry)->version());

        expect(changeOf($entry))->toBeNull()
            ->and($entry->fresh()->translationFor('vi')->title)->toBe('Bản tin vi')
            ->and(AuditLog::query()->where('action', EntryDraftAudit::DISCARDED)->sole()->meta)->toMatchArray(['locales' => ['vi'], 'entry_ulid' => $entry->ulid]);
    });
});

it('keeps each site to its own pending changes', function (): void {
    $siteA = app(Tenancy::class)->enter(1, function (): array {
        [$author] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory());
        changeWorkflow();
        $change = app(SaveEntryDraft::class)->handle($entry, $author, 'vi', ['title' => 'Của site A']);

        return [$entry, $change->version()];
    });
    [$entry, $version] = $siteA;

    app(Tenancy::class)->enter(2, function () use ($entry, $version): void {
        $intruder = User::factory()->create();
        grantRole($intruder, 'owner-b', ['entries.view', 'entries.edit.any', 'entries.submit', 'entries.approve', 'entries.review', 'entries.publish']);
        $intruder = $intruder->fresh();

        expect(EntryDraft::query()->count())->toBe(0)
            ->and(fn () => app(SaveEntryDraft::class)->handle($entry, $intruder, 'vi', ['title' => 'Chiếm']))->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(ApplyEntryDraftTransition::class)->handle($entry, 'vi', $intruder, 'submit', $version))->toThrow(EntryNotGovernedException::class)
            ->and(fn () => app(ApplyEntryDraft::class)->handle($entry, $intruder, $version))->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(DiscardEntryDraft::class)->handle($entry, $intruder, $version))->toThrow(ModelNotFoundException::class);
    });

    app(Tenancy::class)->enter(1, function () use ($entry, $version): void {
        expect(changeOf($entry)->version())->toBe($version)
            ->and(changeOf($entry)->workflow_state)->toBeNull()
            ->and($entry->fresh()->translationFor('vi')->title)->toBe('Bản tin vi')
            ->and(WorkflowLog::query()->count())->toBe(0);
    });
});

it('approves a two-language change within its query budget', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        [$author, $reviewer] = changeCast();
        $entry = changeLivePost($author, changeNewsCategory(), ['vi', 'en']);
        changeWorkflow();
        $save = app(SaveEntryDraft::class);
        $move = app(ApplyEntryDraftTransition::class);
        $save->handle($entry, $author, 'vi', ['title' => 'Tiêu đề mới', 'slug' => 'tieu-de-moi']);
        $change = $save->handle($entry, $author, 'en', ['title' => 'New title']);
        $move->handle($entry, 'vi', $author, 'submit', $change->version());
        $version = changeOf($entry)->version();
        $reviewer->can('entries.approve');   // warm the permission cache, as a request would have

        $fresh = $entry->fresh();
        $queries = countQueries(fn () => $move->handle($fresh, 'vi', $reviewer, 'approve', $version));

        // Measured 36: the workflow lookup, lock + read, the engine's guard and log (~9); per
        // language the translation read/write and block-usage rebuild (~6), plus the route move and
        // 301 for the one new address (~9); a revision per language (2 each); delete, audit and the
        // author's notice. It grows with the languages in the change, never with the site's size.
        expect($queries)->toBeLessThanOrEqual(40);
    });
});

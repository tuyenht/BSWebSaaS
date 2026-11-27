<?php

use App\Modules\Admin\Livewire\Entries\ReviewChanges;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * «Thay đổi của bài đang đăng» in the review inbox (board 17): the pending changes to live posts that
 * wait at a stage the reviewer can act on, compared with what visitors see and moved on through the
 * engine — approved live, sent back with a note, or on to a middle step of a longer flow. Visitors
 * keep the live version until a change is approved.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** The `posts` collection (category «Tin tức»); returns the category. */
function rcCollection(): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);

    return $category;
}

/** A post by $author, put live by $publisher. */
function rcLivePost(Taxonomy $category, User $author, User $publisher, string $slug = 'bai-1'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bản đang đăng', 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Mở đầu cũ']]]],
    ]);
    $entry->assignCategories([$category->id]);
    app(PublishEntry::class)->handle($entry->fresh(), 'vi', $publisher->fresh());

    return $entry->fresh();
}

/** $author's edit of a live post, saved into its change and sent for review (the flow's first move). */
function rcSentChange(Entry $post, User $author, string $title, string $send = 'submit'): EntryDraft
{
    app(SaveEntryDraft::class)->handle($post->fresh(), $author->fresh(), 'vi', ['title' => $title]);
    $change = EntryDraft::query()->where('entry_id', $post->id)->firstOrFail();
    app(ApplyEntryDraftTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), $send, $change->version());

    return $change->fresh();
}

/**
 * An author (edits own, sends), a publisher who puts posts live, and the people who review.
 *
 * @return array{author: User, publisher: User, approver: User, reviewer: User, member: User}
 */
function rcCast(): array
{
    $cast = ['author' => User::factory()->create(), 'publisher' => User::factory()->create(), 'approver' => User::factory()->create(),
        'reviewer' => User::factory()->create(), 'member' => User::factory()->create()];
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        grantRole($cast['author'], 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($cast['publisher'], 'publisher', ['entries.view', 'entries.edit.any', 'entries.publish']);
        grantRole($cast['approver'], 'approver', ['entries.view', 'entries.review', 'entries.approve']);
        grantRole($cast['reviewer'], 'reviewer', ['entries.view', 'entries.review']);
        grantRole($cast['member'], 'member', ['entries.view', 'entries.edit.own']);
    });
    app(TeamContext::class)->forget();

    return array_map(fn (User $user): User => $user->fresh(), $cast);
}

function rcChange(Entry $post): ?EntryDraft
{
    return EntryDraft::query()->where('entry_id', $post->id)->first();
}

function rcLiveTitle(Entry $post): string
{
    return EntryTranslation::query()->where('entry_id', $post->id)->where('locale', 'vi')->value('title');
}

it('lists a sent change for its approver with «So sánh», «Trả lại» and «Duyệt đăng», and what it changes', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Bản sửa chờ duyệt');

        Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->assertSeeHtml('data-chg-card')
            ->assertSee(__('admin.inbox.chg_title'))
            ->assertSee(__('admin.inbox.chg_desc'))
            ->assertSee('Bản sửa chờ duyệt')
            ->assertSee(trans_choice('admin.inbox.chg_edits', 1, ['n' => 1]))
            ->assertSee($cast['author']->name)
            ->assertSeeHtml('data-chg="'.$post->ulid.'"')
            ->assertSeeHtml('data-chg-view')
            ->assertSeeHtml('data-chg-return')
            ->assertSeeHtml('data-chg-approve')
            ->assertDontSeeHtml('data-chg-why');

        // «So sánh»: the new title over what visitors see.
        Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->call('openChange', $post->ulid, 'view')
            ->assertHasNoErrors()
            ->assertSet('compareOpen', true)
            ->assertSee(__('admin.inbox.chg_compare_title', ['title' => 'Bản sửa chờ duyệt']))
            ->assertSee(__('entries.chg.diff_seen', ['text' => 'Bản đang đăng']))
            ->assertSeeHtml('data-chg-dlg-approve');
    });
});

it('shows each reviewer only the changes at a step they act on, inside their categories', function (): void {
    $cast = rcCast();
    $limited = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($cast, $limited): void {
        $category = rcCollection();
        $post = rcLivePost($category, $cast['author'], $cast['publisher']);
        $unsent = rcLivePost($category, $cast['author'], $cast['publisher'], 'bai-2');
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Bản đã gửi');
        // Saved but not sent: still the writer's work, waiting for nobody.
        app(SaveEntryDraft::class)->handle($unsent->fresh(), $cast['author'], 'vi', ['title' => 'Bản chưa gửi']);

        // Without the approve right a one-step flow's change is only seen, with the reason.
        Livewire::actingAs($cast['reviewer'])->test(ReviewChanges::class)
            ->assertSee('Bản đã gửi')
            ->assertDontSee('Bản chưa gửi')
            ->assertSee(__('admin.inbox.chg_why_approve'))
            ->assertDontSeeHtml('data-chg-approve')
            ->assertDontSeeHtml('data-chg-return');

        // A reviewer limited to another category sees none of it.
        grantRole($limited, 'approver', ['entries.view', 'entries.review', 'entries.approve']);
        $other = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
        $other->translations()->create(['locale' => 'vi', 'name' => 'Khác', 'slug' => 'khac']);
        MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $other->id]);
        app(CategoryScopes::class)->forget();
        Livewire::actingAs($limited->fresh())->test(ReviewChanges::class)
            ->assertDontSeeHtml('data-chg-card')
            ->call('openChange', $post->ulid, 'approve')
            ->assertHasErrors('changes')
            ->assertSet('approveOpen', false);
    });
});

it('refuses the card, its page and its calls to a member without review rights', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Bản sửa');

        Livewire::actingAs($cast['member'])->test(ReviewChanges::class)->assertForbidden();
        Livewire::actingAs($cast['author'])->test(ReviewChanges::class)->assertForbidden();
    });

    $this->actingAs($cast['member'])->get('http://a.test/admin/content/review')->assertForbidden();
    app(Tenancy::class)->enter(1, fn () => expect(rcChange(Entry::query()->firstOrFail())?->workflow_state)->toBe('pending'));
});

it('approves a change live from its question, then focuses on what is left', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $category = rcCollection();
        $post = rcLivePost($category, $cast['author'], $cast['publisher']);
        $next = rcLivePost($category, $cast['author'], $cast['publisher'], 'bai-2');
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Bản sửa đã duyệt');
        rcSentChange($next, $cast['author'], 'Bản sửa kế tiếp');

        Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->call('openChange', $post->ulid, 'approve')
            ->assertSet('approveOpen', true)
            ->assertSee(__('entries.chg.ask_approve'))
            ->assertSee(__('entries.chg.ask_langs', ['langs' => 'Tiếng Việt']))
            ->call('confirmChange')
            ->assertHasNoErrors()
            ->assertSet('approveOpen', false)
            ->assertDispatched('bs-toast', text: __('admin.inbox.chg_t_approved', ['langs' => 'Tiếng Việt']))
            ->assertDontSee('Bản sửa đã duyệt')
            ->assertSee('Bản sửa kế tiếp');

        expect(rcChange($post))->toBeNull()
            ->and(rcLiveTitle($post))->toBe('Bản sửa đã duyệt')
            ->and(rcChange($next)?->workflow_state)->toBe('pending');
    });
});

it('sends a change back only with a note, keeping the note dialog open until one is written', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Bản cần sửa');

        $card = Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->call('openChange', $post->ulid, 'return')
            ->assertSet('returnOpen', true)
            ->assertSee(__('entries.chg.return_title'))
            ->assertSee(__('admin.inbox.chg_return_line', ['by' => $cast['author']->name]))
            ->set('note', '   ')
            ->call('confirmChange')
            ->assertHasErrors(['note'])
            ->assertSee(__('entries.chg.return_err'))
            ->assertSet('returnOpen', true);
        expect(rcChange($post)?->workflow_state)->toBe('pending');

        $card->set('note', 'Sửa lại đoạn giá năm 2026')
            ->call('confirmChange')
            ->assertHasNoErrors()
            ->assertSet('returnOpen', false)
            ->assertSet('note', '')
            ->assertDispatched('bs-toast', text: __('admin.inbox.chg_t_returned', ['by' => $cast['author']->name]))
            ->assertDontSeeHtml('data-chg-card');

        $change = rcChange($post);
        $workflow = Workflow::query()->where('applies_to_type', 'posts')->firstOrFail();
        expect(app(EntryWorkflowResolver::class)->changeIsOpen($workflow, $change))->toBeTrue()
            ->and(rcLiveTitle($post))->toBe('Bản đang đăng')
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->where('target', 'change')->where('note', 'Sửa lại đoạn giá năm 2026')->exists())->toBeTrue();
    });
});

it('moves a change on through the middle step of a longer flow, with the flow’s own moves', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('editorial', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Bản sửa biên tập');

        // At «Chờ biên tập» the reviewer has the step's two moves: the note one first.
        $card = Livewire::actingAs($cast['reviewer'])->test(ReviewChanges::class)
            ->assertSee('Chờ biên tập')
            ->assertSee('Trả lại tác giả')
            ->assertSee('Chuyển phản biện')
            ->assertSeeHtml('data-chg-move="send_back"')
            ->assertSeeHtml('data-chg-move="to_review"')
            ->assertDontSeeHtml('data-chg-approve');
        expect(strpos($card->html(), 'data-chg-move="send_back"'))->toBeLessThan(strpos($card->html(), 'data-chg-move="to_review"'));

        $card->call('moveChange', $post->ulid, 'to_review')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('admin.inbox.chg_t_moved', ['step' => 'Đang phản biện']));
        expect(rcChange($post)?->workflow_state)->toBe('reviewing')
            ->and(rcLiveTitle($post))->toBe('Bản đang đăng');

        // At «Đang phản biện» the approver approves it live; the reviewer may only ask for edits.
        Livewire::actingAs($cast['reviewer'])->test(ReviewChanges::class)
            ->assertSeeHtml('data-chg-move="request_changes"')
            ->assertDontSeeHtml('data-chg-move="approve"');
        Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->assertSee('Đang phản biện')
            ->call('openChange', $post->ulid, 'approve', 'approve')
            ->assertSet('approveOpen', true)
            ->call('confirmChange')
            ->assertHasNoErrors();
        expect(rcChange($post))->toBeNull()
            ->and(rcLiveTitle($post))->toBe('Bản sửa biên tập');
    });
});

it('refuses a move the reviewer may not make, and a forged one', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('editorial', 'posts', active: true);
        $change = rcSentChange($post, $cast['author'], 'Bản sửa');

        // An approve-only person has no move at «Chờ biên tập»: the row says why, and calls are refused.
        $approveOnly = User::factory()->create();
        grantRole($approveOnly, 'signer', ['entries.view', 'entries.approve']);
        Livewire::actingAs($approveOnly->fresh())->test(ReviewChanges::class)
            ->assertDontSeeHtml('data-chg-card')
            ->call('moveChange', $post->ulid, 'to_review')
            ->assertHasErrors('changes');
        expect(rcChange($post)?->workflow_state)->toBe('pending');

        // A reviewer without the approve right cannot open «Duyệt đăng» on it either.
        Livewire::actingAs($cast['reviewer'])->test(ReviewChanges::class)
            ->call('openChange', $post->ulid, 'approve')
            ->assertHasErrors('changes')
            ->assertSet('approveOpen', false);

        // The dialog's move cannot be forged from the browser.
        expect(fn () => Livewire::actingAs($cast['reviewer'])->test(ReviewChanges::class)->set('moveKey', 'approve'))
            ->toThrow(CannotUpdateLockedPropertyException::class);
        expect(rcChange($post)?->workflow_state)->toBe('pending')
            ->and($change->fresh()?->version())->toBe($change->version());
    });
});

it('refuses to move a change edited after the row was drawn', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('editorial', 'posts', active: true);
        $change = rcSentChange($post, $cast['author'], 'Bản đã xem');

        $card = Livewire::actingAs($cast['reviewer'])->test(ReviewChanges::class)->assertSee('Bản đã xem');
        $content = $change->content;
        $content['locales']['vi']['title'] = 'Bản chưa xem';
        $change->update(['content' => $content]);

        $card->call('moveChange', $post->ulid, 'to_review')
            ->assertHasErrors('changes')
            ->assertSee(__('entries.change_err_change_stale'));
        expect(rcChange($post)?->workflow_state)->toBe('pending');
    });
});

it('refuses to approve live, from the row, a change edited after the row was drawn', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $change = rcSentChange($post, $cast['author'], 'Bản đã xem');

        $card = Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)->assertSee('Bản đã xem');
        $content = $change->content;
        $content['locales']['vi']['title'] = 'Bản chưa xem';
        $change->update(['content' => $content]);

        $card->call('openChange', $post->ulid, 'approve')
            ->assertSet('approveOpen', true)
            ->call('confirmChange')
            ->assertHasErrors('changes')
            ->assertSee(__('entries.change_err_change_stale'));
        expect(rcLiveTitle($post))->toBe('Bản đang đăng')
            ->and(rcChange($post)?->workflow_state)->toBe('pending');
    });
});

it('draws no card while nothing waits', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->assertDontSeeHtml('data-chg-card')
            ->assertDontSee(__('admin.inbox.chg_title'));
    });

    $this->actingAs($cast['approver'])->get('http://a.test/admin/content/review?tab=queue')->assertOk()
        ->assertSee(__('admin.inbox.empty_queue'))
        ->assertDontSee(__('admin.inbox.chg_title'));
});

it('pages the card by «Tải thêm» and keeps another website’s changes out', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $category = rcCollection();
        $posts = [];
        foreach (range(1, 7) as $i) {
            $posts[$i] = rcLivePost($category, $cast['author'], $cast['publisher'], "bai-{$i}");
        }
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        foreach (range(1, 7) as $i) {
            rcSentChange($posts[$i], $cast['author'], "Thay đổi số {$i}");
            EntryDraft::query()->where('entry_id', $posts[$i]->id)->update(['updated_at' => now()->subMinutes(10 - $i)]);
        }

        $card = Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->assertSeeHtml('data-chg-count>7<')
            ->assertSee('Thay đổi số 7')
            ->assertSee('Thay đổi số 3')
            ->assertDontSee('Thay đổi số 2')
            ->assertSeeHtml('data-chg-more');
        // The page's place in the list reaches the browser sealed, never as a row id.
        expect($card->get('through'))->toBeString()->not->toContain('"id"');
        $card->call('loadMoreChanges')
            ->assertSee('Thay đổi số 1')
            ->assertSee('Thay đổi số 7')
            ->assertDontSeeHtml('data-chg-more');
    });

    // Each website's reviewer sees only its own website's changes.
    $other = ['author' => User::factory()->create(), 'publisher' => User::factory()->create(), 'approver' => User::factory()->create()];
    app(Tenancy::class)->enter(2, function () use ($other): void {
        grantRole($other['author'], 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($other['publisher'], 'publisher', ['entries.view', 'entries.edit.any', 'entries.publish']);
        grantRole($other['approver'], 'approver', ['entries.view', 'entries.review', 'entries.approve']);
        $post = rcLivePost(rcCollection(), $other['author']->fresh(), $other['publisher']->fresh(), 'bai-b');
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $other['author'], 'Thay đổi của website B');

        Livewire::actingAs($other['approver']->fresh())->test(ReviewChanges::class)
            ->assertSee('Thay đổi của website B')
            ->assertDontSee('Thay đổi số 7')
            ->assertSeeHtml('data-chg-count>1<');
    });
    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
        ->assertSee('Thay đổi số 7')
        ->assertDontSee('Thay đổi của website B'));
});

it('renders the inbox with its change card on a flat query budget', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $category = rcCollection();
        $post = rcLivePost($category, $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        rcSentChange($post, $cast['author'], 'Thay đổi 1');
    });

    $url = 'http://a.test/admin/content/review';
    $this->actingAs($cast['approver'])->get($url)->assertOk();            // warm the permission cache
    $lean = countQueries(fn () => $this->actingAs($cast['approver'])->get($url)->assertOk()->assertSee('Thay đổi 1'));

    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->firstOrFail();
        foreach (range(2, 5) as $i) {
            $workflow = Workflow::query()->where('applies_to_type', 'posts')->firstOrFail();
            $workflow->update(['is_active' => false]);
            $post = rcLivePost($category, $cast['author'], $cast['publisher'], "bai-{$i}");
            $workflow->update(['is_active' => true]);
            rcSentChange($post, $cast['author'], "Thay đổi {$i}");
        }
    });

    $loaded = countQueries(fn () => $this->actingAs($cast['approver'])->get($url)->assertOk()->assertSee('Thay đổi 5'));

    expect($loaded)->toBe($lean)->and($lean)->toBeLessThan(80);
});

it('lets only someone who may edit the post move an unsent change on, whatever the flow offers from there', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        $workflow = app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        // A site's own flow: a shortcut from the first stage straight to the website, for approvers.
        $workflow->transitions()->create(['key' => 'fast', 'label' => 'Đăng nhanh', 'from_state' => 'draft', 'to_state' => 'published', 'required_permission' => 'entries.approve']);
        app(SaveEntryDraft::class)->handle($post->fresh(), $cast['author'], 'vi', ['title' => 'Bản chưa gửi']);
        $change = rcChange($post);

        expect(fn () => app(ApplyEntryDraftTransition::class)->handle($post->fresh(), 'vi', $cast['approver'], 'fast', $change->version()))
            ->toThrow(AuthorizationException::class);
        expect(rcLiveTitle($post))->toBe('Bản đang đăng')
            ->and(app(EntryWorkflowResolver::class)->changeMovesFor($workflow->fresh(), $post->fresh(), $change, $cast['approver']))->toBe([]);
    });
});

it('hands a change on only to a member who can act at the next step', function (): void {
    $cast = rcCast();
    $stranger = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($cast, $stranger): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        app(SaveEntryDraft::class)->handle($post->fresh(), $cast['author'], 'vi', ['title' => 'Bản sửa']);
        $version = rcChange($post)->version();

        expect(fn () => app(ApplyEntryDraftTransition::class)->handle($post->fresh(), 'vi', $cast['author'], 'submit', $version, null, $stranger->id))
            ->toThrow(AuthorizationException::class);
        expect(rcChange($post)?->workflow_state)->toBeNull();

        app(ApplyEntryDraftTransition::class)->handle($post->fresh(), 'vi', $cast['author'], 'submit', $version, null, $cast['approver']->id);
        expect(rcChange($post)?->workflow_state)->toBe('pending')
            ->and(rcChange($post)?->assigned_to)->toBe($cast['approver']->id);
    });
});

it('gives a change parked at an end stage, or one with no move out, back to its writers', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        $post = rcLivePost(rcCollection(), $cast['author'], $cast['publisher']);
        $workflow = app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $workflow->states()->create(['key' => 'parked', 'label' => 'Để riêng', 'sort_order' => 9]);
        $change = rcSentChange($post, $cast['author'], 'Bản sửa');
        $resolver = app(EntryWorkflowResolver::class);

        foreach (['parked', 'archived'] as $stage) {
            $change->update(['workflow_state' => $stage]);
            expect($resolver->changeIsOpen($workflow->fresh(), $change->fresh()))->toBeTrue()
                ->and($resolver->changeOriginFor($workflow->fresh(), $change->fresh()))->toBe('draft');
        }
        // The writer can edit it again, and nobody's inbox holds it meanwhile.
        app(SaveEntryDraft::class)->handle($post->fresh(), $cast['author'], 'vi', ['title' => 'Bản sửa tiếp']);
        expect(rcChange($post)?->content['locales']['vi']['title'])->toBe('Bản sửa tiếp');
        Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)->assertDontSeeHtml('data-chg-card');
        expect($resolver->changeIsOpen($workflow->fresh(), rcChange($post)->fresh()))->toBeTrue();
        rcChange($post)->update(['workflow_state' => 'pending']);
        expect($resolver->changeIsOpen($workflow->fresh(), rcChange($post)->fresh()))->toBeFalse();
    });
});

it('names a sub-page’s taken address as visitors see it when approving a change live is refused, with the language when it is not the reviewed one', function (string $clash): void {
    app()->setLocale('vi');   // the language bar would say «Tiếng Anh»; the inbox's chips and this card say «English»
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast, $clash): void {
        $category = rcCollection();
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $about = rcLivePost($category, $cast['author'], $cast['publisher'], 'gioi-thieu');
        $about->update(['type' => 'page']);
        $team = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $cast['author']->id, 'parent_id' => $about->id]);
        $team->translations()->create(['locale' => 'vi', 'title' => 'Đội ngũ', 'slug' => 'doi-ngu', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($team->fresh(), 'vi', $cast['publisher']);
        $about->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($about->fresh(), 'en', $cast['publisher']);
        $team->translations()->create(['locale' => 'en', 'title' => 'Team', 'slug' => 'team', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($team->fresh(), 'en', $cast['publisher']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // one change in both languages; the clash is in $clash, where the sub-page would move
        $slugs = ['vi' => 've-chung-toi', 'en' => 'about-us'];
        $take = $clash === 'en' ? ['en', '/about-us/team'] : ['vi', '/ve-chung-toi/doi-ngu'];
        app(SaveEntryDraft::class)->handle($about->fresh(), $cast['author'], 'vi', ['slug' => $slugs['vi']]);
        app(SaveEntryDraft::class)->handle($about->fresh(), $cast['author'], 'en', ['slug' => $slugs['en']]);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $cast['author']->id]);
        SiteRoute::query()->create(['locale' => $take[0], 'path' => $take[1], 'entry_id' => $other->id]);
        $change = EntryDraft::query()->where('entry_id', $about->id)->firstOrFail();
        app(ApplyEntryDraftTransition::class)->handle($about->fresh(), 'vi', $cast['author'], 'submit', $change->version());
        $stored = $change->fresh()?->version();

        $says = $clash === 'en'
            ? __('entries.change_err_subpage_path_taken_lang', ['path' => '/en/about-us/team', 'lang' => 'English'])
            : __('entries.change_err_subpage_path_taken', ['path' => '/ve-chung-toi/doi-ngu']);
        $card = Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)
            ->call('openChange', $about->ulid, 'approve')
            ->call('confirmChange');
        $card->assertHasErrors('changes');
        expect($card->errors()->first('changes'))->toBe($says)
            ->and(rcChange($about)?->version())->toBe($stored);
    });
})->with(['in another language' => 'en', 'in the default language' => 'vi']);

it('judges «the language reviewed» by the website’s language order, not by the order the change stores its languages', function (): void {
    $cast = rcCast();
    app(Tenancy::class)->enter(1, function () use ($cast): void {
        // the website lists Japanese before English; the change (no Vietnamese) holds both, a clash in English
        $about = rcNestedAboutChange($cast, ['ja', 'en'], ['ja' => 'about-ja', 'en' => 'about-us'], clashIn: 'en');
        $stored = array_keys(rcChange($about)->content['locales']);
        $card = Livewire::actingAs($cast['approver'])->test(ReviewChanges::class)->call('openChange', $about->ulid, 'approve')->call('confirmChange');

        // reviewed language = Japanese (first in the website's order), so the English clash is named; whatever the stored key order was
        expect($stored)->toContain('en')->toContain('ja')
            ->and($card->errors()->first('changes'))->toBe(__('entries.change_err_subpage_path_taken_lang', ['path' => '/en/about-us/team', 'lang' => Locales::label('en')]));
    });
});

/**
 * A live page «about» with a live sub-page «team» in vi + $locales (added to the website in that order),
 * a change by the author in $slugs' languages only (never Vietnamese), sent for review; a route of another
 * page sits where «team» would move in $clashIn. Returns the page.
 *
 * @param  list<string>  $locales
 * @param  array<string, string>  $slugs
 */
function rcNestedAboutChange(array $cast, array $locales, array $slugs, string $clashIn, string $slugPrefix = ''): Entry
{
    $category = Taxonomy::query()->where('vocabulary', 'post_cat')->first() ?? rcCollection();
    SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
    foreach ($locales as $locale) {
        if (! DB::connection('pgsql_admin')->table('site_locales')->where('site_id', 1)->where('locale', $locale)->exists()) {
            DB::connection('pgsql_admin')->table('site_locales')->insert([
                'site_id' => 1, 'locale' => $locale, 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
    $about = rcLivePost($category, $cast['author'], $cast['publisher'], $slugPrefix.'gioi-thieu');
    $about->update(['type' => 'page']);
    $team = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $cast['author']->id, 'parent_id' => $about->id]);
    $team->translations()->create(['locale' => 'vi', 'title' => 'Đội ngũ', 'slug' => $slugPrefix.'doi-ngu', 'status' => 'draft', 'body' => ['blocks' => []]]);
    app(PublishEntry::class)->handle($team->fresh(), 'vi', $cast['publisher']);
    foreach ($locales as $locale) {
        $about->translations()->create(['locale' => $locale, 'title' => 'About '.$locale, 'slug' => $slugPrefix.'about-'.$locale, 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($about->fresh(), $locale, $cast['publisher']);
        $team->translations()->create(['locale' => $locale, 'title' => 'Team '.$locale, 'slug' => 'team', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($team->fresh(), $locale, $cast['publisher']);
    }
    app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
    foreach ($slugs as $locale => $slug) {
        app(SaveEntryDraft::class)->handle($about->fresh(), $cast['author'], $locale, ['slug' => $slug]);
    }
    $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $cast['author']->id]);
    SiteRoute::query()->create(['locale' => $clashIn, 'path' => '/'.$slugs[$clashIn].'/team', 'entry_id' => $other->id]);
    $change = EntryDraft::query()->where('entry_id', $about->id)->firstOrFail();
    app(ApplyEntryDraftTransition::class)->handle($about->fresh(), array_key_first($slugs), $cast['author'], 'submit', $change->version());

    return $about->fresh();
}

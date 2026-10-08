<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Livewire\Entries\ReviewInbox;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\TaxonomyChangeRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Category-scoped write rights, enforced: a member limited to some categories (and all below them)
 * may change only posts whose PRIMARY category lies inside them, and no page — by every road: the
 * policy, the editor, pending changes and their approval, workflow moves, copies, bulk actions and
 * the category tree itself. Approvers so limited see and hear of only the posts inside their
 * limits. Unlimited members, owners and admins (role level ≥ 70) and platform super_admins are
 * unchanged, and limits hold while the plan feature is off (it is off in every test here).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    DB::connection('pgsql_admin')->statement('truncate audit_logs restart identity');
});

/** A member of site 1 holding a corporate template role (or an ad-hoc one with $permissions). */
function cwMember(string $role, ?int $level = null, ?array $permissions = null): User
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate')[$role] ?? null;
    $level ??= $template['level'] ?? 10;
    $permissions ??= ($template['permissions'] ?? []) === '*' ? PermissionRegistry::TENANT_PERMISSIONS : ($template['permissions'] ?? []);
    $user = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user, $role, $level, $permissions): void {
        $granted = Role::findOrCreate($role, PermissionRegistry::GUARD);
        $granted->forceFill(['level' => $level, 'is_system' => true])->save();
        $granted->syncPermissions($permissions);
        $user->assignRole($granted);
    });

    return $user->fresh() ?? $user;
}

function cwCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    return app(Tenancy::class)->enter(1, fn (): Taxonomy => app(CreateTaxonomy::class)->handle(
        'post_cat', $parent, new TaxonomyData(names: ['vi' => $name]),
    ));
}

/** A content section over $category (a workflow can then govern it). */
function cwSection(string $key, Taxonomy $category): void
{
    app(Tenancy::class)->enter(1, fn () => ContentSection::query()->create([
        'key' => $key, 'label' => $key, 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]));
}

function cwLimit(User $user, Taxonomy ...$categories): void
{
    app(Tenancy::class)->enter(1, function () use ($user, $categories): void {
        foreach ($categories as $category) {
            MemberCategoryScope::query()->create(['user_id' => $user->id, 'taxonomy_id' => $category->id]);
        }
        app(CategoryScopes::class)->forget();
    });
}

/** A post on site 1 filed under $categories (index 0 = primary), or a page when none are given. */
function cwEntry(?User $author, string $status = 'draft', Taxonomy ...$categories): Entry
{
    return app(Tenancy::class)->enter(1, function () use ($author, $status, $categories): Entry {
        $slug = 'bai-'.Str::lower(Str::random(8));
        $entry = Entry::query()->create([
            'type' => $categories === [] ? 'page' : 'post', 'status' => $status, 'author_id' => $author?->id, 'meta' => [],
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $entry->translations()->create([
            'locale' => 'vi', 'title' => 'Bài '.$slug, 'slug' => $slug, 'status' => $status === 'published' ? 'published' : 'draft',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung']]]],
        ]);
        if ($categories !== []) {
            $entry->assignCategories(array_map(fn (Taxonomy $c): int => $c->id, $categories));
        }

        return $entry->fresh() ?? $entry;
    });
}

/** @return list<int> the entry's categories in order (facet terms excluded) */
function cwCategoriesOf(Entry $entry): array
{
    return app(Tenancy::class)->enter(1, fn (): array => array_map('intval', DB::table('entry_taxonomies')
        ->where('entry_id', $entry->id)->where('position', '<', Entry::FACET_POSITION_BASE)->orderBy('position')->pluck('taxonomy_id')->all()));
}

/**
 * The next time the entry's row is locked, re-file it under $category first — as if someone else
 * did it while the caller waited for the lock.
 */
function cwRefileOnLock(Entry $entry, Taxonomy $category): void
{
    $done = false;
    DB::listen(function ($query) use (&$done, $entry, $category): void {
        if (! $done && str_contains($query->sql, 'for update') && str_contains($query->sql, '"entries"')) {
            $done = true;
            DB::table('entry_taxonomies')->where('entry_id', $entry->id)->where('position', 0)->update(['taxonomy_id' => $category->id]);
        }
    });
}

function cwStatus(Entry $entry): ?string
{
    return app(Tenancy::class)->enter(1, fn (): ?string => Entry::withTrashed()->whereKey($entry->id)->value('status'));
}

/** Run $call on site 1; the reason a category action was refused, `denied` for an authorization refusal, or null. */
function cwRefusal(Closure $call): ?string
{
    try {
        app(Tenancy::class)->enter(1, $call);
    } catch (TaxonomyChangeRejectedException $e) {
        return $e->reason;
    } catch (AuthorizationException) {
        return 'denied';
    }

    return null;
}

/** Jobs (Tuyển dụng) with IT below it, and News (Tin tức) beside it. */
function cwTree(): array
{
    $jobs = cwCategory('Tuyển dụng');
    $it = cwCategory('Kỹ thuật', $jobs);
    $news = cwCategory('Tin tức');

    return [$jobs, $it, $news];
}

// ── The policy ────────────────────────────────────────────────────────────────────────────────

it('lets a limited member change only posts filed inside their limits, and no page', function (): void {
    [$jobs, $it, $news] = cwTree();
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $inside = cwEntry($lan, 'draft', $it);
    $outside = cwEntry($lan, 'draft', $news);
    $page = cwEntry($lan);

    $answers = app(Tenancy::class)->enter(1, fn (): array => array_map(
        fn (Entry $entry): array => array_map(
            fn (string $ability): bool => $lan->can($ability, $entry),
            ['update', 'delete', 'restore', 'forceDelete', 'submit', 'publish', 'view'],
        ),
        [$inside, $outside, $page],
    ));

    expect($answers)->toBe([
        [true, true, true, true, true, true, true],
        [false, false, false, false, false, false, true],     // viewing is never limited
        [false, false, false, false, false, false, true],
    ]);
});

it('leaves unlimited members, owners, admins and super_admins as they were, rows or not', function (): void {
    [$jobs, , $news] = cwTree();
    $editor = cwMember('editor');
    $owner = cwMember('owner');
    $admin = cwMember('admin');
    $staff = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole('super_admin'));
    cwLimit($owner, $jobs);
    cwLimit($admin, $jobs);
    cwLimit($staff, $jobs);
    $outside = cwEntry(null, 'draft', $news);
    $page = cwEntry(null);

    $answers = app(Tenancy::class)->enter(1, fn (): array => array_map(
        fn (User $user): array => [$user->can('update', $outside), $user->can('publish', $outside), $user->can('delete', $page)],
        [$editor, $owner, $admin, $staff->fresh() ?? $staff],
    ));

    expect($answers)->toBe(array_fill(0, 4, [true, true, true]));
});

it('refuses the status roads outside the limits: publish, send, draft, private, schedule', function (): void {
    [$jobs, , $news] = cwTree();
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $draft = cwEntry($lan, 'draft', $news);
    $live = cwEntry($lan, 'published', $news);

    $refusals = [
        cwRefusal(fn () => app(PublishEntry::class)->handle($draft, 'vi', $lan)),
        cwRefusal(fn () => app(TransitionEntryStatus::class)->handle($live, EntryStatus::Draft, $lan)),
        cwRefusal(fn () => app(TransitionEntryStatus::class)->handle($live, EntryStatus::Private, $lan)),
        cwRefusal(fn () => app(TransitionEntryStatus::class)->handle($draft, EntryStatus::Scheduled, $lan, now()->addDay())),
    ];

    expect($refusals)->toBe(['denied', 'denied', 'denied', 'denied'])
        ->and(cwStatus($draft))->toBe('draft')
        ->and(cwStatus($live))->toBe('published');
});

// ── The editor ────────────────────────────────────────────────────────────────────────────────

it('opens the editor for a new post but saves it only inside the limits, saying where', function (): void {
    [$jobs, $it, $news] = cwTree();
    cwSection('tin-tuc', $news);
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    app()->setLocale('vi');

    app(Tenancy::class)->enter(1, function () use ($lan, $it, $news): void {
        $editor = Livewire::actingAs($lan)->test(Editor::class, ['section' => 'tin-tuc'])
            ->assertSet('categoryId', $news->id)
            ->set('title', 'Tuyển kỹ sư')
            ->set('slug', 'tuyen-ky-su')
            ->call('save')
            ->assertHasErrors(['categoryId']);

        expect($editor->errors()->first('categoryId'))->toBe('Bạn chỉ được viết bài trong: Tuyển dụng.')
            ->and(Entry::query()->count())->toBe(0);

        $editor->set('categoryId', $it->id)->call('save')->assertHasNoErrors();
        expect(Entry::query()->sole()->primaryCategory()?->id)->toBe($it->id);
    });
});

it('refuses a limited member a new page, and an existing post outside the limits', function (): void {
    [$jobs, , $news] = cwTree();
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $outside = cwEntry(null, 'draft', $news);

    app(Tenancy::class)->enter(1, function () use ($lan, $outside): void {
        Livewire::actingAs($lan)->test(Editor::class)
            ->assertSet('type', 'page')
            ->set('title', 'Giới thiệu')
            ->set('slug', 'gioi-thieu')
            ->call('save')
            ->assertForbidden();

        Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $outside->ulid])->assertForbidden();

        expect(Entry::query()->where('type', 'page')->count())->toBe(0);
    });
});

it('refuses to move a post outside the limits and keeps the categories outside them it already has', function (): void {
    [$jobs, $it, $news] = cwTree();
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $post = cwEntry($lan, 'draft', $jobs, $news);   // primary inside, a second category outside

    app(Tenancy::class)->enter(1, function () use ($lan, $post, $it, $news): void {
        $editor = Livewire::actingAs($lan)->test(Editor::class, ['ulid' => $post->ulid]);
        $editor->set('categoryId', $news->id)->call('save')->assertHasErrors(['categoryId']);
        $editor->set('categoryId', $it->id)->call('save')->assertHasNoErrors();
    });

    expect(cwCategoriesOf($post))->toBe([$it->id, $news->id]);
});

it('still lets an unlimited member re-file a post wherever, dropping its other categories as before', function (): void {
    [$jobs, , $news] = cwTree();
    $editor = cwMember('editor');
    $post = cwEntry($editor, 'draft', $jobs, $news);

    app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
        ->set('categoryId', $news->id)->call('save')->assertHasNoErrors());

    expect(cwCategoriesOf($post))->toBe([$news->id]);
});

// ── Pending changes ───────────────────────────────────────────────────────────────────────────

it('refuses a pending change that files the post outside the limits', function (): void {
    [$jobs, $it, $news] = cwTree();
    $lan = cwMember('author');
    cwLimit($lan, $jobs);
    $post = cwEntry($lan, 'published', $jobs);

    expect(cwRefusal(fn () => app(SaveEntryDraft::class)->handle($post, $lan, 'vi', [], ['primary_category_id' => $news->id])))->toBe('denied')
        ->and(cwRefusal(fn () => app(SaveEntryDraft::class)->handle($post, $lan, 'vi', ['title' => 'Mới'], ['primary_category_id' => $it->id])))->toBeNull()
        ->and(app(Tenancy::class)->enter(1, fn () => EntryDraft::query()->sole()->content['shared']['primary_category_id']))->toBe($it->id);
});

it('lets a publisher put a change online only when both the post and the change\'s category are inside their limits', function (): void {
    [$jobs, , $news] = cwTree();
    $author = cwMember('author');
    $limited = cwMember('editor');
    $publisher = cwMember('editor');
    cwLimit($limited, $jobs);
    $post = cwEntry($author, 'published', $jobs);
    $change = app(Tenancy::class)->enter(1, fn (): EntryDraft => app(SaveEntryDraft::class)->handle($post, $author, 'vi', ['title' => 'Chuyển mục'], ['primary_category_id' => $news->id]));

    expect(cwRefusal(fn () => app(ApplyEntryDraft::class)->handle($post, $limited, $change->version())))->toBe('denied')
        ->and(cwCategoriesOf($post))->toBe([$jobs->id])
        ->and(cwRefusal(fn () => app(ApplyEntryDraft::class)->handle($post, $publisher, $change->version())))->toBeNull()
        ->and(cwCategoriesOf($post))->toBe([$news->id]);
});

it('lets an approver move a change on only when both the post and the change\'s category are inside their limits', function (): void {
    [$jobs, $it] = cwTree();
    $hr = cwCategory('Nhân sự', $jobs);
    cwSection('tuyen-dung', $jobs);
    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tuyen-dung', active: true));
    $author = cwMember('author');
    $itOnly = cwMember('editor');
    $hrOnly = cwMember('editor');
    $approver = cwMember('editor');
    cwLimit($itOnly, $it);
    cwLimit($hrOnly, $hr);
    $post = cwEntry($author, 'published', $it);

    $change = app(Tenancy::class)->enter(1, function () use ($post, $author, $hr): EntryDraft {
        $change = app(SaveEntryDraft::class)->handle($post, $author, 'vi', ['title' => 'Sang nhân sự'], ['primary_category_id' => $hr->id]);
        app(ApplyEntryDraftTransition::class)->handle($post, 'vi', $author, 'submit', $change->version());

        return $change->fresh() ?? $change;
    });
    $approve = fn (User $who) => cwRefusal(fn () => app(ApplyEntryDraftTransition::class)->handle($post, 'vi', $who, 'approve', $change->version()));
    $return = fn (User $who) => cwRefusal(fn () => app(ApplyEntryDraftTransition::class)->handle($post, 'vi', $who, 'reject', $change->version(), 'Xem lại'));

    expect($return($itOnly))->toBe('denied')      // a move that writes nothing is refused too
        ->and($approve($itOnly))->toBe('denied')  // the target lies outside
        ->and($approve($hrOnly))->toBe('denied')  // the post lies outside
        ->and(cwCategoriesOf($post))->toBe([$it->id])
        ->and($approve($approver))->toBeNull()
        ->and(cwCategoriesOf($post))->toBe([$hr->id]);
});

// ── Workflow moves, approvers and the inbox ───────────────────────────────────────────────────

it('refuses a limited approver every move on a post outside their limits, and leaves them out of the queue', function (): void {
    [$jobs, , $news] = cwTree();
    cwSection('tin-tuc', $news);
    cwSection('tuyen-dung', $jobs);
    app(Tenancy::class)->enter(1, function (): void {
        app(InstantiateWorkflow::class)->handle('editorial', 'tin-tuc', active: true);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'tuyen-dung', active: true);
    });
    $author = cwMember('author');
    $hrHead = cwMember('editor');
    $chief = cwMember('editor');
    cwLimit($hrHead, $jobs);
    $newsPost = cwEntry($author, 'draft', $news);
    $jobsPost = cwEntry($author, 'draft', $jobs);

    app(Tenancy::class)->enter(1, function () use ($author, $newsPost, $jobsPost): void {
        app(ApplyEntryWorkflowTransition::class)->handle($newsPost, 'vi', $author, 'submit');
        app(ApplyEntryWorkflowTransition::class)->handle($jobsPost, 'vi', $author, 'submit');
    });

    $notified = fn (User $who): array => app(Tenancy::class)->enter(1, fn (): array => SiteNotification::query()
        ->where('user_id', $who->id)->where('kind', 'workflow.pending_review')->pluck('data')->map(fn (array $d): string => $d['entry'])->sort()->values()->all());
    $approvers = app(Tenancy::class)->enter(1, function () use ($newsPost): array {
        $resolver = app(EntryWorkflowResolver::class);
        $entry = Entry::query()->findOrFail($newsPost->id);
        $workflow = $resolver->forEntry($entry);

        return $resolver->membersWhoMayApply($workflow->transitions->where('key', 'to_review'), $entry)?->pluck('id')->all() ?? [];
    });
    $inbox = fn (User $who): array => app(Tenancy::class)->enter(1, fn (): array => collect(
        Livewire::actingAs($who)->test(ReviewInbox::class)->viewData('rows'),
    )->pluck('ulid')->sort()->values()->all());

    $newsMove = fn (string $key) => cwRefusal(fn () => app(ApplyEntryWorkflowTransition::class)->handle(
        Entry::query()->findOrFail($newsPost->id), 'vi', $hrHead, $key, 'Chưa ổn',
    ));

    expect($newsMove('to_review'))->toBe('denied')
        ->and($newsMove('request_changes'))->toBe('denied')
        ->and(cwStatus($newsPost))->toBe('pending')
        ->and($approvers)->toContain($chief->id)->not->toContain($hrHead->id)
        ->and($notified($hrHead))->toBe([$jobsPost->ulid])
        ->and($notified($chief))->toBe(collect([$newsPost->ulid, $jobsPost->ulid])->sort()->values()->all())
        ->and($inbox($hrHead))->toBe([$jobsPost->ulid])
        ->and(cwRefusal(fn () => app(ApplyEntryWorkflowTransition::class)->handle(Entry::query()->findOrFail($jobsPost->id), 'vi', $hrHead, 'approve')))->toBeNull()
        ->and(cwStatus($jobsPost))->toBe('published');
});

// ── Copies and the list ───────────────────────────────────────────────────────────────────────

it('copies only posts inside the limits, keeping only the categories inside them', function (): void {
    [$jobs, , $news] = cwTree();
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $outside = cwEntry(null, 'draft', $news);
    $inside = cwEntry(null, 'draft', $jobs, $news);

    $copy = null;
    expect(cwRefusal(fn () => app(DuplicateEntry::class)->handle($outside, $lan)))->toBe('denied')
        ->and(cwRefusal(function () use ($inside, $lan, &$copy): void {
            $copy = app(DuplicateEntry::class)->handle($inside, $lan);
        }))->toBeNull()
        ->and(cwCategoriesOf($copy))->toBe([$jobs->id]);
});

it('acts in bulk only on the rows inside the limits and marks each row for the screen', function (): void {
    [$jobs, , $news] = cwTree();
    cwSection('tin-tuc', $news);
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $inside = cwEntry($lan, 'draft', $jobs);
    $outside = cwEntry($lan, 'draft', $news);
    app()->setLocale('vi');

    app(Tenancy::class)->enter(1, function () use ($lan, $inside, $outside): void {
        $list = Livewire::actingAs($lan)->test(Index::class, ['section' => 'tin-tuc']);
        expect($list->viewData('rowCovered'))->toBe([$outside->ulid => false])
            ->and($list->viewData('scopeRefusal'))->toBe('Bạn chỉ được viết bài trong: Tuyển dụng.');

        $list->set('selected', [$inside->ulid, $outside->ulid])->call('bulkTrash');
    });

    expect(app(Tenancy::class)->enter(1, fn () => Entry::onlyTrashed()->pluck('id')->all()))->toBe([$inside->id]);
});

// ── The category tree ─────────────────────────────────────────────────────────────────────────

it('keeps a limited manager\'s category changes inside their limits, source and destination', function (): void {
    [$jobs, $it, $news] = cwTree();
    $hr = cwCategory('Nhân sự', $jobs);
    $lan = cwMember('editor');   // the editor template holds taxonomies.manage
    cwLimit($lan, $jobs);
    $this->actingAs($lan);
    $names = fn (string $name) => new TaxonomyData(names: ['vi' => $name]);

    expect(cwRefusal(fn () => app(CreateTaxonomy::class)->handle('post_cat', null, $names('Mới'))))->toBe('outside_category_limits')
        ->and(cwRefusal(fn () => app(CreateTaxonomy::class)->handle('post_cat', $news, $names('Mới'))))->toBe('outside_category_limits')
        ->and(cwRefusal(fn () => app(UpdateTaxonomy::class)->handle($news, $names('Tin'))))->toBe('outside_category_limits')
        ->and(cwRefusal(fn () => app(MoveTaxonomy::class)->handle($news, $jobs, 0)))->toBe('outside_category_limits')   // into the limits
        ->and(cwRefusal(fn () => app(MoveTaxonomy::class)->handle($it, $news, 0)))->toBe('outside_category_limits')     // out of them
        ->and(cwRefusal(fn () => app(MoveTaxonomy::class)->handle($jobs, null, 1)))->toBe('outside_category_limits')    // the limit itself
        ->and(cwRefusal(fn () => app(DeleteTaxonomy::class)->handle($news)))->toBe('outside_category_limits')
        ->and(cwRefusal(fn () => app(DeleteTaxonomy::class)->handle($jobs)))->toBe('outside_category_limits')
        ->and(cwRefusal(fn () => app(DeleteTaxonomy::class)->handle($hr, CategoryItemsOnDelete::Reassign, $news)))->toBe('outside_category_limits');

    expect(cwRefusal(fn () => app(CreateTaxonomy::class)->handle('post_cat', $jobs, $names('Thực tập'))))->toBeNull()
        ->and(cwRefusal(fn () => app(UpdateTaxonomy::class)->handle($it, $names('Kỹ thuật số'))))->toBeNull()
        ->and(cwRefusal(fn () => app(MoveTaxonomy::class)->handle($it, $hr, 0)))->toBeNull()
        ->and(cwRefusal(fn () => app(DeleteTaxonomy::class)->handle($it)))->toBeNull()
        ->and(app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->whereKey($news->id)->value('parent_id')))->toBeNull();
});

it('leaves an unlimited manager free to reshape the tree', function (): void {
    [$jobs, , $news] = cwTree();
    $this->actingAs(cwMember('editor'));

    expect(cwRefusal(fn () => app(MoveTaxonomy::class)->handle($news, $jobs, 0)))->toBeNull()
        ->and(app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->whereKey($news->id)->value('parent_id')))->toBe($jobs->id);
});

// ── Query budget ──────────────────────────────────────────────────────────────────────────────

it('lists posts for a limited member on a flat query budget', function (): void {
    [$jobs, $it, $news] = cwTree();
    cwSection('tin-tuc', $news);
    $lan = cwMember('editor');
    $chief = cwMember('editor');
    cwLimit($lan, $jobs);
    foreach (range(1, 6) as $i) {
        cwEntry($lan, 'draft', $news);
        cwEntry($lan, 'draft', $news, $it);
    }

    // Each measured request starts as a fresh one would: nothing remembered from the last.
    $list = function (User $who): int {
        app()->forgetScopedInstances();

        return countQueries(fn () => $this->actingAs($who)->get('http://a.test/admin/content?section=tin-tuc')->assertOk());
    };
    $list($chief);   // warm each member's caches (permissions, preferences) first
    $list($lan);
    $unlimited = $list($chief);
    $limited = $list($lan);

    // A member without limits pays nothing for them (the list learns it from its own query). A
    // limited one adds a flat five — their limits, their role level, the rows' primary categories,
    // the names in the refusal sentence and the categories without a path — never one per row.
    expect($unlimited)->toBeLessThanOrEqual(16)
        ->and($limited)->toBeLessThanOrEqual($unlimited + 5);
});

it('opens a post in the editor for a limited member on a flat query budget', function (): void {
    [$jobs, $it] = cwTree();
    $lan = cwMember('editor');
    $chief = cwMember('editor');
    cwLimit($lan, $jobs);
    $post = cwEntry($lan, 'draft', $it);

    $open = function (User $who) use ($post): int {
        app()->forgetScopedInstances();

        return countQueries(fn () => $this->actingAs($who)->get("http://a.test/admin/content/{$post->ulid}/edit")->assertOk());
    };
    $open($chief);   // warm each member's caches (permissions, preferences) first
    $open($lan);
    $unlimited = $open($chief);
    $limited = $open($lan);

    expect($limited)->toBeLessThanOrEqual($unlimited + 4);
});

// ── Review follow-ups ─────────────────────────────────────────────────────────────────────────

it('clears the workflow stage when a post in a governed sub-category goes back to draft in bulk, limited or not', function (): void {
    [$jobs, $it] = cwTree();
    cwSection('tuyen-dung', $jobs);
    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tuyen-dung', active: true));
    $lan = cwMember('editor');
    $chief = cwMember('editor');
    cwLimit($lan, $jobs);

    $stages = [];
    foreach ([$lan, $chief] as $who) {
        $post = cwEntry($who, 'published', $it);
        app(Tenancy::class)->enter(1, fn () => Entry::query()->whereKey($post->id)->update(['workflow_state' => 'published']));
        app()->forgetScopedInstances();   // a fresh request: nothing resolved for this category yet

        app(Tenancy::class)->enter(1, fn () => Livewire::actingAs($who)->test(Index::class, ['section' => 'tuyen-dung'])
            ->set('selected', [$post->ulid])->call('bulkDraft'));

        $stages[] = app(Tenancy::class)->enter(1, fn (): array => Entry::query()->whereKey($post->id)->first(['status', 'workflow_state'])?->only(['status', 'workflow_state']) ?? []);
    }

    expect($stages)->toBe([['status' => 'draft', 'workflow_state' => null], ['status' => 'draft', 'workflow_state' => null]]);
});

it('lets a category move change a limited member\'s reach only for someone who could set that limit', function (): void {
    [$jobs, $it, $news] = cwTree();
    $events = cwCategory('Sự kiện');
    $lan = cwMember('author');
    cwLimit($lan, $jobs);
    $manager = cwMember('editor');   // taxonomies.manage, no users.manage
    $owner = cwMember('owner');

    $this->actingAs($manager);
    $into = cwRefusal(fn () => app(MoveTaxonomy::class)->handle($news, $jobs, 0));     // Tin tức would come under Lan's limit
    $outOf = cwRefusal(fn () => app(MoveTaxonomy::class)->handle($it, null, 0));       // Kỹ thuật would leave it
    $nobody = cwRefusal(fn () => app(MoveTaxonomy::class)->handle($events, $news, 0)); // nobody's reach changes

    $this->actingAs($owner);
    $byOwner = cwRefusal(fn () => app(MoveTaxonomy::class)->handle($news, $jobs, 0));

    expect([$into, $outOf, $nobody, $byOwner])->toBe(['changes_member_limits', 'changes_member_limits', null, null])
        ->and(app(Tenancy::class)->enter(1, fn () => Taxonomy::query()->whereKey($news->id)->value('parent_id')))->toBe($jobs->id);
});

it('adds no query to the posts list of a member who is not limited', function (): void {
    [, , $news] = cwTree();
    cwSection('tin-tuc', $news);
    $chief = cwMember('editor');
    foreach (range(1, 4) as $i) {
        cwEntry($chief, 'draft', $news);
    }
    $this->actingAs($chief)->get('http://a.test/admin/content?section=tin-tuc')->assertOk();   // warm the caches
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($chief)->get('http://a.test/admin/content?section=tin-tuc')->assertOk();
    $queries = array_map(fn (array $q): string => $q['query'], DB::getQueryLog());
    DB::disableQueryLog();

    // Neither the reader's own query nor the rows' primary categories: the list learns that this
    // member has no limits from its own entries query.
    expect(array_values(array_filter($queries, fn (string $q): bool => str_contains($q, 'root_id') || str_contains($q, '"taxonomies"."path"'))))->toBe([])
        ->and(count($queries))->toBeLessThanOrEqual(19);
});

it('re-checks the limits on the locked row before saving a pending change', function (): void {
    [$jobs, , $news] = cwTree();
    $lan = cwMember('author');
    cwLimit($lan, $jobs);
    $post = cwEntry($lan, 'published', $jobs);
    app(Tenancy::class)->enter(1, fn () => app(SaveEntryDraft::class)->handle($post, $lan, 'vi', ['title' => 'Lần một']));

    cwRefileOnLock($post, $news);   // someone re-files the post while this save waits for the row

    expect(cwRefusal(fn () => app(SaveEntryDraft::class)->handle(Entry::query()->findOrFail($post->id), $lan, 'vi', ['title' => 'Lần hai'])))->toBe('denied')
        ->and(app(Tenancy::class)->enter(1, fn () => EntryDraft::query()->sole()->content['locales']['vi']['title']))->toBe('Lần một');
});

it('re-checks the limits on the locked row before putting a change online', function (): void {
    [$jobs, $it, $news] = cwTree();
    $author = cwMember('author');
    $lan = cwMember('editor');
    cwLimit($lan, $jobs);
    $post = cwEntry($author, 'published', $jobs);
    $change = app(Tenancy::class)->enter(1, fn (): EntryDraft => app(SaveEntryDraft::class)->handle($post, $author, 'vi', ['title' => 'Mới'], ['primary_category_id' => $it->id]));

    cwRefileOnLock($post, $news);

    expect(cwRefusal(fn () => app(ApplyEntryDraft::class)->handle(Entry::query()->findOrFail($post->id), $lan, $change->version())))->toBe('denied')
        ->and(cwCategoriesOf($post))->toBe([$jobs->id]);   // refused whole: the change's category was not written
});

it('lists as hand-off candidates for a pending change only members whose limits reach its new category too', function (): void {
    [$jobs, $it] = cwTree();
    $hr = cwCategory('Nhân sự', $jobs);
    cwSection('tuyen-dung', $jobs);
    app(Tenancy::class)->enter(1, fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'tuyen-dung', active: true));
    $author = cwMember('author');
    $itOnly = cwMember('editor');
    $chief = cwMember('editor');
    cwLimit($itOnly, $it);
    $post = cwEntry($author, 'published', $it);

    $ids = app(Tenancy::class)->enter(1, function () use ($post, $author, $hr): array {
        $change = app(SaveEntryDraft::class)->handle($post, $author, 'vi', ['title' => 'Sang nhân sự'], ['primary_category_id' => $hr->id]);
        $resolver = app(EntryWorkflowResolver::class);
        $entry = Entry::query()->findOrFail($post->id);
        $workflow = $resolver->forEntry($entry);

        return $resolver->membersWhoMayApply($workflow->transitions->where('key', 'approve'), $entry, $change)?->pluck('id')->all() ?? [];
    });

    expect($ids)->toContain($chief->id)->not->toContain($itOnly->id);
});

it('refuses a limited member every list action on a post outside their limits, and allows an unlimited one', function (): void {
    [$jobs, , $news] = cwTree();
    cwSection('tin-tuc', $news);
    $lan = cwMember('editor');
    $chief = cwMember('editor');
    cwLimit($lan, $jobs);

    $run = function (User $who, int $answer) use ($news): array {
        $draft = cwEntry(null, 'draft', $news);
        $live = cwEntry(null, 'published', $news);
        $quick = cwEntry(null, 'draft', $news);
        $binned = cwEntry(null, 'draft', $news);
        app(Tenancy::class)->enter(1, fn () => Entry::query()->whereKey($binned->id)->first()?->delete());

        return app(Tenancy::class)->enter(1, function () use ($who, $answer, $draft, $live, $quick, $binned): array {
            $list = fn () => Livewire::actingAs($who)->test(Index::class, ['section' => 'tin-tuc']);
            $list()->set('selected', [$draft->ulid])->call('bulkPublish');
            $list()->set('selected', [$live->ulid])->call('bulkDraft');
            $list()->call('saveTitle', $quick->ulid, 'Tên mới')->assertStatus($answer);
            $list()->set('tab', 'trash')->call('restore', $binned->ulid)->assertStatus($answer);

            return [
                Entry::query()->whereKey($draft->id)->value('status'),
                Entry::query()->whereKey($live->id)->value('status'),
                Entry::query()->whereKey($quick->id)->first()?->translationFor('vi')?->title === 'Tên mới',
                Entry::withTrashed()->whereKey($binned->id)->first()?->trashed(),
            ];
        });
    };

    expect($run($lan, 403))->toBe(['draft', 'published', false, true])
        ->and($run($chief, 200))->toBe(['published', 'draft', true, false]);
});

it('takes each member\'s limits lock before moving their limits onto a merge target', function (): void {
    [$jobs, $it] = cwTree();
    $lan = cwMember('author');
    cwLimit($lan, $it);
    $this->actingAs(cwMember('owner'));

    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $locks[] = (string) ($query->bindings[0] ?? '');
        }
    });

    expect(cwRefusal(fn () => app(DeleteTaxonomy::class)->handle($it, CategoryItemsOnDelete::Reassign, $jobs)))->toBeNull()
        ->and($locks)->toContain('category_scopes:1:'.$lan->id);
});

it('does not count members who are never limited against someone merging their old limit', function (): void {
    [$jobs, $it] = cwTree();
    $lan = cwMember('author');
    $otherAdmin = cwMember('admin');
    cwLimit($lan, $it);
    cwLimit($otherAdmin, $it);   // left over, ignored: an admin is never limited
    $this->actingAs(cwMember('admin'));

    expect(cwRefusal(fn () => app(DeleteTaxonomy::class)->handle($it, CategoryItemsOnDelete::Reassign, $jobs)))->toBeNull();
});

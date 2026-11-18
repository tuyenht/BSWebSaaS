<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * Publishing and taking content offline (publish, draft, private, schedule, applying a pending
 * change, the bulk bar) is open only on an entry the member may also edit: the publish right plus
 * edit.any, or edit.own on their own entry, inside their category limits. A post not saved yet is
 * the writer's own. The "waiting for review" notice reaches only publishers who could act on it.
 * The shipped roles keep exactly what they had, and a workflow's approvers — who approve, not
 * edit — still move content through the workflow.
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement('truncate audit_logs, site_notifications restart identity');
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * A custom role holding the publish right and edit.own, but not edit.any.
 *
 * @return list<string>
 */
function pneOwnPublisher(): array
{
    return ['entries.view', 'entries.create', 'entries.edit.own', 'entries.publish'];
}

function pneEntry(?User $author, string $title, string $status = 'published'): Entry
{
    $entry = Entry::query()->create([
        'type' => 'page', 'status' => $status, 'author_id' => $author?->id,
        'published_at' => $status === 'published' ? now() : null,
    ]);
    $translation = $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
        'status' => $status, 'body' => ['blocks' => []],
    ]);
    if ($status === 'published') {
        app(RouteSync::class)->sync($entry, 'vi', $translation->slug);
    }

    return $entry->fresh();
}

/** A fresh user holding the shipped corporate role $name on site 1 (call inside site 1). */
function pnePreset(string $name): User
{
    $template = PermissionRegistry::tenantRoleTemplates('corporate')[$name];
    $role = Role::findOrCreate($name, PermissionRegistry::GUARD);
    $role->forceFill(['level' => $template['level'], 'is_system' => true])->save();
    $role->syncPermissions($template['permissions'] === '*' ? PermissionRegistry::TENANT_PERMISSIONS : $template['permissions']);
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

/** @return list<string> */
function pneNotices(User $user): array
{
    return SiteNotification::query()->where('user_id', $user->id)->pluck('kind')->all();
}

it('lets a publisher without edit.any publish or take offline only their own entry, and a new one', function (): void {
    $publisher = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $colleague): void {
        grantRole($publisher, 'own-publisher', pneOwnPublisher());
        $mine = pneEntry($publisher, 'Của tôi');
        $theirs = pneEntry($colleague, 'Của đồng nghiệp');
        $orphan = pneEntry(null, 'Không người viết');

        $p = $publisher->fresh();
        expect($p->can('publish', $mine))->toBeTrue()
            ->and($p->can('publish', $theirs))->toBeFalse()
            ->and($p->can('publish', $orphan))->toBeFalse()
            // Not saved yet: it will be theirs, so it is judged as theirs.
            ->and($p->can('publish', new Entry(['type' => 'page', 'author_id' => $p->id])))->toBeTrue();
    });
});

it("refuses the list's status menu and schedule on someone else's entry, and allows them on one's own", function (): void {
    $publisher = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $colleague): void {
        grantRole($publisher, 'own-publisher', pneOwnPublisher());
        $theirsLive = pneEntry($colleague, 'Đang chạy của đồng nghiệp');
        $theirsDraft = pneEntry($colleague, 'Nháp của đồng nghiệp', 'draft');
        $mineDraft = pneEntry($publisher, 'Nháp của tôi', 'draft');
        $as = fn () => Livewire::actingAs($publisher->fresh())->test(Index::class);

        // A forged call on a colleague's row: the reason in a toast that stays, the row untouched.
        $refused = __('entries.refused_status');
        $as()->call('changeStatus', $theirsLive->ulid, 'draft')->assertOk()->assertDispatched('bs-toast', text: $refused, stay: true);
        $as()->call('changeStatus', $theirsLive->ulid, 'private')->assertOk()->assertDispatched('bs-toast', text: $refused, stay: true);
        $as()->call('changeStatus', $theirsDraft->ulid, 'published')->assertOk()->assertDispatched('bs-toast', text: $refused, stay: true);
        $as()->call('scheduleEntry', $theirsDraft->ulid, now()->addDay()->format('Y-m-d\TH:i'))->assertOk()->assertDispatched('bs-toast', text: $refused, stay: true);

        expect($theirsLive->fresh()->status)->toBe('published')
            ->and($theirsDraft->fresh()->status)->toBe('draft')
            ->and($theirsDraft->fresh()->published_at)->toBeNull();

        $as()->call('changeStatus', $mineDraft->ulid, 'published')->assertDispatched('bs-toast', text: __('entries.status_changed'));
        expect($mineDraft->fresh()->status)->toBe('published');

        $as()->call('changeStatus', $mineDraft->ulid, 'draft')->assertDispatched('bs-toast', text: __('entries.status_changed'));
        expect($mineDraft->fresh()->status)->toBe('draft');
    });
});

it("skips someone else's rows in the bulk bar's publish and take-offline", function (): void {
    $publisher = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $colleague): void {
        grantRole($publisher, 'own-publisher', pneOwnPublisher());
        $mineDraft = pneEntry($publisher, 'Nháp của tôi', 'draft');
        $theirsDraft = pneEntry($colleague, 'Nháp của đồng nghiệp', 'draft');
        $mineLive = pneEntry($publisher, 'Đang chạy của tôi');
        $theirsLive = pneEntry($colleague, 'Đang chạy của đồng nghiệp');

        Livewire::actingAs($publisher->fresh())->test(Index::class)
            ->set('selected', [$mineDraft->ulid, $theirsDraft->ulid])
            ->call('bulkPublish')
            ->assertDispatched('bs-toast', text: __('entries.bulk_published', ['count' => 1]).' '.__('entries.bulk_skipped', ['count' => 1]));

        Livewire::actingAs($publisher->fresh())->test(Index::class)
            ->set('selected', [$mineLive->ulid, $theirsLive->ulid])
            ->call('bulkDraft')
            ->assertDispatched('bs-toast', text: __('entries.bulk_drafted', ['count' => 1]).' '.__('entries.bulk_skipped', ['count' => 1]));

        expect($mineDraft->fresh()->status)->toBe('published')
            ->and($theirsDraft->fresh()->status)->toBe('draft')
            ->and($mineLive->fresh()->status)->toBe('draft')
            ->and($theirsLive->fresh()->status)->toBe('published');
    });
});

it("refuses a straight publish of someone else's entry, whoever calls the action", function (): void {
    $publisher = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $colleague): void {
        grantRole($publisher, 'own-publisher', [...pneOwnPublisher(), 'entries.submit']);
        $theirs = pneEntry($colleague, 'Nháp của đồng nghiệp', 'draft');

        // Not publishable, and not theirs to send for review either.
        expect(fn () => app(PublishEntry::class)->handle($theirs, 'vi', $publisher->fresh()))->toThrow(AuthorizationException::class)
            ->and($theirs->fresh()->status)->toBe('draft');
    });
});

it("lets the editor publish a brand-new entry, but not open someone else's", function (): void {
    $publisher = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $colleague): void {
        grantRole($publisher, 'own-publisher', pneOwnPublisher());
        $theirs = pneEntry($colleague, 'Của đồng nghiệp');

        Livewire::actingAs($publisher->fresh())->test(Editor::class, ['ulid' => $theirs->ulid])->assertForbidden();

        Livewire::actingAs($publisher->fresh())->test(Editor::class)
            ->assertSet('mayPublish', true)
            ->set('title', 'Trang mới')
            ->set('slug', 'trang-moi')
            ->call('addBlock', 'hero')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('status', 'published');

        $entry = Entry::query()->where('author_id', $publisher->id)->sole();
        expect($entry->status)->toBe('published');
    });
});

it("refuses to apply a pending change to someone else's live entry, and applies one to one's own", function (): void {
    $publisher = User::factory()->create();
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher, $author): void {
        grantRole($publisher, 'own-publisher', pneOwnPublisher());
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        $theirs = pneEntry($author, 'Bài của tác giả');
        $mine = pneEntry($publisher, 'Bài của tôi');

        $save = app(SaveEntryDraft::class);
        $apply = app(ApplyEntryDraft::class);
        $theirChange = $save->handle($theirs, $author->fresh(), 'vi', ['title' => 'Tác giả sửa']);
        $myChange = $save->handle($mine, $publisher->fresh(), 'vi', ['title' => 'Tôi sửa']);

        expect(fn () => $apply->handle($theirs, $publisher->fresh(), $theirChange->version()))->toThrow(AuthorizationException::class)
            ->and($theirs->fresh()->translationFor('vi')->title)->toBe('Bài của tác giả')
            ->and($apply->handle($mine, $publisher->fresh(), $myChange->version()))->toBe(['vi'])
            ->and($mine->fresh()->translationFor('vi')->title)->toBe('Tôi sửa');
    });
});

it('tells only the publishers who may edit the entry that it waits for review', function (): void {
    [$editor, $ownPublisher, $owningPublisher, $sender, $author] = app(Tenancy::class)->enter(1, function (): array {
        $editor = pnePreset('editor');
        $ownPublisher = User::factory()->create();
        $owningPublisher = User::factory()->create();
        $sender = User::factory()->create();
        grantRole($ownPublisher, 'own-publisher', pneOwnPublisher());
        grantRole($owningPublisher, 'own-publisher', pneOwnPublisher());
        grantRole($sender, 'sender', ['entries.view', 'entries.edit.any', 'entries.submit']);

        return [$editor, $ownPublisher->fresh(), $owningPublisher->fresh(), $sender->fresh(), pnePreset('contributor')];
    });

    app(Tenancy::class)->enter(1, function () use ($author, $owningPublisher, $sender): void {
        // A draft sent by its author, a draft of a publisher sent by someone who may edit any
        // entry, and a pending change to a live entry (no workflow: the publishers hear of it).
        app(PublishEntry::class)->handle(pneEntry($author, 'Bài gửi duyệt', 'draft'), 'vi', $author);
        app(PublishEntry::class)->handle(pneEntry($owningPublisher, 'Nháp của người đăng', 'draft'), 'vi', $sender);
        app(SaveEntryDraft::class)->handle(pneEntry($author, 'Bài đang chạy'), $author, 'vi', ['title' => 'Bản sửa chờ']);
    });

    app(Tenancy::class)->enter(1, function () use ($editor, $ownPublisher, $owningPublisher): void {
        expect(pneNotices($editor))->toBe(['workflow.pending_review', 'workflow.pending_review', 'workflow.pending_review'])
            ->and(pneNotices($ownPublisher))->toBe([])
            // Their own draft: they may edit it, so they hear of it — and only of it.
            ->and(pneNotices($owningPublisher))->toBe(['workflow.pending_review']);
    });
});

it('keeps exactly what each shipped role may publish', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $colleague = User::factory()->create();
        $theirs = pneEntry($colleague, 'Của đồng nghiệp');
        $publishers = [];

        foreach (array_keys(PermissionRegistry::tenantRoleTemplates('corporate')) as $name) {
            $member = pnePreset($name);
            $mine = pneEntry($member, "Của {$name}");
            $may = [$member->can('publish', $theirs), $member->can('publish', $mine), $member->can('publish', new Entry(['type' => 'page', 'author_id' => $member->id]))];

            expect(array_unique($may))->toBe([$member->can('entries.publish')]);
            if ($may[0]) {
                $publishers[] = $name;
            }
        }

        expect($publishers)->toBe(['owner', 'admin', 'editor']);
    });
});

it("still lets a workflow's approver, who may not edit, approve and retract", function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.view', 'entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.view', 'entries.review', 'entries.approve']);

        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->firstOrCreate(['key' => 'tin-tuc'], ['label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        $workflow = Workflow::query()->create(['key' => 'admin_approval', 'name' => 'Duyệt bài', 'applies_to_type' => 'tin-tuc', 'template' => 'admin_approval', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true], ['key' => 'pending', 'label' => 'Chờ duyệt'], ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true]] as $state) {
            $workflow->states()->create($state);
        }
        foreach ([
            ['key' => 'submit', 'label' => 'Gửi duyệt', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
            ['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
            ['key' => 'retract', 'label' => 'Gỡ bài', 'from_state' => 'published', 'to_state' => 'draft', 'required_permission' => 'entries.review', 'requires_note' => true],
        ] as $transition) {
            $workflow->transitions()->create($transition);
        }

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $post->assignCategories([$category->id]);

        $move = app(ApplyEntryWorkflowTransition::class);
        $move->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
        expect($approver->fresh()->can('update', $post->fresh()))->toBeFalse()
            ->and(pneNotices($approver->fresh()))->toBe(['workflow.pending_review']);

        $move->handle($post->fresh(), 'vi', $approver->fresh(), 'approve');
        expect($post->fresh()->status)->toBe('published');

        $move->handle($post->fresh(), 'vi', $approver->fresh(), 'retract', 'Cần sửa lại');
        expect($post->fresh()->status)->toBe('draft');
    });
});

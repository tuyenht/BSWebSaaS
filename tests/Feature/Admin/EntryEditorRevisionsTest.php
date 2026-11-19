<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Support\Locales;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * «Lịch sử phiên bản» in the post and page editor (board 07): the history of the language being
 * edited is read when its menu opens, paged by keyset; a version is shown beside the form; a restore
 * goes through RestoreEntryRevision (the edit right, the status kept, the four refusals said in
 * words) and the form then holds the restored content. Another site's or another entry's version is
 * never found from this editor.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A member of the site who may edit and publish any entry. Call outside any site context. */
function revEditorMember(int $site = 1, string $name = 'Minh Trí'): User
{
    $user = User::factory()->create(['name' => $name]);
    app(Tenancy::class)->enter($site, fn () => grantRole($user, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']));
    app(TeamContext::class)->forget();

    return $user->fresh();
}

/** A published page «Giới thiệu» (one publish revision); returns [page, that revision]. */
function revEditorPage(User $publisher, string $slug = 'gioi-thieu'): array
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'meta' => []]);
    $page->translations()->create([
        'locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => $slug, 'status' => 'draft', 'excerpt' => 'Về chúng tôi',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Xin chào']]]],
    ]);
    app(PublishEntry::class)->handle($page->fresh(), 'vi', $publisher);

    return [$page->fresh(), EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')->sole()];
}

/** Save new vi content on a live page the way the editor does, recording the edit. */
function revEditorEdit(Entry $page, array $changes, User $actor): void
{
    $translation = $page->translationFor('vi');
    $translation->update($changes);
    app(RecordEntryRevision::class)->forLocale($page->fresh(), $translation->fresh(), RevisionKind::Edit, $actor);
}

it('reads the history when its menu opens, newest first, twenty a page by keyset, with why and who', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page] = revEditorPage($member);
        // 22 more, older than the publish, from no one (a loader): 23 in all
        for ($i = 1; $i <= 22; $i++) {
            EntryRevision::query()->create(['entry_id' => $page->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit,
                'snapshot' => ['v' => 1, 'title' => "Bản $i"], 'created_at' => now()->subDays($i)]);
        }
        $newest = EntryRevision::query()->where('entry_id', $page->id)->orderByDesc('created_at')->orderByDesc('id')->value('ulid');

        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->assertSet('revRows', [])
            ->assertSee(__('entries.rev.btn'))
            ->call('loadRevisions');

        $rows = $editor->get('revRows');
        expect($rows)->toHaveCount(20)
            ->and($rows[0]['ulid'])->toBe($newest)
            ->and($rows[0]['kind'])->toBe('publish')
            ->and($rows[0]['who'])->toBe('Minh Trí')
            ->and($rows[1]['who'])->toBe(__('entries.rev.who_system'))
            ->and($rows[0]['at'])->toMatch('/^\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/')
            ->and($editor->get('revTotal'))->toBe(23)
            ->and($editor->get('revCursor'))->not->toBeNull();
        $editor->assertSee(__('entries.rev.all', ['count' => 23]))
            ->assertSee(__('entries.rev.more', ['count' => 3]))
            ->assertSee(__('entries.rev.latest'));

        $editor->call('moreRevisions');
        $all = array_column($editor->get('revRows'), 'ulid');
        expect($all)->toHaveCount(23)
            ->and(array_unique($all))->toHaveCount(23)
            ->and($editor->get('revCursor'))->toBeNull();
        $editor->assertDontSee(__('entries.rev.more', ['count' => 0]));
    });
});

it('reads a page of history in a fixed number of queries, whoever saved each version', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page] = revEditorPage($member);
        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid]);
        $count = function () use ($editor): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $editor->call('loadRevisions');
            DB::disableQueryLog();

            return count(array_filter(DB::getQueryLog(), static fn (array $q): bool => str_contains($q['query'], 'entry_revisions') || str_contains($q['query'], '"users"')));
        };
        $one = $count();

        foreach (range(1, 12) as $i) {
            $author = User::factory()->create(['name' => "Người $i"]);
            EntryRevision::query()->create(['entry_id' => $page->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit,
                'snapshot' => ['v' => 1, 'title' => "Bản $i"], 'created_by' => $author->id, 'created_at' => now()->subDays($i)]);
        }

        expect($count())->toBe($one)
            ->and($editor->get('revRows'))->toHaveCount(13);
    });
});

it('shows one version beside the form, marking each place that differs', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh', 'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Chào mừng']]]]], $member);

        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('viewRevision', $v1->ulid)
            ->assertSet('revViewOpen', true);

        $view = $editor->get('revView');
        $fields = collect($view['fields'])->keyBy('label');
        expect($fields[__('entries.rev.f_title')])->toMatchArray(['value' => 'Giới thiệu', 'now' => 'Về An Vinh', 'differs' => true])
            ->and($fields[__('entries.rev.f_excerpt')]['differs'])->toBeFalse()
            ->and($fields[__('entries.rev.f_cover')]['shared'])->toBeTrue()
            ->and($view['lines'])->toHaveCount(1)
            ->and($view['lines'][0]['differs'])->toBeTrue()
            ->and($view['gone'])->toHaveCount(1)
            ->and($view['sum'])->toBe(__('entries.rev.sum_diff', ['count' => 3]))
            ->and($view['canRestore'])->toBeTrue();
        $editor->assertSee(__('entries.rev.differs'))->assertSee(__('entries.rev.restore_this'));

        // the newest version is the saved content: nothing differs, and it is not offered for restore
        $latest = $editor->get('revRows')[0]['ulid'];
        $editor->call('viewRevision', $latest);
        expect($editor->get('revView')['sum'])->toBe(__('entries.rev.sum_latest'))
            ->and($editor->get('revView')['canRestore'])->toBeFalse();
    });
});

it('restores a version after asking: the form takes it, the status stays, the history gains a restore', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh', 'excerpt' => 'Mới'], $member);

        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->set('excerpt', 'Đang gõ dở')
            ->call('loadRevisions')
            ->call('askRestore', $v1->ulid)
            ->assertSet('revAskOpen', true);
        $at = collect($editor->get('revRows'))->firstWhere('ulid', $v1->ulid)['at'];
        $ask = $editor->get('revAsk');
        expect($ask['live'])->toBeTrue()
            ->and($ask['dirty'])->toBeTrue()
            ->and($ask['shared'])->toBeFalse()
            ->and($ask['missing'])->toBeNull()
            ->and($ask['title'])->toBe(__('entries.rev.ask_title', ['at' => $at]));
        $editor->assertSee(__('entries.rev.ask_dirty'))->assertSee($ask['liveLine']);

        $editor->call('restoreRevision')->assertReturned(true)
            ->assertSet('revAskOpen', false)
            ->assertSet('revRefusal', null)
            ->assertSet('title', 'Giới thiệu')
            ->assertSet('excerpt', 'Về chúng tôi')
            ->assertDispatched('bs-toast', text: __('entries.rev.done_live', ['at' => $at]));

        $stored = $page->fresh();
        expect($stored->status)->toBe('published')
            ->and($stored->translationFor('vi')->title)->toBe('Giới thiệu')
            ->and($editor->get('revRows')[0]['kind'])->toBe('restore')
            ->and($editor->get('revNote')['text'])->toBe(__('entries.rev.note', ['at' => $at]))
            ->and($editor->get('revTotal'))->toBe(3);
    });
});

it('says in the question why a restore is refused, changing nothing', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $member);

        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('askRestore', $v1->ulid);
        // moved to the Trash in another tab meanwhile
        $page->delete();
        $count = EntryRevision::query()->count();

        $editor->call('restoreRevision')
            ->assertSet('revRefusal', __('entries.revision_err_entry_trashed'))
            ->assertSet('revAskOpen', true)
            ->assertSee(__('entries.revision_err_entry_trashed'))
            ->assertSee(__('entries.rev.close'))
            ->assertNotDispatched('bs-toast');
        expect(Entry::withTrashed()->find($page->id)->translationFor('vi')->title)->toBe('Về An Vinh')
            ->and(EntryRevision::query()->count())->toBe($count);
    });
});

it('never finds another entry\'s version from this editor', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page] = revEditorPage($member);
        [, $other] = revEditorPage($member, 'lien-he');

        Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('viewRevision', $other->ulid)
            ->assertSet('revView', null)
            ->assertSet('revViewOpen', false)
            ->call('askRestore', $other->ulid)
            ->assertSet('revAsk', null)
            ->assertDispatched('bs-toast', text: __('entries.rev.gone'));
    });
});

it('lets site B neither list nor restore site A\'s versions', function (): void {
    $a = revEditorMember(1, 'Thu Hà');
    [$pageA, $v1] = app(Tenancy::class)->enter(1, function () use ($a): array {
        [$page, $v1] = revEditorPage($a);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $a);

        return [$page, $v1];
    });
    $countA = app(Tenancy::class)->enter(1, fn (): int => EntryRevision::query()->count());

    $b = revEditorMember(2, 'Ngọc Anh');
    app(Tenancy::class)->enter(2, function () use ($b, $pageA, $v1): void {
        // site A's page does not open on site B
        Livewire::actingAs($b)->test(Editor::class, ['ulid' => $pageA->ulid])->assertStatus(404);

        [$pageB] = revEditorPage($b, 'trang-b');
        $editor = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $pageB->ulid])->call('loadRevisions');
        expect(array_column($editor->get('revRows'), 'ulid'))->not->toContain($v1->ulid)
            ->and($editor->get('revTotal'))->toBe(1);

        $editor->call('viewRevision', $v1->ulid)->assertSet('revView', null)
            ->call('askRestore', $v1->ulid)->assertSet('revAsk', null);
        // the question is the server's: the browser cannot point it at another version
        expect(fn () => $editor->set('revAsk', ['ulid' => $v1->ulid]))->toThrow(CannotUpdateLockedPropertyException::class);
        $editor->call('restoreRevision')->assertSet('revRefusal', null);
    });

    app(Tenancy::class)->enter(1, function () use ($pageA, $countA): void {
        expect($pageA->fresh()->translationFor('vi')->title)->toBe('Về An Vinh')
            ->and(EntryRevision::query()->count())->toBe($countA);
    });
});

it('says in the question when the right to edit was taken away since the editor opened', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $member);
        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('askRestore', $v1->ulid);

        Role::findByName('editor')->syncPermissions(['entries.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $editor->call('restoreRevision')
            ->assertReturned(false)
            ->assertSet('revRefusal', __('entries.err_update_forbidden'))
            ->assertSet('revAskOpen', true);
        $editor->call('loadRevisions')->assertForbidden();
        expect($page->fresh()->translationFor('vi')->title)->toBe('Về An Vinh');
    });
});

it('keeps each language\'s history to itself and says only this language changes', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
        [$page, $vi] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $member);
        $en = $page->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'published', 'body' => ['blocks' => []]]);
        app(RecordEntryRevision::class)->forLocale($page->fresh(), $en, RevisionKind::Edit, $member);
        $enUlid = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'en')->value('ulid');

        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
            ->call('loadRevisions');
        expect(array_column($editor->get('revRows'), 'ulid'))->toBe([$enUlid])
            ->and($editor->get('revTotal'))->toBe(1);
        // the Vietnamese version is not found from the English editor
        $editor->call('askRestore', $vi->ulid)->assertSet('revAsk', null)->assertDispatched('bs-toast', text: __('entries.rev.gone'));

        $vietnamese = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('askRestore', $vi->ulid);
        expect($vietnamese->get('revAsk')['oneLocale'])->toBe(__('entries.rev.ask_one_loc', ['lang' => Locales::label('vi')]))
            ->and($vietnamese->get('revAsk')['shared'])->toBeFalse();
    });
});

it('warns that the shared layout changes every language, and says so after the restore', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $member);
        $page->forceFill(['template_override' => 'landing'])->save();

        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('askRestore', $v1->ulid);
        expect($editor->get('revAsk')['shared'])->toBeTrue()
            ->and($editor->get('revAsk')['oneLocale'])->toBeNull();
        $editor->assertSee(__('entries.rev.ask_shared'));

        $editor->call('restoreRevision');
        expect($page->fresh()->template_override)->toBeNull()
            ->and($editor->get('revNote')['text'])->toContain(__('entries.rev.note_shared'));
    });
});

it('closes and forgets the question when the version left the history meanwhile, and keeps nothing', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $member);
        $editor = Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('viewRevision', $v1->ulid)
            ->call('askRestore', $v1->ulid);

        EntryRevision::query()->whereKey($v1->id)->delete();

        $editor->call('restoreRevision')
            ->assertReturned(false)
            ->assertSet('revAskOpen', false)
            ->assertSet('revViewOpen', false)
            ->assertSet('revAsk', null)
            ->assertSet('revView', null)
            ->assertSet('revRefusal', null)
            ->assertDispatched('bs-toast', text: __('entries.rev.gone'));
        expect($page->fresh()->translationFor('vi')->title)->toBe('Về An Vinh');
    });
});

it('forgets a closed version and a closed question', function (): void {
    $member = revEditorMember();

    app(Tenancy::class)->enter(1, function () use ($member): void {
        [$page, $v1] = revEditorPage($member);
        revEditorEdit($page, ['title' => 'Về An Vinh'], $member);

        Livewire::actingAs($member)->test(Editor::class, ['ulid' => $page->ulid])
            ->call('loadRevisions')
            ->call('viewRevision', $v1->ulid)
            ->call('askRestore', $v1->ulid)
            ->set('revAskOpen', false)
            ->assertSet('revAsk', null)
            ->assertSet('revRefusal', null)
            ->assertNotSet('revView', null)
            ->set('revViewOpen', false)
            ->assertSet('revView', null);
    });
});

it('has every history word in every language', function (): void {
    $vi = json_decode((string) file_get_contents(lang_path('vi.json')), true);
    foreach (['en', 'ja', 'zh'] as $lang) {
        $words = json_decode((string) file_get_contents(lang_path("$lang.json")), true);
        foreach (array_keys($vi) as $key) {
            if (str_starts_with($key, 'entries.rev.')) {
                expect($words)->toHaveKey($key);
            }
        }
    }
});

<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Exceptions\HomePageLockedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\HomePages;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * «Chuyển vào Thùng rác» happens at once, on a row or for the rows ticked, and the message offers
 * «Hoàn tác» for 10 seconds, which brings every one of them back. It asks first only for a page with
 * sub-pages (they go with it); the Home page never goes (another page becomes it first). The bulk bar counts, on each button, the ticked
 * rows that button would really act on, by the same rule the action runs by, and says under the bar
 * why the rest are left out. A trashed row says how many days it has before it is deleted for good.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** A member of site 1 with a role holding $permissions. */
function tuMember(string $role, array $permissions): User
{
    $user = User::factory()->create(['name' => Str::title($role).' '.Str::random(4)]);
    app(Tenancy::class)->enter(1, function () use ($user, $role, $permissions): void {
        $granted = Role::findOrCreate($role, PermissionRegistry::GUARD);
        $granted->forceFill(['level' => 40, 'is_system' => true])->save();
        $granted->syncPermissions($permissions);
        $user->assignRole($granted);
    });
    app(TeamContext::class)->forget();

    return $user->fresh() ?? $user;
}

/** A page of site 1 (enter the site first). */
function tuPage(?User $author, string $title, string $status = 'published', ?Entry $parent = null, ?string $slug = null): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => $status, 'author_id' => $author?->id, 'parent_id' => $parent?->id, 'meta' => []]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug ?? Str::slug($title).'-'.Str::lower(Str::random(5)),
        'status' => $status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => []],
    ]);

    return $entry;
}

function tuTrashed(Entry $entry): bool
{
    return Entry::onlyTrashed()->whereKey($entry->id)->exists();
}

/** @return array<string, array{label: string, ok: int}> the bulk bar's buttons by key */
function tuBulk(mixed $list): array
{
    $out = [];
    foreach ($list->viewData('bulk') as $action) {
        $out[$action['key']] = ['label' => $action['label'], 'ok' => $action['ok']];
    }

    return $out;
}

it('moves a row to the Trash at once, and «Hoàn tác» brings it back', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $page = tuPage($owner, 'Giới thiệu');
        $other = tuPage($owner, 'Liên hệ');
        $list = Livewire::actingAs($owner)->test(Index::class)->assertOk();
        expect($list->html())->toContain("wire:click=\"trash('{$page->ulid}')\"")
            ->and($list->html())->not->toContain(__('entries.trash_confirm', ['noun' => __('entries.noun_page')]));

        $list->call('trash', $page->ulid)->assertOk()
            ->assertNotDispatched('entries-ask-trash')
            ->assertSet('toastMsg', __('entries.trashed_one', ['name' => 'Giới thiệu']))
            ->assertSet('trashUndo', [$page->ulid])
            ->assertSeeHtml('wire:click="undoTrash"');
        expect(tuTrashed($page))->toBeTrue();

        // a second move while the message is up joins it: one message, one «Hoàn tác» for both
        $list->call('trash', $other->ulid)
            ->assertSet('toastMsg', __('entries.trashed_many', ['count' => 2, 'noun' => __('entries.noun_page')]))
            ->assertSet('trashUndo', [$page->ulid, $other->ulid]);

        $list->call('undoTrash')
            ->assertSet('trashUndo', null)
            ->assertSet('toastMsg', __('entries.undo_trash_done', ['count' => 2, 'noun' => __('entries.noun_page')]));
        expect(tuTrashed($page))->toBeFalse()->and(tuTrashed($other))->toBeFalse();

        // the timer of an older message never clears a newer one
        $list->call('trash', $page->ulid);
        $seq = $list->get('toastSeq');
        $list->call('closeToast', $seq - 1)->assertSet('trashUndo', [$page->ulid])
            ->call('closeToast', $seq)->assertSet('toastMsg', '')->assertSet('trashUndo', null);
        expect(tuTrashed($page))->toBeTrue();
    });
});

it('asks first for a page with sub-pages, then moves the whole branch and brings it all back', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $parent = tuPage($owner, 'Dịch vụ');
        $child = tuPage($owner, 'Gia công', 'published', $parent);
        $grandchild = tuPage($owner, 'Phay CNC', 'draft', $child);
        $list = Livewire::actingAs($owner)->test(Index::class);

        $list->call('trash', $parent->ulid)->assertDispatched('entries-ask-trash',
            ulid: $parent->ulid,
            title: __('entries.trash_ask_kids_title', ['count' => 2]),
            text: __('entries.trash_ask_kids_text', ['days' => 30]),
            ok: __('entries.trash_ask_kids_ok', ['count' => 3]),
            cancel: __('admin.cancel'),
        )->assertSet('toastMsg', '');
        expect(tuTrashed($parent))->toBeFalse()
            ->and(__('entries.trash_ask_kids_title', ['count' => 2]))->toBe('Chuyển trang này và 2 trang con vào Thùng rác?');

        $list->call('trash', $parent->ulid, true)
            ->assertSet('toastMsg', __('entries.trashed_many', ['count' => 3, 'noun' => __('entries.noun_page')]));
        expect(tuTrashed($parent))->toBeTrue()->and(tuTrashed($child))->toBeTrue()->and(tuTrashed($grandchild))->toBeTrue();

        $list->call('undoTrash');
        expect(tuTrashed($parent))->toBeFalse()->and(tuTrashed($child))->toBeFalse()->and(tuTrashed($grandchild))->toBeFalse()
            ->and(Entry::query()->whereKey($grandchild->id)->value('parent_id'))->toBe($child->id);
    });
});

it('never moves the Home page to the Trash, from the row or a direct call, and says why', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $home = tuPage($owner, 'Trang chủ', 'published', null, 'home');
        $list = Livewire::actingAs($owner)->test(Index::class);

        // asked or not, the answer is the reason, kept until closed
        foreach ([false, true] as $confirmed) {
            $list->call('trash', $home->ulid, $confirmed)
                ->assertNotDispatched('entries-ask-trash')
                ->assertDispatched('bs-toast', text: 'Muốn bỏ trang này, hãy chọn trang khác làm Trang chủ trước.', stay: true)
                ->assertSet('trashUndo', null);
        }
        expect(tuTrashed($home))->toBeFalse();
    });
});

it('never moves a branch with the Home page in it to the Trash', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $parent = tuPage($owner, 'Giới thiệu');
        $home = tuPage($owner, 'Trang chủ', 'published', $parent, 'home');

        Livewire::actingAs($owner)->test(Index::class)
            ->call('trash', $parent->ulid, true)
            ->assertDispatched('bs-toast', text: __('entries.home_trash_why'), stay: true);
        expect(tuTrashed($parent))->toBeFalse()->and(tuTrashed($home))->toBeFalse();
    });
});

it('refuses a move of the Home page to the Trash outside the screens too, but lets the Trash purge it', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $home = tuPage($owner, 'Trang chủ', 'published', null, 'home');

        expect(fn () => $home->delete())->toThrow(HomePageLockedException::class, __('entries.home_trash_why'));
        expect(tuTrashed($home))->toBeFalse();

        // a page that is not the Home page goes as before
        $other = tuPage($owner, 'Liên hệ');
        $other->delete();
        expect(tuTrashed($other))->toBeTrue();

        // a permanent purge is not a move to the Trash
        $home->forceDelete();
        expect(Entry::withTrashed()->whereKey($home->id)->exists())->toBeFalse();
    });
});

it('reads the Home page guard of a whole branch in one go, however many pages it takes along', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        // the Home page reads of one confirmed move of a branch of $size pages
        $homeReads = function (int $size) use ($owner): int {
            $parent = tuPage($owner, "Nhánh {$size}");
            foreach (range(2, $size) as $i) {
                tuPage($owner, "Trang con {$size}-{$i}", 'published', $parent);
            }
            $list = Livewire::actingAs($owner)->test(Index::class);
            $reads = 0;
            DB::listen(function ($query) use (&$reads): void {
                if (str_contains($query->sql, 'from "entry_translations"') && str_contains($query->sql, '"slug" in')) {
                    $reads++;
                }
            });
            $list->call('trash', $parent->ulid, true);
            expect(tuTrashed($parent))->toBeTrue();

            return $reads;
        };

        $small = $homeReads(3);
        $large = $homeReads(9);
        expect($large)->toBe($small)->and($small)->toBeLessThanOrEqual(2);
    });
});

it('reads the Home page guard of a bulk move in one go per chunk, however many pages are ticked', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        // the Home page reads of one bulk move of $size ticked pages
        $homeReads = function (int $size) use ($owner): int {
            $ulids = [];
            foreach (range(1, $size) as $i) {
                $ulids[] = tuPage($owner, "Trang {$size}-{$i}")->ulid;
            }
            $list = Livewire::actingAs($owner)->test(Index::class)->set('selected', $ulids);
            $reads = 0;
            DB::listen(function ($query) use (&$reads): void {
                if (str_contains($query->sql, 'from "entry_translations"') && str_contains($query->sql, '"slug" in')) {
                    $reads++;
                }
            });
            $list->call('bulkTrash');
            foreach ($ulids as $ulid) {
                expect(Entry::onlyTrashed()->where('ulid', $ulid)->exists())->toBeTrue();
            }

            return $reads;
        };

        $small = $homeReads(2);
        $large = $homeReads(8);
        expect($large)->toBe($small)->and($small)->toBeLessThanOrEqual(2);
    });
});

it('says why when a page became the Home page between the check and the move, and moves nothing', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $parent = tuPage($owner, 'Giới thiệu');
        $kid = tuPage($owner, 'Trang con', 'published', $parent);
        $list = Livewire::actingAs($owner)->test(Index::class);

        // the sub-page becomes the Home page right after the screen's own check (as a colleague's save would)
        $made = false;
        DB::listen(function ($query) use (&$made, $kid): void {
            if (! $made && str_contains($query->sql, 'from "entry_translations"') && str_contains($query->sql, '"slug" in')) {
                $made = true;
                DB::table('entry_translations')->where('entry_id', $kid->id)->update(['slug' => 'home']);
            }
        });
        $list->call('trash', $parent->ulid, true)
            ->assertOk()
            ->assertDispatched('bs-toast', text: __('entries.home_trash_why'), stay: true)
            ->assertSet('trashUndo', null);
        expect($made)->toBeTrue()->and(tuTrashed($parent))->toBeFalse()->and(tuTrashed($kid))->toBeFalse();
    });
});

it('keeps refusing the Home page when its answer was read for a batch beforehand', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $home = tuPage($owner, 'Trang chủ', 'published', null, 'home');
        $other = tuPage($owner, 'Liên hệ');
        HomePages::remember([$home, $other]);

        expect(fn () => $home->delete())->toThrow(HomePageLockedException::class, __('entries.home_trash_why'))
            ->and(countQueries(fn () => expect(HomePages::is($other))->toBeFalse()))->toBe(0);
        $other->delete();
        expect(tuTrashed($home))->toBeFalse()->and(tuTrashed($other))->toBeTrue();
    });
});

it('refuses to move a page whose sub-pages the member may not remove, and moves none of them', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);
    $own = tuMember('own-deleter', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own']);

    app(Tenancy::class)->enter(1, function () use ($owner, $own): void {
        $mine = tuPage($own, 'Trang của tôi');
        $theirs = tuPage($owner, 'Trang con của chủ', 'published', $mine);

        Livewire::actingAs($own)->test(Index::class)
            ->call('trash', $mine->ulid, true)->assertOk()
            ->assertDispatched('bs-toast', text: __('entries.trash_kids_refused'), stay: true)
            ->assertSet('trashUndo', null);
        expect(tuTrashed($mine))->toBeFalse()->and(tuTrashed($theirs))->toBeFalse();
    });
});

it('bulk-moves at once with «Hoàn tác», and leaves the Home page out with the reason', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $home = tuPage($owner, 'Trang chủ', 'published', null, 'home');
        $a = tuPage($owner, 'Trang A');
        $b = tuPage($owner, 'Trang B', 'draft');
        $list = Livewire::actingAs($owner)->test(Index::class)->set('selected', [$home->ulid, $a->ulid, $b->ulid]);

        expect(tuBulk($list)['trash'])->toBe(['label' => 'Chuyển vào Thùng rác (2)', 'ok' => 2])
            ->and($list->viewData('bulkSkip'))->toBe('“Đăng” bỏ qua 2 trang: 2 trang đã đăng.');

        $list->call('bulkTrash')
            ->assertSet('selected', [])
            ->assertSet('trashUndo', [$a->ulid, $b->ulid])
            ->assertSet('toastMsg', __('entries.bulk_trashed', ['count' => 2]).' '.__('entries.bulk_skipped', ['count' => 1]).' '.__('entries.skip_home_note'));
        expect(tuTrashed($home))->toBeFalse()->and(tuTrashed($a))->toBeTrue()->and(tuTrashed($b))->toBeTrue();

        $list->call('undoTrash');
        expect(tuTrashed($a))->toBeFalse()->and(tuTrashed($b))->toBeFalse();
    });
});

it('counts on each bulk button the rows it would act on, with the reasons for the rest, for each member', function (): void {
    $template = PermissionRegistry::tenantRoleTemplates('corporate');
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);
    $own = tuMember('own-publisher', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.delete.own', 'entries.publish']);
    $sender = tuMember('contributor', $template['contributor']['permissions']);
    $viewer = tuMember('viewer', $template['viewer']['permissions']);

    app(Tenancy::class)->enter(1, function () use ($owner, $own, $sender, $viewer): void {
        $ownDraft = tuPage($own, 'Nháp của tôi', 'draft');
        $ownLive = tuPage($own, 'Đã đăng của tôi');
        $theirLive = tuPage($owner, 'Của chủ');
        $sendDraft = tuPage($sender, 'Nháp gửi duyệt', 'draft');
        $sendPending = tuPage($sender, 'Đang chờ', 'pending');
        $all = [$ownDraft->ulid, $ownLive->ulid, $theirLive->ulid, $sendDraft->ulid, $sendPending->ulid];

        // the owner: everything is theirs to act on; only the state of a row leaves it out
        $list = Livewire::actingAs($owner)->test(Index::class)->set('selected', $all);
        expect(tuBulk($list))->toBe([
            'publish' => ['label' => 'Đăng (3)', 'ok' => 3],
            'unpublish' => ['label' => 'Gỡ khỏi website (2)', 'ok' => 2],
            'draft' => ['label' => 'Đưa về nháp (3)', 'ok' => 3],
            'duplicate' => ['label' => 'Nhân bản (5)', 'ok' => 5],
            'trash' => ['label' => 'Chuyển vào Thùng rác (5)', 'ok' => 5],
        ])->and($list->viewData('bulkSkip'))->toBe('“Đăng” bỏ qua 2 trang: 2 trang đã đăng.');

        // an own-only publisher: publishing needs the right to edit the row, so like the Trash it reaches
        // only their own rows; a colleague's rows are left out as «của người khác»
        $list = Livewire::actingAs($own)->test(Index::class)->set('selected', $all);
        expect(tuBulk($list))->toBe([
            'publish' => ['label' => 'Đăng (1)', 'ok' => 1],
            'unpublish' => ['label' => 'Gỡ khỏi website (1)', 'ok' => 1],
            'draft' => ['label' => 'Đưa về nháp (1)', 'ok' => 1],
            'duplicate' => ['label' => 'Nhân bản (5)', 'ok' => 5],
            'trash' => ['label' => 'Chuyển vào Thùng rác (2)', 'ok' => 2],
        ])->and(collect($list->viewData('bulk'))->firstWhere('key', 'trash')['title'])
            ->toBe(__('entries.bulk_title_skip', ['label' => 'Chuyển vào Thùng rác', 'count' => 2, 'noun' => 'trang', 'skipped' => 3]));

        // a sender: «Gửi duyệt» for their own drafts only, no Thùng rác (no right to delete)
        $list = Livewire::actingAs($sender)->test(Index::class)->set('selected', $all);
        expect(tuBulk($list))->toBe([
            'send' => ['label' => 'Gửi duyệt (1)', 'ok' => 1],
            'duplicate' => ['label' => 'Nhân bản (5)', 'ok' => 5],
        ])->and($list->viewData('bulkSkip'))->toBe('“Gửi duyệt” bỏ qua 4 trang: 3 trang của người khác · 1 trang không phải bản nháp.');

        // a viewer: no bar at all
        $list = Livewire::actingAs($viewer)->test(Index::class)->set('selected', $all);
        expect($list->viewData('bulk'))->toBe([])
            ->and($list->html())->not->toContain('data-guide="bulk-bar"');

        // a button with nothing to do says why instead of running
        $list = Livewire::actingAs($own)->test(Index::class)->set('selected', [$theirLive->ulid]);
        $trash = collect($list->viewData('bulk'))->firstWhere('key', 'trash');
        expect($trash['ok'])->toBe(0)
            ->and($trash['none'])->toBe('Không có trang nào làm được việc “Chuyển vào Thùng rác”: 1 trang của người khác.')
            ->and($list->viewData('bulkSkip'))->toBe('“Đăng” bỏ qua 1 trang: 1 trang của người khác.');
    });
});

it('counts a member outside their category limits as out of scope, on every page', function (): void {
    $template = PermissionRegistry::tenantRoleTemplates('corporate');
    $limited = tuMember('editor', $template['editor']['permissions']);

    app(Tenancy::class)->enter(1, function () use ($limited): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => null, 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Sự kiện', 'slug' => 'su-kien']);
        MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $category->id]);
        app(CategoryScopes::class)->forget();
        $page = tuPage(null, 'Giới thiệu');

        $list = Livewire::actingAs($limited)->test(Index::class)->set('selected', [$page->ulid]);
        expect(tuBulk($list)['trash'])->toBe(['label' => 'Chuyển vào Thùng rác (0)', 'ok' => 0])
            ->and($list->viewData('bulkSkip'))->toBe('“Đăng” bỏ qua 1 trang: 1 trang ngoài phạm vi của bạn.');

        $list->call('bulkTrash');
        expect(tuTrashed($page))->toBeFalse();
    });
});

it('takes a published row off the website in bulk and leaves the rest', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $live = tuPage($owner, 'Đang đăng');
        $draft = tuPage($owner, 'Bản nháp', 'draft');

        Livewire::actingAs($owner)->test(Index::class)
            ->set('selected', [$live->ulid, $draft->ulid])
            ->call('bulkUnpublish')
            ->assertDispatched('bs-toast', text: __('entries.bulk_unpublished', ['count' => 1]).' '.__('entries.bulk_skipped', ['count' => 1]));

        expect(Entry::query()->whereKey($live->id)->value('status'))->toBe('archived')
            ->and(Entry::query()->whereKey($draft->id)->value('status'))->toBe('draft');
    });
});

it('says on a trashed row how many days it has before it is deleted for good', function (): void {
    $this->travelTo(Carbon::parse('2027-01-15 10:00:00', 'UTC'));
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        foreach (['Mới xóa' => 1, 'Xóa lâu' => 21, 'Quá hạn' => 45] as $title => $ago) {
            $page = tuPage($owner, $title);
            $page->delete();
            Entry::withTrashed()->whereKey($page->id)->toBase()->update(['deleted_at' => now()->subDays($ago)->subHour()->utc()->toDateTimeString().'+00']);
        }

        $html = Livewire::actingAs($owner)->test(Index::class)->call('setTab', 'trash')->html();
        expect($html)->toContain('Tự xóa vĩnh viễn sau 29 ngày')
            ->and($html)->toContain('Tự xóa vĩnh viễn sau 9 ngày')
            ->and($html)->toContain(__('entries.trash_goes_today'));
    });
});

it('works the bulk bar out in the same few queries however many rows are ticked', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $ulids = [];
        foreach (range(1, 18) as $i) {
            $ulids[] = tuPage($owner, "Trang {$i}", $i % 3 === 0 ? 'draft' : 'published')->ulid;
        }
        $list = Livewire::actingAs($owner)->test(Index::class)->set('perPage', 20);
        $list->set('selected', array_slice($ulids, 0, 2));

        $few = countQueries(fn () => $list->set('selected', array_slice($ulids, 0, 3)));
        $many = countQueries(fn () => $list->set('selected', $ulids));

        expect($many)->toBe($few);
    });
});

it('brings back with «Hoàn tác» a branch of more pages than one bulk run takes', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $parent = tuPage($owner, 'Sản phẩm');
        foreach (range(1, 205) as $i) {
            tuPage($owner, "Mẫu {$i}", 'published', $parent);
        }

        $list = Livewire::actingAs($owner)->test(Index::class)->call('trash', $parent->ulid, true);
        expect(count($list->get('trashUndo')))->toBe(206)
            ->and(Entry::onlyTrashed()->count())->toBe(206);

        $list->call('undoTrash')
            ->assertSet('trashUndo', null)
            ->assertSet('toastMsg', __('entries.undo_trash_done', ['count' => 206, 'noun' => __('entries.noun_page')]));
        expect(Entry::onlyTrashed()->count())->toBe(0)
            ->and(Entry::query()->where('parent_id', $parent->id)->count())->toBe(205);
    });
});

it('leaves out of a bulk move a page that still has live sub-pages, on the count and in the run', function (): void {
    $deleter = tuMember('deleter', ['entries.view', 'entries.delete.any']);

    app(Tenancy::class)->enter(1, function () use ($deleter): void {
        $parent = tuPage(null, 'Dịch vụ');
        $kid = tuPage(null, 'Gia công', 'published', $parent);
        $emptied = tuPage(null, 'Tin cũ');
        tuPage(null, 'Đã bỏ', 'published', $emptied)->delete();   // only a trashed sub-page: it may go
        $plain = tuPage(null, 'Liên hệ');
        $list = Livewire::actingAs($deleter)->test(Index::class)->set('selected', [$parent->ulid, $emptied->ulid, $plain->ulid]);

        expect(tuBulk($list))->toBe(['trash' => ['label' => 'Chuyển vào Thùng rác (2)', 'ok' => 2]])
            ->and($list->viewData('bulkSkip'))->toBe('“Chuyển vào Thùng rác” bỏ qua 1 trang: 1 trang còn trang con — chuyển từng trang.');

        $list->call('bulkTrash')
            ->assertSet('trashUndo', [$emptied->ulid, $plain->ulid])
            ->assertSet('toastMsg', __('entries.bulk_trashed', ['count' => 2]).' '.__('entries.bulk_skipped', ['count' => 1]).' '.__('entries.skip_kids_note'));
        expect(tuTrashed($parent))->toBeFalse()->and(tuTrashed($kid))->toBeFalse()
            ->and(tuTrashed($emptied))->toBeTrue()->and(tuTrashed($plain))->toBeTrue();
    });
});

it('moves nothing of a branch past the sub-page cap, at whatever level the cap is passed', function (): void {
    config(['cms.trash_subpages_max' => 2]);
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $refused = __('entries.trash_kids_too_many', ['max' => 2, 'depth' => 12]);

        // three sub-pages on one level: past the cap
        $wide = tuPage($owner, 'Rộng');
        $wideKids = array_map(fn (int $i): Entry => tuPage($owner, "Rộng {$i}", 'published', $wide), [1, 2, 3]);
        Livewire::actingAs($owner)->test(Index::class)->call('trash', $wide->ulid, true)
            ->assertDispatched('bs-toast', text: $refused, stay: true)
            ->assertSet('trashUndo', null);

        // two sub-pages, within the cap level by level, and a grandchild that passes it
        $deep = tuPage($owner, 'Sâu');
        $a = tuPage($owner, 'Sâu A', 'published', $deep);
        $b = tuPage($owner, 'Sâu B', 'published', $deep);
        $grandkid = tuPage($owner, 'Sâu A1', 'published', $a);
        Livewire::actingAs($owner)->test(Index::class)->call('trash', $deep->ulid, true)
            ->assertDispatched('bs-toast', text: $refused, stay: true);

        expect(Entry::onlyTrashed()->count())->toBe(0);

        // exactly at the cap: asked, then the whole branch goes
        $grandkid->forceDelete();
        Livewire::actingAs($owner)->test(Index::class)->call('trash', $deep->ulid)
            ->assertDispatched('entries-ask-trash', ulid: $deep->ulid)
            ->call('trash', $deep->ulid, true);
        expect(tuTrashed($deep))->toBeTrue()->and(tuTrashed($a))->toBeTrue()->and(tuTrashed($b))->toBeTrue()
            ->and(collect($wideKids)->contains(fn (Entry $kid): bool => tuTrashed($kid)))->toBeFalse();
    });
});

it('takes a branch exactly twelve levels deep along, and refuses one a level deeper', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $top = tuPage($owner, 'Gốc');
        $parent = $top;
        foreach (range(1, 12) as $level) {
            $parent = tuPage($owner, "Tầng {$level}", 'published', $parent);
        }

        Livewire::actingAs($owner)->test(Index::class)->call('trash', $top->ulid, true);
        expect(Entry::onlyTrashed()->count())->toBe(13);

        Entry::onlyTrashed()->restore();
        tuPage($owner, 'Tầng 13', 'published', $parent);
        Livewire::actingAs($owner)->test(Index::class)->call('trash', $top->ulid, true)
            ->assertDispatched('bs-toast', text: __('entries.trash_kids_too_many', ['max' => 1000, 'depth' => 12]), stay: true)
            ->assertSet('trashUndo', null);
        expect(Entry::onlyTrashed()->count())->toBe(0);
    });
});

it('reads no row for the bulk bar when more are ticked than one run takes, and every button says so', function (): void {
    $owner = tuMember('owner', PermissionRegistry::TENANT_PERMISSIONS);

    app(Tenancy::class)->enter(1, function () use ($owner): void {
        $page = tuPage($owner, 'Giới thiệu');
        $many = [$page->ulid, ...array_map(static fn (): string => (string) Str::ulid(), range(1, 200))];
        $tooMany = __('entries.bulk_too_many', ['max' => 200]);

        $list = Livewire::actingAs($owner)->test(Index::class)->set('selected', $many);
        $bulk = collect($list->viewData('bulk'));
        expect($bulk->pluck('ok')->unique()->all())->toBe([0])
            ->and($bulk->pluck('none')->unique()->all())->toBe([$tooMany])
            ->and($bulk->pluck('label')->all())->toBe(['Đăng', 'Gỡ khỏi website', 'Đưa về nháp', 'Nhân bản', 'Chuyển vào Thùng rác'])
            ->and($list->viewData('bulkSkip'))->toBe($tooMany);

        $list->call('bulkTrash')->assertDispatched('bs-toast', text: $tooMany);
        expect(tuTrashed($page))->toBeFalse();
    });
});

<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\Entries\PageParentChoices;
use App\Modules\Admin\Support\Locales;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\PageRules;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * «Thêm trang con» in the Trang list's row menu and the page editor's «Trang cha» (design 05 and 07):
 * the menu item opens the editor with the row as the parent (by its ulid), for whoever may add a page,
 * never on the Home page or a page on the last level; the picker lists the website's pages, greys the
 * ones that cannot be the parent with the reason, and a new page is saved under its parent at the end
 * of its sub-pages while an existing page changes parent through MovePage (addresses and 301s).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function acPage(string $slug, ?Entry $parent = null, int $position = 0, ?User $author = null): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author?->id, 'parent_id' => $parent?->id, 'position' => $position]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page->fresh() ?? $page;
}

/** A member of site 1 with $permissions. */
function acMember(array $permissions, string $role = 'editor'): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, $role, $permissions));

    return $user->fresh() ?? $user;
}

function acWriter(): User
{
    return acMember(['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
}

function acNested(): void
{
    SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
}

/** The row menu's «Thêm trang con» for each slug: its address, 'lock: reason', or null. */
function acChildItems(User $user, array $pages): array
{
    // every row, also for a member who may edit only their own pages (their list opens on «Của tôi»)
    $rowCan = Livewire::actingAs($user)->test(Index::class)->set('creatorF', [])->viewData('rowCan');
    $out = [];
    foreach ($pages as $slug => $page) {
        $may = $rowCan[$page->ulid] ?? throw new RuntimeException("no row {$slug} for user {$user->id}: ".implode(',', array_keys($rowCan)));
        $out[$slug] = $may['childWhy'] !== null ? 'lock: '.$may['childWhy'] : $may['child'];
    }

    return $out;
}

it('offers «Thêm trang con» on every page but the Home page and the last level, with the parent by its ulid', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $home = acPage('home');
        $a = acPage('a', position: 1);
        $a1 = acPage('a1', $a);
        $a11 = acPage('a11', $a1);

        $items = acChildItems($writer, compact('home', 'a', 'a1', 'a11'));
        expect($items['home'])->toBeNull()
            ->and($items['a11'])->toBeNull()
            ->and($items['a'])->toBe(route('admin.entries.create', ['parent' => $a->ulid]))
            ->and($items['a1'])->toBe(route('admin.entries.create', ['parent' => $a1->ulid]))
            ->and((string) $items['a'])->not->toContain('='.$a->id.'&')->not->toEndWith('='.$a->id);

        // the menu draws it, in tree and flat mode alike
        Livewire::actingAs($writer)->test(Index::class)->assertSee(__('entries.row_add_child'));
        $flat = Livewire::actingAs($writer)->test(Index::class)->set('search', 'T')->viewData('rowCan');
        expect($flat[$a11->ulid]['child'])->toBeNull()->and($flat[$a1->ulid]['child'])->not->toBeNull();
    });
});

it('counts the levels above a page under a page in the Trash, so its sub-page is not offered past the last level', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = acPage('a');
        $a1 = acPage('a1', $a);
        $a11 = acPage('a11', $a1);
        $b = acPage('b', position: 1);
        $b1 = acPage('b1', $b);
        $b->delete();   // b in the Trash: b1 is drawn at the top, yet it is on level 2

        $items = acChildItems($writer, compact('a', 'a1', 'a11', 'b1'));
        expect($items['b1'])->not->toBeNull()->and($items['a11'])->toBeNull();

        $c = acPage('c', $b1);   // level 3 under the trashed b
        expect(acChildItems($writer, ['c' => $c])['c'])->toBeNull();
    });
});

it('offers «Thêm trang con» to whoever may add a page, without a right on the parent, and locks it for a member limited to some categories', function (): void {
    $own = acMember(['entries.view', 'entries.create', 'entries.edit.own'], 'author');
    $viewer = acMember(['entries.view'], 'viewer');
    $limited = acMember(['entries.view', 'entries.create', 'entries.edit.any'], 'limited');

    app(Tenancy::class)->enter(1, function () use ($own, $viewer, $limited): void {
        $theirs = acPage('theirs');
        $cat = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
        MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $cat->id]);
        app(CategoryScopes::class)->forget();

        expect(acChildItems($own, ['theirs' => $theirs])['theirs'])->toBe(route('admin.entries.create', ['parent' => $theirs->ulid]))
            ->and(acChildItems($viewer, ['theirs' => $theirs])['theirs'])->toBeNull();

        $locked = acChildItems($limited, ['theirs' => $theirs])['theirs'];
        expect($locked)->toStartWith('lock: ')->not->toEndWith('.');
        // drawn locked, with its reason, in the menu of a row the member may not touch
        Livewire::actingAs($limited)->test(Index::class)->set('creatorF', [])
            ->assertSeeHtml('data-child')->assertSee(__('entries.row_add_child'))->assertSee(substr($locked, 6))->assertDontSeeHtml('href="'.route('admin.entries.create', ['parent' => $theirs->ulid]).'"');
    });
});

it('opens the editor under the parent the menu named, and says why when that page can no longer take it', function (): void {
    $writer = acWriter();

    $foreign = app(Tenancy::class)->enter(2, fn (): string => acPage('khac')->ulid);
    app(Tenancy::class)->enter(1, function () use ($writer, $foreign): void {
        $home = acPage('home');
        $a = acPage('a', position: 1);
        $a1 = acPage('a1', $a);
        $a11 = acPage('a11', $a1);
        $gone = acPage('gone', position: 2);
        $gone->delete();

        Livewire::actingAs($writer)->test(Editor::class, ['parent' => $a->ulid])
            ->assertSet('parentUlid', $a->ulid)->assertSet('parentName', 'T a');
        Livewire::withQueryParams(['parent' => $a1->ulid])->actingAs($writer)->test(Editor::class)
            ->assertSet('parentUlid', $a1->ulid);

        $gonePage = __('entries.ed.par_gone');
        $cases = [[$foreign, $gonePage], [$home->ulid, __('entries.move_refused_parent_home')],
            [$a11->ulid, __('entries.ed.par_why_level', ['n' => 4])], [$gone->ulid, $gonePage], ['not-a-key', $gonePage], [(string) $a->id, $gonePage]];
        foreach ($cases as [$key, $why]) {
            $editor = Livewire::actingAs($writer)->test(Editor::class, ['parent' => $key])
                ->assertSet('parentUlid', null)->assertSet('parentName', '')->assertHasErrors('parentUlid');
            expect($editor->errors()->first('parentUlid'))->toBe($why);
        }
        Livewire::withQueryParams([])->actingAs($writer)->test(Editor::class)->assertSet('parentUlid', null)->assertHasNoErrors();

        // «Hoàn tác» on a new page keeps the parent it was opened under
        Livewire::actingAs($writer)->test(Editor::class, ['parent' => $a->ulid])
            ->set('title', 'Nháp')->call('resetChanges')
            ->assertSet('title', '')->assertSet('parentUlid', $a->ulid)->assertSet('parentName', 'T a');
    });
});

it('saves a new page at the end of its parent\'s sub-pages, at a nested address when the website nests them', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        acNested();
        $a = acPage('dich-vu');
        app(PublishEntry::class)->handle($a, 'vi', $writer);
        acPage('first', $a, 0);
        acPage('second', $a, 4);

        Livewire::actingAs($writer)->test(Editor::class, ['parent' => $a->ulid])
            ->set('title', 'Thiết kế')->set('slug', 'thiet-ke')
            ->call('publish')->assertHasNoErrors();

        $page = Entry::query()->whereHas('translations', fn ($q) => $q->where('slug', 'thiet-ke'))->firstOrFail();
        expect($page->parent_id)->toBe($a->id)
            ->and($page->position)->toBe(5)
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/dich-vu/thiet-ke');
    });
});

it('refuses to save a new page under a parent that went to the Trash, became the Home page or moved to the last level, keeping what was typed', function (string $change, string $why): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer, $change, $why): void {
        $a = acPage('a');
        $b = acPage('b', position: 1);
        $b1 = acPage('b1', $b);

        $editor = Livewire::actingAs($writer)->test(Editor::class, ['parent' => $a->ulid])
            ->set('title', 'Trang mới')->set('slug', 'trang-moi');
        match ($change) {
            'trash' => $a->delete(),
            'home' => $a->translations()->update(['slug' => 'home']),
            'deep' => Entry::query()->whereKey($a->id)->update(['parent_id' => $b1->id]),
        };
        $editor->call('save')
            ->assertHasErrors('parentUlid')
            ->assertDispatched('bs-toast', text: $why, stay: true)
            ->assertSet('ulid', null)->assertSet('title', 'Trang mới');
        expect($editor->errors()->first('parentUlid'))->toBe($why);

        expect(Entry::query()->whereHas('translations', fn ($q) => $q->where('title', 'Trang mới'))->exists())->toBeFalse();
    });
})->with([
    'in the Trash' => ['trash', 'Trang cha đã chọn không còn (đã vào Thùng rác hoặc bị xóa) — hãy chọn trang cha khác.'],
    'now the Home page' => ['home', fn (): string => __('entries.move_refused_parent_home')],
    'now on the last level' => ['deep', fn (): string => __('entries.move_depth_page')],
]);

it('moves an edited page to the parent picked, at the end, through MovePage with a 301 from the old address', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        acNested();
        $a = acPage('dich-vu');
        app(PublishEntry::class)->handle($a, 'vi', $writer);
        acPage('first', $a, 0);
        $b = acPage('thiet-ke', position: 1);
        app(PublishEntry::class)->handle($b, 'vi', $writer);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid])
            ->assertSet('parentUlid', null)
            ->set('parentUlid', $a->ulid)->assertHasNoErrors()->assertSet('parentName', 'T dich-vu')
            ->call('publish')->assertHasNoErrors()
            ->assertSet('parentLoaded', $a->ulid);

        $b->refresh();
        expect($b->parent_id)->toBe($a->id)->and($b->position)->toBe(1)
            ->and(SiteRoute::query()->where('entry_id', $b->id)->value('path'))->toBe('/dich-vu/thiet-ke')
            ->and(Redirect::query()->where('from_path', '/thiet-ke')->value('to_path'))->toBe('/dich-vu/thiet-ke')
            ->and(AuditLog::query()->where('site_id', 1)->where('action', 'cms.page.moved')->where('auditable_id', $b->id)->exists())->toBeTrue();

        // back to the top level
        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid])
            ->assertSet('parentUlid', $a->ulid)
            ->set('parentUlid', null)->call('save')->assertHasNoErrors();
        expect($b->fresh()?->parent_id)->toBeNull();
    });
});

it('refuses a parent the tree rules forbid as it is picked, with the reason under the field', function (): void {
    $writer = acWriter();

    $foreign = app(Tenancy::class)->enter(2, fn (): string => acPage('khac')->ulid);
    app(Tenancy::class)->enter(1, function () use ($writer, $foreign): void {
        $home = acPage('home');
        $top = acPage('top', position: 1);
        $l2 = acPage('l2', $top);
        $l3 = acPage('l3', $l2);
        $p = acPage('p', position: 2);
        $p1 = acPage('p1', $p);
        $leaf = acPage('leaf', position: 3);

        $cases = [
            [$p, $p->ulid, __('entries.ed.par_why_self')],
            [$p, $p1->ulid, __('entries.ed.par_why_sub')],
            [$p, $l2->ulid, __('entries.ed.par_why_branch')],
            [$leaf, $l3->ulid, __('entries.ed.par_why_level', ['n' => 4])],
            [$leaf, $home->ulid, __('entries.move_refused_parent_home')],
            [$leaf, $foreign, __('entries.ed.par_gone')],
            [$leaf, 'not-a-key', __('entries.ed.par_gone')],
        ];
        foreach ($cases as [$page, $pick, $why]) {
            $editor = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid])
                ->set('parentUlid', $pick)
                ->assertHasErrors('parentUlid')
                ->assertSet('parentUlid', null);
            expect($editor->errors()->first('parentUlid'))->toBe($why);
        }
        // a page with sub-pages fits under a top page
        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $p->ulid])
            ->set('parentUlid', $top->ulid)->assertHasNoErrors()->call('save')->assertHasNoErrors();
        expect($p->fresh()?->parent_id)->toBe($top->id);
    });
});

it('counts the levels above the first row of a list page that starts inside a branch under a page in the Trash', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        foreach (range(0, 8) as $i) {
            acPage("p{$i}", position: $i);
        }
        $b = acPage('b', position: 20);
        $b1 = acPage('b1', $b, position: 20);
        $c = acPage('c', $b1);   // level 3
        $b->delete();   // b1 drawn at the top, as the 10th row; c opens the list's page 2

        $list = Livewire::actingAs($writer)->test(Index::class)->call('setPerPage', 10);
        expect($list->viewData('rowCan'))->toHaveKey($b1->ulid)->not->toHaveKey($c->ulid);
        $second = $list->call('gotoPage', 2)->viewData('rowCan');
        expect($second)->toHaveKey($c->ulid)
            ->and($second[$c->ulid]['child'])->toBeNull()->and($second[$c->ulid]['childWhy'])->toBeNull();
    });
});

it('says the page is gone when it went to the Trash while its move was being saved, keeping what was typed', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = acPage('a');
        $leaf = acPage('leaf', position: 1);

        $editor = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $leaf->ulid])
            ->set('parentUlid', $a->ulid)->assertHasNoErrors()->set('title', 'Đổi tên');
        // the page goes to the Trash right after the save takes the page-tree lock
        $done = false;
        DB::listen(function ($query) use (&$done, $leaf): void {
            if (! $done && str_contains($query->sql, 'pg_advisory_xact_lock') && in_array('pages:1', $query->bindings, true)) {
                $done = true;
                DB::table('entries')->where('id', $leaf->id)->update(['deleted_at' => now()]);
            }
        });
        $editor->call('save')
            ->assertDispatched('bs-toast', text: __('admin.editor.gone'), stay: true)
            ->assertSet('title', 'Đổi tên');
        expect($done)->toBeTrue()
            ->and(Entry::withTrashed()->find($leaf->id)?->parent_id)->toBeNull()
            ->and($leaf->translations()->value('title'))->toBe('T leaf');
    });
});

it('lists at most the cap of pages for «Trang cha», and says there are more', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $now = now();
        DB::table('entries')->insert(array_map(fn (int $i): array => ['site_id' => 1, 'ulid' => (string) Str::ulid(), 'type' => 'page',
            'status' => 'draft', 'position' => $i, 'created_at' => $now, 'updated_at' => $now], range(0, PageRules::WALK_SIZE)));

        $choices = app(PageParentChoices::class)->for(null);
        expect($choices['more'])->toBeTrue()
            ->and($choices['opts'])->toHaveCount(PageRules::WALK_SIZE + 1);   // «Không có (trang gốc)» + the cap
    });
});

it('refuses a move the tree rules forbid at save time, saving nothing', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        $a = acPage('a');
        $b = acPage('b', position: 1);
        $b1 = acPage('b1', $b);
        $leaf = acPage('leaf', position: 2);

        $editor = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $leaf->ulid])
            ->set('parentUlid', $a->ulid)->assertHasNoErrors()->set('title', 'Đổi tên');
        Entry::query()->whereKey($a->id)->update(['parent_id' => $b1->id]);   // a is now on level 3
        $editor->call('save')->assertHasErrors('parentUlid')->assertSet('title', 'Đổi tên');
        expect($editor->errors()->first('parentUlid'))->toBe(__('entries.move_depth_page'));

        expect($leaf->fresh()?->parent_id)->toBeNull()
            ->and($leaf->translations()->value('title'))->toBe('T leaf');
    });
});

it('lists the website\'s pages for «Trang cha», greying the ones that cannot be the parent, without the Home page', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        acPage('home');
        $top = acPage('top', position: 1);
        $l2 = acPage('l2', $top);
        acPage('l3', $l2);
        $p = acPage('p', position: 2);
        acPage('p1', $p);

        $opts = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $p->ulid])->call('parentChoices')->effects['returns'][0]['opts'];
        $by = collect($opts)->mapWithKeys(fn (array $o): array => [$o['name'] => [$o['lv'], $o['why']]])->all();

        expect(array_keys($by))->toBe([__('entries.ed.par_none'), 'T top', 'T l2', 'T l3', 'T p', 'T p1'])
            ->and($by[__('entries.ed.par_none')])->toBe([0, null])
            ->and($by['T top'])->toBe([1, null])
            ->and($by['T l2'])->toBe([2, __('entries.ed.par_why_branch')])
            ->and($by['T l3'])->toBe([3, __('entries.ed.par_why_branch')])
            ->and($by['T p'])->toBe([1, __('entries.ed.par_why_self')])
            ->and($by['T p1'])->toBe([2, __('entries.ed.par_why_sub')]);

        // a new page (no sub-pages) may go under level 2, not level 3
        $new = collect(Livewire::actingAs($writer)->test(Editor::class)->call('parentChoices')->effects['returns'][0]['opts'])
            ->mapWithKeys(fn (array $o): array => [$o['name'] => $o['why']])->all();
        expect($new['T l2'])->toBeNull()->and($new['T l3'])->toBe(__('entries.ed.par_why_level', ['n' => 4]));
    });
});

it('locks «Trang cha» in a language other than the website\'s default one', function (): void {
    $writer = acWriter();

    app(Tenancy::class)->enter(1, function () use ($writer): void {
        SiteLocale::query()->create(['locale' => 'en', 'is_default' => false, 'enabled' => true]);
        $a = acPage('a');
        $b = acPage('b', position: 1);

        Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $b->ulid, 'locale' => 'en'])
            ->set('parentUlid', $a->ulid)
            ->assertSet('parentUlid', null)
            ->assertDispatched('bs-toast', text: __('entries.ed.shared_guard', ['lang' => Locales::label('vi')]), stay: true);
        expect($b->fresh()?->parent_id)->toBeNull();
    });
});

it('asks the policy on each «Trang cha» endpoint', function (): void {
    $viewer = acMember(['entries.view'], 'viewer');
    $own = acMember(['entries.view', 'entries.create', 'entries.edit.own'], 'author');

    $a = app(Tenancy::class)->enter(1, fn (): Entry => acPage('a'));
    $this->actingAs($viewer)->get('http://a.test/admin/content/create?parent='.$a->ulid)->assertForbidden();

    app(Tenancy::class)->enter(1, function () use ($viewer, $own, $a): void {
        $b = acPage('b', position: 1);

        Livewire::actingAs($viewer)->test(Editor::class, ['ulid' => $b->ulid])->assertForbidden();
        Livewire::actingAs($viewer)->test(Editor::class, ['parent' => $a->ulid])->assertForbidden();

        // an author may open a page of theirs, not move someone else's: the endpoints ask again
        $mine = acPage('mine', position: 2, author: $own);
        $reads = Livewire::actingAs($own)->test(Editor::class, ['ulid' => $mine->ulid]);
        $picks = Livewire::actingAs($own)->test(Editor::class, ['ulid' => $mine->ulid]);
        $mine->update(['author_id' => null]);
        $reads->call('parentChoices')->assertForbidden();
        $picks->set('parentUlid', $a->ulid)->assertForbidden();
        expect($mine->fresh()?->parent_id)->toBeNull();
    });
});

it('reads «Trang cha» on a flat budget, whatever the number of pages', function (): void {
    $writer = acWriter();

    $seed = function (int $from, int $to): void {
        app(Tenancy::class)->enter(1, function () use ($from, $to): void {
            $tops = [];
            foreach (range($from, $to) as $i) {
                $tops[] = $top = acPage("p{$i}", position: $i);
                acPage("p{$i}-a", $top);
            }
        });
    };
    $measure = function () use ($writer): array {
        return app(Tenancy::class)->enter(1, function () use ($writer): array {
            $page = Entry::query()->where('type', 'page')->whereNull('parent_id')->orderBy('id')->firstOrFail();
            app(PageParentChoices::class)->for($page);   // the website's languages are read once, then kept
            $choices = countQueries(fn () => app(PageParentChoices::class)->for($page));
            $editor = Livewire::actingAs($writer)->test(Editor::class, ['ulid' => $page->ulid]);
            $call = countQueries(fn () => $editor->call('parentChoices'));

            return [$choices, $call];
        });
    };

    $seed(1, 3);
    [$smallChoices, $smallCall] = $measure();
    $seed(4, 30);
    [$bigChoices, $bigCall] = $measure();

    expect($bigChoices)->toBe($smallChoices)->toBeLessThanOrEqual(4)
        ->and($bigCall)->toBe($smallCall);

    $open = fn (): int => countQueries(fn () => $this->actingAs($writer)->get('http://a.test/admin/content/'
        .app(Tenancy::class)->enter(1, fn (): string => Entry::query()->where('type', 'page')->orderBy('id')->value('ulid')).'/edit')->assertOk());
    $open();   // what the first request reads once and keeps
    $before = $open();
    $seed(31, 40);
    expect($open())->toBe($before);
});

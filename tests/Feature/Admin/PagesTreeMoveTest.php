<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * The Trang list's moves in the page tree (Entries\Index): a drop by the grip, «Chuyển lên /
 * xuống» and «Chuyển đến…», each asked of the policy, refused outside the tree in website order
 * (but «Chuyển đến…»), said in the design's words with «Hoàn tác», and drawn three levels deep.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function ptPage(?User $author, string $slug, ?Entry $parent = null, int $position = 0): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $author?->id, 'parent_id' => $parent?->id, 'position' => $position]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $page;
}

function ptEditor(): User
{
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view', 'entries.edit.any']));

    return $user->fresh() ?? $user;
}

/** @return list<string> */
function ptOrder(?Entry $parent): array
{
    return Entry::query()->where('type', 'page')
        ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent?->id))
        ->orderBy('position')->orderBy('id')->with('translations')->get()
        ->map(fn (Entry $e): string => (string) $e->translations->first()?->slug)->all();
}

it('moves a page one place up and down, says so, and «Hoàn tác» puts it back', function (): void {
    $user = ptEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        ptPage($user, 'a');
        $b = ptPage($user, 'b');
        ptPage($user, 'c');

        $list = Livewire::actingAs($user)->test(Index::class)
            ->call('movePageStep', $b->ulid, 'up')
            ->assertSet('toastMsg', __('entries.move_step_up', ['name' => 'T b']))
            ->assertSet('moveUndo.page', $b->ulid);
        expect(ptOrder(null))->toBe(['b', 'a', 'c']);

        $list->call('undoMovePage')->assertSet('toastMsg', __('entries.move_undone', ['name' => 'T b']))->assertSet('moveUndo', null);
        expect(ptOrder(null))->toBe(['a', 'b', 'c']);

        $list->call('movePageStep', $b->ulid, 'down')->assertSet('toastMsg', __('entries.move_step_down', ['name' => 'T b']));
        expect(ptOrder(null))->toBe(['a', 'c', 'b']);

        $list->call('movePageStep', $b->ulid, 'down')->assertDispatched('bs-toast', text: __('entries.move_at_bottom'));
        expect(ptOrder(null))->toBe(['a', 'c', 'b']);
    });
});

it('places a dropped page before or after a sibling, or first under a parent', function (): void {
    $user = ptEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $a = ptPage($user, 'a');
        $b = ptPage($user, 'b');
        $c = ptPage($user, 'c');
        ptPage($user, 'a1', $a);

        $list = Livewire::actingAs($user)->test(Index::class)->call('movePage', $c->ulid, null, 'before', $a->ulid);
        expect(ptOrder(null))->toBe(['c', 'a', 'b']);

        $list->call('movePage', $c->ulid, $a->ulid, 'child', null)
            ->assertSet('toastMsg', __('entries.move_done_in', ['name' => 'T c', 'parent' => 'T a']));
        expect(ptOrder($a))->toBe(['c', 'a1']);

        $list->call('movePage', $c->ulid, null, 'after', $a->ulid)
            ->assertSet('toastMsg', __('entries.move_done_out', ['name' => 'T c', 'group' => 'T a']));
        expect(ptOrder(null))->toBe(['a', 'c', 'b'])->and(ptOrder($a))->toBe(['a1']);

        // a refused drop says why and that the tree stays as it was
        $list->call('movePage', $a->ulid, $a->ulid, 'child', null)
            ->assertDispatched('bs-toast', text: __('entries.move_refused_cycle').' '.__('entries.move_tree_kept'), stay: true);
        expect($a->fresh()?->parent_id)->toBeNull();
    });
});

it('refuses the drag and the one-place moves outside the tree in website order, but not «Chuyển đến…»', function (): void {
    $user = ptEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $a = ptPage($user, 'a');
        $b = ptPage($user, 'b');

        Livewire::actingAs($user)->test(Index::class)
            ->set('search', 'T')
            ->call('movePageStep', $b->ulid, 'up')
            ->assertDispatched('bs-toast', text: __('entries.move_off_why'), stay: true)
            ->call('movePage', $b->ulid, $a->ulid, 'child', null)
            ->assertDispatched('bs-toast', text: __('entries.move_off_why'), stay: true);
        expect(ptOrder(null))->toBe(['a', 'b']);

        Livewire::actingAs($user)->test(Index::class)
            ->call('sortBy', 'name')
            ->call('movePageStep', $b->ulid, 'up')
            ->assertDispatched('bs-toast', text: __('entries.move_off_why'), stay: true)
            ->call('movePageTo', $b->ulid, $a->ulid)
            ->assertSet('toastMsg', __('entries.move_done_in', ['name' => 'T b', 'parent' => 'T a']));
        expect($b->fresh()?->parent_id)->toBe($a->id);
    });
});

it('never finds a page of another site', function (): void {
    $user = ptEditor();
    $other = app(Tenancy::class)->enter(2, fn (): Entry => ptPage(null, 'elsewhere'));

    app(Tenancy::class)->enter(1, function () use ($user, $other): void {
        $page = ptPage($user, 'here');

        Livewire::actingAs($user)->test(Index::class)
            ->call('movePageTo', $page->ulid, $other->ulid)
            ->assertDispatched('bs-toast', text: __('entries.row_stale'))
            ->call('movePageStep', $other->ulid, 'up')
            ->assertDispatched('bs-toast', text: __('entries.row_stale'))
            ->call('moveTargets', $other->ulid)
            ->assertReturned(null);
        expect($page->fresh()?->parent_id)->toBeNull();
    });

    app(Tenancy::class)->enter(2, fn () => expect($other->fresh()?->position)->toBe(0)->and($other->fresh()?->parent_id)->toBeNull());
});

it('lets a member who may edit their own pages move only those', function (): void {
    $own = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($own, $colleague): void {
        grantRole($own, 'author', ['entries.view', 'entries.edit.own']);
        $mine = ptPage($own, 'mine');
        $theirs = ptPage($colleague, 'theirs');

        // such a member opens on their own pages (a filter): the whole tree first
        Livewire::actingAs($own->fresh())->test(Index::class)
            ->call('clearAllFilters')
            ->call('movePageStep', $theirs->ulid, 'up')
            ->assertDispatched('bs-toast', text: __('entries.refused_others_edit', ['noun' => __('entries.noun_page')]), stay: true)
            ->call('movePageStep', $mine->ulid, 'down')
            ->assertSet('toastMsg', __('entries.move_step_down', ['name' => 'T mine']));
        expect(ptOrder(null))->toBe(['theirs', 'mine']);
    });
});

it('refuses every page move to a member limited to some categories and to a viewer', function (): void {
    $limited = ptEditor();
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($limited, $viewer): void {
        grantRole($viewer, 'viewer', ['entries.view']);
        $cat = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin tức']));
        MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $cat->id]);
        app(CategoryScopes::class)->forget();
        $a = ptPage($limited, 'a');
        $b = ptPage($limited, 'b');

        foreach ([$limited, $viewer] as $who) {
            Livewire::actingAs($who->fresh())->test(Index::class)
                ->call('movePageStep', $b->ulid, 'up')
                ->call('movePageTo', $b->ulid, $a->ulid)
                ->call('movePage', $b->ulid, null, 'before', $a->ulid)
                ->assertSet('moveUndo', null)
                ->call('moveTargets', $b->ulid)
                ->assertReturned(null);
        }
        expect(ptOrder(null))->toBe(['a', 'b']);
    });
});

it('offers every place «Chuyển đến…» can take, locking the ones the tree rules refuse', function (): void {
    $user = ptEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        ptPage($user, 'home');
        $a = ptPage($user, 'a');
        $a1 = ptPage($user, 'a1', $a);
        $a2 = ptPage($user, 'a2', $a1);
        $p = ptPage($user, 'p');
        $p1 = ptPage($user, 'p1', $p);

        $targets = Livewire::actingAs($user)->test(Index::class)->instance()->moveTargets($p->ulid);
        expect($targets['nested'] ?? null)->toBeFalse();
        $by = collect($targets['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');

        // the Home page and p's own branch are not places; p (two levels) fits under a level-1 page only
        expect($by->keys()->all())->toBe(['top', $a->ulid, $a1->ulid, $a2->ulid])
            ->and($by['top']['here'])->toBeTrue()
            ->and($by[$a->ulid]['why'])->toBeNull()
            ->and($by[$a->ulid]['label'])->toBe(__('entries.mv_becomes', ['n' => 2]))
            ->and($by[$a1->ulid]['why'])->toBe(__('entries.move_depth_branch_l1', ['n' => 2]))
            ->and($by[$a2->ulid]['lv'])->toBe(3);
        expect($p1->fresh()?->parent_id)->toBe($p->id);
    });
});

it('draws the page tree three levels deep, each level in its own website order', function (): void {
    $user = ptEditor();

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $b = ptPage($user, 'b', position: 1);
        $a = ptPage($user, 'a', position: 0);
        $b2 = ptPage($user, 'b2', $b, 1);
        ptPage($user, 'b1', $b, 0);
        ptPage($user, 'b22', $b2, 1);
        ptPage($user, 'b21', $b2, 0);
        ptPage($user, 'a1', $a);

        $html = Livewire::actingAs($user)->test(Index::class)->html();
        preg_match_all('/data-pk="[^"]+"[^>]*data-lv="(\d)"[^>]*data-name="T ([a-z0-9]+)"/', $html, $m);

        expect($m[2])->toBe(['a', 'a1', 'b', 'b1', 'b2', 'b21', 'b22'])
            ->and($m[1])->toBe(['1', '2', '1', '2', '2', '3', '3']);
    });
});

it('draws the grip and the move items only for who may move a page', function (): void {
    $user = ptEditor();
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($user, $viewer): void {
        grantRole($viewer, 'viewer', ['entries.view']);
        ptPage($user, 'home');
        ptPage($user, 'a');

        $html = Livewire::actingAs($user)->test(Index::class)->html();
        // the Home page has no grip and no «Chuyển đến…»; «a» has both
        expect(substr_count($html, 'data-grip'))->toBe(1)
            ->and(substr_count($html, 'data-move="to"'))->toBe(1)
            ->and(substr_count($html, 'data-move="up"'))->toBe(2);

        $seen = Livewire::actingAs($viewer->fresh())->test(Index::class)->html();
        expect($seen)->not->toContain('data-grip')->and($seen)->not->toContain('data-move=');
    });
});

<?php

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Cms\Actions\MovePage;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Exceptions\PageMoveRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Support\PageRules;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
 * Moving a page in a site's page tree (MovePage): the page goes with every page under it, both
 * sibling lists are renumbered 0..n, the tree keeps at most three levels and no loop, the Home page
 * keeps its place and has no sub-pages, and under nested addresses the branch moves its addresses
 * with a 301 from each old one.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function mpPage(string $slug, ?Entry $parent = null, string $status = 'draft', int $position = 0): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'parent_id' => $parent?->id, 'position' => $position]);
    $page->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
    if ($status === 'published') {
        $editor = User::query()->orderByDesc('id')->firstOrFail();   // the one mpPublisher() made
        app(PublishEntry::class)->handle($page->fresh() ?? $page, 'vi', $editor);
    }

    return $page->fresh() ?? $page;
}

/** Someone who may publish, for the pages mpPage() publishes. Call inside the tenancy context. */
function mpPublisher(): void
{
    grantRole(User::factory()->create(), 'editor', ['entries.edit.any', 'entries.publish']);
}

function mpNested(): void
{
    SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
}

/** @return list<string> */
function mpOrder(?Entry $parent): array
{
    return Entry::query()->where('type', 'page')
        ->when($parent === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parent?->id))
        ->orderBy('position')->orderBy('id')->with('translations')->get()
        ->map(fn (Entry $e): string => (string) $e->translations->first()?->slug)->all();
}

function mpRefusal(Closure $move): string
{
    try {
        $move();
    } catch (PageMoveRejectedException $e) {
        return $e->reason;
    }

    return 'moved';
}

it('reorders a page among its siblings and renumbers them 0..n without touching their last edit', function (): void {
    $moved = app(Tenancy::class)->enter(1, function (): int {
        $a = mpPage('a');
        $b = mpPage('b');
        $c = mpPage('c');
        $edited = $a->updated_at;

        $result = app(MovePage::class)->handle($c, null, 0);

        expect(mpOrder(null))->toBe(['c', 'a', 'b'])
            ->and(Entry::query()->whereKey([$a->id, $b->id, $c->id])->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2])
            ->and($result)->toMatchArray(['from_parent' => null, 'from_position' => 2, 'parent' => null, 'position' => 0, 'reparented' => false])
            ->and($a->fresh()?->updated_at?->equalTo($edited))->toBeTrue();

        return $c->id;
    });

    expect(AuditLog::query()->where('site_id', 1)->where('action', 'cms.page.moved')->where('auditable_id', $moved)->exists())->toBeTrue();
});

it('moves a page with its sub-pages under another parent and renumbers both lists', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $b = mpPage('b');
        $x = mpPage('x', $a);
        $y = mpPage('y', $a);
        $kid = mpPage('kid', $x);

        $result = app(MovePage::class)->handle($x, $b, 5);

        expect($x->fresh()?->parent_id)->toBe($b->id)
            ->and($kid->fresh()?->parent_id)->toBe($x->id)
            ->and(mpOrder($a))->toBe(['y'])
            ->and($y->fresh()?->position)->toBe(0)
            ->and(mpOrder($b))->toBe(['x'])
            ->and($result)->toMatchArray(['from_parent' => $a->id, 'from_position' => 0, 'parent' => $b->id, 'position' => 0, 'reparented' => true]);
    });
});

it('refuses each broken tree with its own reason and changes nothing', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $home = mpPage('home');
        $a = mpPage('a');
        $b = mpPage('b', $a);
        $c = mpPage('c', $b);
        $d = mpPage('d');
        $e = mpPage('e', $d);
        $move = app(MovePage::class);

        expect(mpRefusal(fn () => $move->handle($home, $a, 0)))->toBe('home_locked')
            ->and(mpRefusal(fn () => $move->handle($d, $home, 0)))->toBe('parent_home')
            ->and(mpRefusal(fn () => $move->handle($a, $a, 0)))->toBe('cycle')
            ->and(mpRefusal(fn () => $move->handle($a, $c, 0)))->toBe('cycle')
            // d has two levels: under b (level 2) it would reach level 4
            ->and(mpRefusal(fn () => $move->handle($d, $b, 0)))->toBe('too_deep')
            ->and(mpRefusal(fn () => $move->handle($e, $c, 0)))->toBe('too_deep')
            ->and($d->fresh()?->parent_id)->toBeNull()
            ->and($e->fresh()?->parent_id)->toBe($d->id);

        // the Home page may still be reordered where it is
        expect(mpRefusal(fn () => $move->handle($home, null, 3)))->toBe('moved');
    });
});

it('says how many levels the refused branch has', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $d = mpPage('d');
        mpPage('e', $d);

        try {
            app(MovePage::class)->handle($d, mpPage('deep', mpPage('mid', $a)), 0);
            $this->fail('the move was not refused');
        } catch (PageMoveRejectedException $refused) {
            expect($refused->reason)->toBe('too_deep')->and($refused->height)->toBe(2);
        }
    });
});

it('counts sub-pages in the Trash, so restoring one never makes a fourth level', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $mid = mpPage('mid', $a);
        $p = mpPage('p');
        $gone = mpPage('gone', $p);
        $gone->delete();

        expect(PageRules::heightOf($p))->toBe(2)
            ->and(mpRefusal(fn () => app(MovePage::class)->handle($p, $mid, 0)))->toBe('too_deep');

        app(MovePage::class)->handle($p, $a, 0);
        $gone->restore();

        expect(PageRules::levelOf($gone->fresh() ?? $gone))->toBe(3);
    });
});

it('refuses a parent of another site as a page that does not exist here', function (): void {
    $other = app(Tenancy::class)->enter(2, fn (): Entry => mpPage('elsewhere'));

    app(Tenancy::class)->enter(1, function () use ($other): void {
        $page = mpPage('here');

        expect(mpRefusal(fn () => app(MovePage::class)->handle($page, $other, 0)))->toBe('parent_invalid')
            ->and($page->fresh()?->parent_id)->toBeNull();
    });
});

it('keeps every address under flat page addresses', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        mpPublisher();
        $a = mpPage('dich-vu', status: 'published');
        $page = mpPage('thiet-ke', status: 'published');

        $result = app(MovePage::class)->handle($page, $a, 0);

        expect($result['paths'])->toBe([])
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/thiet-ke')
            ->and(Redirect::query()->count())->toBe(0);
    });
});

it('moves the branch addresses under nested addresses, with a 301 each, and moves them back without a loop', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        mpPublisher();
        mpNested();
        $a = mpPage('dich-vu', status: 'published');
        $page = mpPage('thiet-ke', status: 'published');
        $kid = mpPage('logo', $page, 'published');

        $result = app(MovePage::class)->handle($page, $a, 0);

        expect($result['paths'])->toBe(['vi' => ['from' => '/thiet-ke', 'to' => '/dich-vu/thiet-ke']])
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/dich-vu/thiet-ke')
            ->and(SiteRoute::query()->where('entry_id', $kid->id)->value('path'))->toBe('/dich-vu/thiet-ke/logo')
            ->and(Redirect::query()->where('from_path', '/thiet-ke')->value('to_path'))->toBe('/dich-vu/thiet-ke')
            ->and(Redirect::query()->where('from_path', '/thiet-ke/logo')->value('to_path'))->toBe('/dich-vu/thiet-ke/logo');

        // «Hoàn tác»: back to the top, where it was
        app(MovePage::class)->handle($page->fresh() ?? $page, null, $result['from_position']);

        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/thiet-ke')
            ->and(SiteRoute::query()->where('entry_id', $kid->id)->value('path'))->toBe('/thiet-ke/logo')
            ->and(Redirect::query()->where('from_path', '/thiet-ke')->exists())->toBeFalse()
            ->and(Redirect::query()->where('from_path', '/thiet-ke/logo')->exists())->toBeFalse()
            ->and(Redirect::query()->where('from_path', '/dich-vu/thiet-ke')->value('to_path'))->toBe('/thiet-ke')
            ->and(mpOrder(null))->toBe(['dich-vu', 'thiet-ke']);
    });
});

it('refuses a nested move onto an address another page holds and leaves the tree as it was', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        mpPublisher();
        mpNested();
        $a = mpPage('dich-vu', status: 'published');
        mpPage('logo', $a, 'published');
        $page = mpPage('logo', status: 'published');

        try {
            app(MovePage::class)->handle($page, $a, 0);
            $this->fail('the move was not refused');
        } catch (PageMoveRejectedException $refused) {
            expect($refused->reason)->toBe('path_taken')->and($refused->path)->toBe('/dich-vu/logo');
        }

        expect($page->fresh()?->parent_id)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/logo');
    });
});

it('ends two crossing moves with one refused and no loop', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $b = mpPage('b');
        $move = app(MovePage::class);

        // each was checked against the tree before the other moved: the second, judged under the lock on
        // the tree as it is now, is refused
        $move->handle($a, $b, 0);

        expect(mpRefusal(fn () => $move->handle($b, $a, 0)))->toBe('cycle')
            ->and($b->fresh()?->parent_id)->toBeNull()
            ->and($a->fresh()?->parent_id)->toBe($b->id);
    });
});

it('ends two moves that each fit alone with one refused and no fourth level', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $b = mpPage('b', $a);
        $x = mpPage('x');
        $y = mpPage('y', $x);
        $move = app(MovePage::class);

        // x (two levels) fits under a (level 1); b (level 2) fits under x (level 1) — not both
        $move->handle($x, $a, 0);

        expect(mpRefusal(fn () => $move->handle($b, $y, 0)))->toBe('too_deep')
            ->and(PageRules::levelOf($y->fresh() ?? $y))->toBe(3);
    });
});

it('asks the policy of the person signed in', function (): void {
    $editor = User::factory()->create();
    $other = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor, $other): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.own']);
        $mine = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $theirs = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $other->id]);
        Auth::setUser($editor->fresh() ?? $editor);

        expect(fn () => app(MovePage::class)->handle($theirs, null, 0))->toThrow(AuthorizationException::class);
        app(MovePage::class)->handle($mine, null, 5);
        expect($mine->fresh()?->position)->toBe(1);
    });
});

it('moves a nested branch in two languages with the page under it, and a draft page under it gets no address', function (): void {
    DB::connection('pgsql_admin')->table('site_locales')->insert(['site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

    app(Tenancy::class)->enter(1, function (): void {
        mpPublisher();
        mpNested();
        $editor = User::query()->orderByDesc('id')->firstOrFail();
        $both = function (string $vi, string $en, ?Entry $parent, bool $publish) use ($editor): Entry {
            $page = mpPage($vi, $parent);
            $page->translations()->create(['locale' => 'en', 'title' => 'E '.$en, 'slug' => $en, 'status' => 'draft', 'body' => ['blocks' => []]]);
            foreach ($publish ? ['vi', 'en'] : [] as $locale) {
                app(PublishEntry::class)->handle($page->fresh() ?? $page, $locale, $editor);
            }

            return $page->fresh() ?? $page;
        };
        $services = $both('dich-vu', 'services', null, true);
        $design = $both('thiet-ke', 'design', null, true);
        $logo = $both('logo', 'logo', $design, true);
        $samples = $both('mau', 'samples', $logo, true);
        $draft = $both('nhap', 'draft', $logo, false);
        $path = fn (Entry $page, string $locale): ?string => SiteRoute::query()->where('entry_id', $page->id)->where('locale', $locale)->value('path');

        expect($path($samples, 'en'))->toBe('/design/logo/samples');

        $result = app(MovePage::class)->handle($logo, $services, 0);

        expect($result['paths'])->toHaveKeys(['vi', 'en'])
            ->and($result['paths']['en'])->toBe(['from' => '/design/logo', 'to' => '/services/logo'])
            ->and($path($logo, 'vi'))->toBe('/dich-vu/logo')
            ->and($path($samples, 'vi'))->toBe('/dich-vu/logo/mau')
            ->and($path($samples, 'en'))->toBe('/services/logo/samples')
            ->and(Redirect::query()->where('locale', 'en')->where('from_path', '/design/logo/samples')->value('to_path'))->toBe('/services/logo/samples')
            ->and(Redirect::query()->where('locale', 'vi')->where('from_path', '/thiet-ke/logo/mau')->value('to_path'))->toBe('/dich-vu/logo/mau')
            ->and(SiteRoute::query()->where('entry_id', $draft->id)->exists())->toBeFalse()
            ->and(Redirect::query()->where('entry_id', $draft->id)->exists())->toBeFalse()
            ->and(PageRules::levelOf($samples->fresh() ?? $samples))->toBe(3);
    });
});

it('steps among the siblings the page has when the move runs, never back under the parent the screen showed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $b = mpPage('b', position: 1);
        mpPage('x', $a);
        $page = mpPage('p', $a, position: 1);
        mpPage('y', $b);
        $shown = $page->fresh() ?? $page;   // the row as the screen read it: under a, second
        $move = app(MovePage::class);

        // another member drops it under b, after y, meanwhile
        $move->handle($page, $b, 1);
        $result = $move->step($shown, 'up');

        expect($result['parent'])->toBe($b->id)
            ->and($result['from_parent'])->toBe($b->id)
            ->and(mpOrder($b))->toBe(['p', 'y'])
            ->and(mpOrder($a))->toBe(['x'])
            ->and(mpRefusal(fn () => $move->step($shown, 'up')))->toBe('at_top');
    });
});

it('places a page beside the neighbour found inside the lock, and refuses one that left the list', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $b = mpPage('b', position: 1);
        $c = mpPage('c', position: 2);
        $move = app(MovePage::class);

        $move->beside($c, null, 'after', $a->ulid);
        expect(mpOrder(null))->toBe(['a', 'c', 'b']);

        $b->delete();
        expect(mpRefusal(fn () => $move->beside($a, null, 'after', $b->ulid)))->toBe('anchor_gone')
            ->and(mpRefusal(fn () => $move->beside($a, $c, 'before', $c->ulid)))->toBe('anchor_gone')
            ->and(mpOrder(null))->toBe(['a', 'c']);
    });
});

it('reports the parent and place the page really left, for an undo that returns it there', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = mpPage('a');
        $b = mpPage('b', position: 1);
        $c = mpPage('c', position: 2);
        mpPage('c0', $c);
        $page = mpPage('p', $a);
        $shown = $page->fresh() ?? $page;   // the screen read it under a
        $move = app(MovePage::class);

        $move->handle($page, $c, 1);   // moved under c, second, meanwhile
        $result = $move->into($shown, $b);

        expect($result['from_parent'])->toBe($c->id)->and($result['from_position'])->toBe(1);

        $move->handle($page->fresh() ?? $page, $c, $result['from_position']);
        expect(mpOrder($c))->toBe(['c0', 'p'])->and(mpOrder($b))->toBe([]);
    });
});

it('refuses to renumber a sibling list past the cap rather than read it unbounded', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $parent = mpPage('cha');
        $page = mpPage('p');
        DB::statement(   // in the test's own transaction, so the parent it names is there
            'insert into entries (ulid, site_id, type, status, parent_id, position, created_at, updated_at)
             select lpad(g::text, 26, \'0\'), 1, \'page\', \'draft\', ?, g, now(), now() from generate_series(1, ?) g',
            [$parent->id, MovePage::SIBLINGS_MAX + 1],
        );

        expect(mpRefusal(fn () => app(MovePage::class)->handle($page, $parent, 0)))->toBe('siblings_too_many')
            ->and($page->fresh()?->parent_id)->toBeNull();
    });
});

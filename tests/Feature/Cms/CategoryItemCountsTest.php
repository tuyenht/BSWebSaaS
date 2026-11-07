<?php

use App\Modules\Admin\Livewire\Catalog\Index as ProductIndex;
use App\Modules\Admin\Livewire\Entries\Index as EntryIndex;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\UpdateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryItemCounts;
use App\Modules\Cms\Support\EntryCategoryWriter;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteModule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * A category's item total (taxonomies.items_count) counts the posts and products whose MAIN
 * category it is (pivot position 0) and that are not in the trash, whatever their status — what
 * the post list filtered by that category shows under "Tất cả". Pages, the other categories of an
 * item and its facet terms are not counted. Every write path moves it in the same transaction,
 * through one service (CategoryItemCounts), and the nightly reconcile corrects and logs a drift.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function ccCategory(string $name, string $vocabulary = 'post_cat'): Taxonomy
{
    return app(CreateTaxonomy::class)->handle($vocabulary, null, new TaxonomyData(names: ['vi' => $name]));
}

/** @param  list<Taxonomy>  $categories  index 0 = the main one */
function ccPost(array $categories, string $type = 'post', string $status = 'published'): Entry
{
    $post = Entry::query()->create(['type' => $type, 'status' => $status]);
    $post->translations()->create(['locale' => 'vi', 'title' => 'Bài '.Str::random(6), 'slug' => Str::lower(Str::random(10)), 'status' => $status, 'body' => ['blocks' => []]]);
    $post->assignCategories(array_map(fn (Taxonomy $category): int => $category->id, $categories));

    return $post;
}

/** @param  list<Taxonomy>  $categories */
function ccProduct(array $categories): Product
{
    $product = Product::query()->create(['status' => 'draft']);
    $product->translations()->create(['locale' => 'vi', 'name' => 'Sản phẩm '.Str::random(6), 'slug' => Str::lower(Str::random(10))]);
    $product->assignCategories(array_map(fn (Taxonomy $category): int => $category->id, $categories));

    return $product;
}

/** @return list<int> the stored totals of these categories, in order */
function ccCounts(Taxonomy ...$categories): array
{
    $stored = Taxonomy::query()->whereKey(array_map(fn (Taxonomy $category): int => $category->id, $categories))->pluck('items_count', 'id');

    return array_map(fn (Taxonomy $category): int => (int) $stored[$category->id], $categories);
}

/** Set a stored total behind the service's back (a drift). Inside the category's site context. */
function ccSetCount(Taxonomy $category, int $count): void
{
    DB::table('taxonomies')->where('site_id', $category->site_id)->where('id', $category->id)->update(['items_count' => $count]);
}

/**
 * The SQL run by $callback.
 *
 * @return list<string>
 */
function ccStatements(Closure $callback): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });
    $callback();

    return $statements;
}

it('counts a post under its main category only, in any status, and never a page', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');

        ccPost([$a, $b], status: 'draft');
        ccPost([$a], status: 'published');
        ccPost([$a], status: 'archived');
        ccPost([$a], type: 'page');

        expect(ccCounts($a, $b))->toBe([3, 0]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('moves the count when the editor changes the main category, and not for another category or a facet term', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        $c = ccCategory('C');
        $sector = ccCategory('Ô tô', 'sector');
        $post = ccPost([$a]);
        $writer = app(EntryCategoryWriter::class);

        $writer->assign($post, [$b->id, $a->id], null);
        expect(ccCounts($a, $b))->toBe([0, 1]);

        // Another category added after the main one, and a facet term: no change.
        $writer->assign($post, [$b->id, $a->id, $c->id], null);
        $post->taxonomies()->attach($sector->id, ['position' => Entry::FACET_POSITION_BASE, 'site_id' => 1]);
        $writer->assign($post, [$b->id, $c->id], null);
        expect(ccCounts($a, $b, $c, $sector))->toBe([0, 1, 0, 0]);

        $writer->assign($post, [], null);
        expect(ccCounts($b))->toBe([0]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('updates both categories of one save in id order, and a decrement on 0 stays 0 without an error', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        $post = ccPost([$b]);

        $updates = array_values(array_filter(
            ccStatements(fn () => $post->assignCategories([$a->id])),
            fn (string $sql): bool => str_starts_with($sql, 'update taxonomies set items_count'),
        ));
        expect($updates)->toHaveCount(2)->and(ccCounts($a, $b))->toBe([1, 0]);

        // Already drifted to 0: the next decrement is clamped, not a CHECK violation.
        ccSetCount($a, 0);
        $post->assignCategories([$b->id]);
        expect(ccCounts($a, $b))->toBe([0, 1]);
    });
    expect(categoryCountDrift(1))->toHaveCount(0);
});

it('takes the categories of one change in id order', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        $order = [];
        DB::listen(function (QueryExecuted $query) use (&$order): void {
            if (str_starts_with(strtolower($query->sql), 'update taxonomies set items_count')) {
                $order[] = $query->bindings[2];
            }
        });

        app(CategoryItemCounts::class)->adjust([$b->id => -1, $a->id => 1]);
        app(CategoryItemCounts::class)->adjust([$a->id => -1, $b->id => 1]);

        expect($order)->toBe([$a->id, $b->id, $a->id, $b->id]);
    });
});

it('counts a duplicated post under the same main category', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        $a = ccCategory('A');
        $b = ccCategory('B');
        $post = ccPost([$a, $b]);

        app(DuplicateEntry::class)->handle($post->fresh(), $editor->fresh());

        expect(ccCounts($a, $b))->toBe([2, 0]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('keeps the counts through a rename and a move, and follows the items a delete re-files or detaches', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        $c = ccCategory('C');
        ccPost([$a, $c]);
        ccPost([$c, $a]);
        ccPost([$a]);
        ccPost([$a])->delete();   // in the trash: not counted before or after

        app(UpdateTaxonomy::class)->handle($a, new TaxonomyData(names: ['vi' => 'A mới']));
        app(MoveTaxonomy::class)->handle($a, $b, 0);
        expect(ccCounts($a, $b, $c))->toBe([2, 0, 1]);

        app(MoveTaxonomy::class)->handle($a->fresh(), null, 0);
        app(DeleteTaxonomy::class)->handle($a->fresh(), CategoryItemsOnDelete::Reassign, $b);
        expect(ccCounts($b, $c))->toBe([2, 1]);

        // Detached: the post whose main category goes is counted under the one that moves up.
        app(DeleteTaxonomy::class)->handle($c->fresh(), CategoryItemsOnDelete::Detach);
        expect(ccCounts($b))->toBe([3]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('locks the posts a category delete re-files before it reads them, and moves the counts once at the end', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        ccPost([$a]);
        ccPost([$a]);

        $sql = ccStatements(fn () => app(DeleteTaxonomy::class)->handle($a, CategoryItemsOnDelete::Reassign, $b));
        $lock = array_key_first(array_filter($sql, fn (string $s): bool => str_contains($s, 'from "entries"') && str_ends_with($s, 'for update')));
        $read = array_key_first(array_filter($sql, fn (string $s): bool => str_contains($s, 'join entries as i')));   // the counter's reading of the main categories
        $counts = array_keys(array_filter($sql, fn (string $s): bool => str_starts_with($s, 'update') && str_contains($s, 'items_count')));

        expect($lock)->not->toBeNull()
            ->and($lock)->toBeLessThan($read)
            ->and($counts)->not->toBe([])
            ->and(min($counts))->toBeGreaterThan($lock)
            ->and(ccCounts($b))->toBe([2]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('follows the products a category delete re-files', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('Đồ gá', 'product_cat');
        $b = ccCategory('Khuôn', 'product_cat');
        ccProduct([$a]);
        ccProduct([$a, $b]);

        expect(ccCounts($a, $b))->toBe([2, 0]);
        app(DeleteTaxonomy::class)->handle($a, CategoryItemsOnDelete::Detach);
        expect(ccCounts($b))->toBe([1]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('takes a post out of its count in the trash and puts it back on restore, from the list row buttons', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = ccCategory('A');
        $post = ccPost([$a]);
        $other = ccPost([$a]);
        $list = Livewire::actingAs($editor->fresh())->test(EntryIndex::class);

        $list->call('trash', $post->ulid);
        expect(ccCounts($a))->toBe([1]);
        Entry::withTrashed()->findOrFail($post->id)->delete();   // trashed again: counted out once
        expect(ccCounts($a))->toBe([1]);
        $list->call('restore', $post->ulid);
        expect(ccCounts($a))->toBe([2]);

        // A purge from the trash changes nothing more; a live item deleted for good leaves the count.
        $list->call('trash', $post->ulid)->call('forceDelete', $post->ulid);
        expect(ccCounts($a))->toBe([1]);
        $other->forceDelete();
        expect(ccCounts($a))->toBe([0]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('moves the counts of a bulk trash, restore and purge with one update per category, whatever the number of posts', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = ccCategory('A');
        $b = ccCategory('B');
        $posts = [ccPost([$a]), ccPost([$a]), ccPost([$a]), ccPost([$b, $a]), ccPost([$b])];
        $ulids = array_map(fn (Entry $post): string => $post->ulid, $posts);

        $trash = ccStatements(fn () => Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
            ->set('selected', $ulids)->call('bulkTrash'));
        $counterUpdates = array_filter($trash, fn (string $sql): bool => str_starts_with($sql, 'update taxonomies set items_count'));

        expect(ccCounts($a, $b))->toBe([0, 0])
            ->and($counterUpdates)->toHaveCount(2);      // one per category, not one per post

        Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
            ->set('tab', 'trash')->set('selected', $ulids)->call('bulkRestore');
        expect(ccCounts($a, $b))->toBe([3, 2]);

        Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
            ->set('selected', array_slice($ulids, 0, 2))->call('bulkTrash');
        Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
            ->set('tab', 'trash')->set('selected', array_slice($ulids, 0, 2))->call('bulkForceDelete');
        expect(ccCounts($a, $b))->toBe([1, 2]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('adds the same few counter queries to a bulk trash of 2 or of 6 posts', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = ccCategory('A');
        $counterQueries = function (int $n) use ($editor, $a): int {
            $ulids = array_map(fn (): string => ccPost([$a])->ulid, range(1, $n));
            $sql = ccStatements(fn () => Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
                ->set('selected', $ulids)->call('bulkTrash'));

            return count(array_filter($sql, fn (string $s): bool => str_contains($s, 'items_count') || str_contains($s, 'entry_taxonomies')));
        };

        expect($counterQueries(2))->toBe($counterQueries(6));
        expect(ccCounts($a))->toBe([0]);
    });
});

it('drops the count change of a bulk row that rolled back', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $kept = ccPost([$a]);
        $failed = ccPost([$a]);
        $counts = app(CategoryItemCounts::class);

        $counts->batch(function () use ($counts, $kept, $failed): void {
            $counts->batch(fn () => DB::transaction(fn () => $kept->delete()));
            try {
                $counts->batch(fn () => DB::transaction(function () use ($failed): void {
                    $failed->delete();

                    throw new RuntimeException('row failed');
                }));
            } catch (RuntimeException) {
                // the row's savepoint rolled back, and its count change with it
            }
        });

        expect(ccCounts($a))->toBe([1])
            ->and(Entry::query()->whereKey($failed->id)->exists())->toBeTrue();
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('keeps the counts of a bulk trash true when one row fails after its delete, through the post list', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = ccCategory('A');
        $posts = [ccPost([$a]), ccPost([$a]), ccPost([$a])];
        $failed = $posts[1];
        // Runs after the counter's own listener has taken the row out of A: only the row's
        // savepoint and its own batch can put that change back.
        Entry::deleted(function (Entry $entry) use ($failed): void {
            if ($entry->id === $failed->id) {
                throw new RuntimeException('row failed after its delete');
            }
        });

        Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
            ->set('selected', array_map(fn (Entry $post): string => $post->ulid, $posts))->call('bulkTrash');

        expect(Entry::query()->whereKey($failed->id)->exists())->toBeTrue()
            ->and(Entry::query()->whereKey([$posts[0]->id, $posts[2]->id])->exists())->toBeFalse()
            ->and(ccCounts($a))->toBe([1]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('takes a product out of its count in the trash and back on restore, from the product list', function (): void {
    $manager = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($manager): void {
        grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']);
        SiteModule::query()->create(['module_key' => 'catalog', 'enabled' => true]);
        $a = ccCategory('Đồ gá', 'product_cat');
        $b = ccCategory('Khuôn', 'product_cat');
        $product = ccProduct([$a]);
        expect(ccCounts($a))->toBe([1]);

        $product->assignCategories([$b->id]);   // the product editor's save
        expect(ccCounts($a, $b))->toBe([0, 1]);

        $list = Livewire::actingAs($manager->fresh())->test(ProductIndex::class);
        $list->call('trash', $product->ulid);
        expect(ccCounts($b))->toBe([0]);
        $list->call('restore', $product->ulid);
        expect(ccCounts($b))->toBe([1]);
        $list->call('restore', $product->ulid);   // a live product: restore() only saves it again
        expect(ccCounts($b))->toBe([1]);
        $list->call('trash', $product->ulid)->call('forceDelete', $product->ulid);
        expect(ccCounts($b))->toBe([0]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('corrects a drifted count, logs it, and finds nothing on a second run', function (): void {
    [$a, $b] = app(Tenancy::class)->enter(1, function (): array {
        $a = ccCategory('A');
        $b = ccCategory('B');
        ccPost([$a]);
        ccPost([$a]);
        ccSetCount($a, 7);
        ccSetCount($b, 3);

        return [$a, $b];
    });
    Log::spy();

    $this->artisan('cms:reconcile-category-counts')->expectsOutputToContain('corrected 2')->assertSuccessful();

    app(Tenancy::class)->enter(1, fn () => expect(ccCounts($a, $b))->toBe([2, 0]));
    Log::shouldHaveReceived('warning')->with('Category item count drifted', ['site_id' => 1, 'taxonomy_id' => $a->id, 'from' => 7, 'to' => 2])->once();
    Log::shouldHaveReceived('warning')->with('Category item count drifted', ['site_id' => 1, 'taxonomy_id' => $b->id, 'from' => 3, 'to' => 0])->once();

    $this->artisan('cms:reconcile-category-counts')->expectsOutputToContain('corrected 0')->assertSuccessful();
});

it('reconciles each site in its own context, never counting another site\'s posts', function (): void {
    $one = app(Tenancy::class)->enter(1, function (): Taxonomy {
        $category = ccCategory('Tin tức');
        ccPost([$category]);
        ccSetCount($category, 0);

        return $category;
    });
    $two = app(Tenancy::class)->enter(2, function (): Taxonomy {
        $category = ccCategory('Tin tức');
        ccPost([$category]);
        ccPost([$category]);
        ccPost([$category]);
        ccSetCount($category, 0);

        return $category;
    });

    $this->artisan('cms:reconcile-category-counts')->expectsOutputToContain('on 2 site(s); corrected 2')->assertSuccessful();

    app(Tenancy::class)->enter(1, fn () => expect(ccCounts($one))->toBe([1]));
    app(Tenancy::class)->enter(2, fn () => expect(ccCounts($two))->toBe([3]));
});

it('reconciles a site across several chunks', function (): void {
    $categories = app(Tenancy::class)->enter(1, function (): array {
        $categories = [ccCategory('A'), ccCategory('B'), ccCategory('C'), ccCategory('D'), ccCategory('E')];
        foreach ($categories as $n => $category) {
            foreach (range(0, $n) as $ignored) {
                ccPost([$category]);
            }
            ccSetCount($category, 0);
        }

        return $categories;
    });

    $this->artisan('cms:reconcile-category-counts', ['--chunk' => 2])
        ->expectsOutputToContain('Checked 5 categor(ies) on 2 site(s); corrected 5')->assertSuccessful();

    app(Tenancy::class)->enter(1, fn () => expect(ccCounts(...$categories))->toBe([1, 2, 3, 4, 5]));
    expect(categoryCountDrift(1))->toBe([]);
});

it('runs the reconcile every night, never two at once', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'cms:reconcile-category-counts'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 20 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('files a category listed twice once, at its first place, and counts it once', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');

        $post = ccPost([$a, $b, $a]);
        expect($post->taxonomies()->pluck('entry_taxonomies.position', 'taxonomies.id')->map(fn ($p): int => (int) $p)->all())
            ->toBe([$a->id => 0, $b->id => 1])
            ->and(ccCounts($a, $b))->toBe([1, 0]);

        $post->assignCategories([$b->id, $a->id, $b->id]);
        expect(ccCounts($a, $b))->toBe([0, 1]);

        $product = ccProduct([]);
        $product->assignCategories([$a->id, $a->id]);
        expect(ccCounts($a))->toBe([1]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('locks the item before it reads the old main category, so two saves at once queue', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        $post = ccPost([$a]);

        $sql = ccStatements(fn () => $post->assignCategories([$b->id]));
        $lock = array_key_first(array_filter($sql, fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, 'from "entries"') && str_ends_with($s, 'for update')));
        $read = array_key_first(array_filter($sql, fn (string $s): bool => str_contains($s, 'from "entry_taxonomies"')));

        expect($lock)->not->toBeNull()->and($lock)->toBeLessThan($read);
    });
});

it('takes the trash state from the locked row, so a stale copy of the item never counts twice', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        $b = ccCategory('B');
        $post = ccPost([$a]);
        ccPost([$a]);
        // Two requests that loaded the same live post.
        $first = Entry::query()->findOrFail($post->id);
        $second = Entry::query()->findOrFail($post->id);

        $first->delete();
        $second->delete();
        expect(ccCounts($a))->toBe([1]);

        // A save from a tab that still sees it live: the trashed post moves without counting.
        $second->assignCategories([$b->id]);
        expect(ccCounts($a, $b))->toBe([1, 0]);

        $first = Entry::withTrashed()->findOrFail($post->id);
        $second = Entry::withTrashed()->findOrFail($post->id);
        $first->restore();
        $second->restore();
        expect(ccCounts($a, $b))->toBe([1, 1]);
    });
    expect(categoryCountDrift(1))->toBe([]);
});

it('counts without locks and corrects only the drifted categories under the counter\'s own lock', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = ccCategory('A');
        ccCategory('B');
        ccPost([$a]);
        ccSetCount($a, 4);
    });

    $sql = ccStatements(fn () => $this->artisan('cms:reconcile-category-counts')->expectsOutputToContain('corrected 1')->assertSuccessful());
    $locked = array_values(array_filter($sql, fn (string $s): bool => str_contains($s, 'from "taxonomies"') && str_contains($s, ' for ')));

    expect($locked)->toHaveCount(1)
        ->and($locked[0])->toEndWith('for no key update')
        ->and(array_filter($sql, fn (string $s): bool => str_ends_with($s, 'for update')))->toBe([]);
});

it('reconciles the other sites when one fails, logs it, and exits non-zero', function (): void {
    $one = app(Tenancy::class)->enter(1, function (): Taxonomy {
        $category = ccCategory('A');
        ccPost([$category]);
        ccSetCount($category, 5);

        return $category;
    });
    $two = app(Tenancy::class)->enter(2, function (): Taxonomy {
        $category = ccCategory('A');
        ccPost([$category]);
        ccPost([$category]);
        ccSetCount($category, 0);

        return $category;
    });
    Log::spy();
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context): void {
        if ($context['site_id'] === 1) {
            throw new RuntimeException('log store unavailable');
        }
    });

    $this->artisan('cms:reconcile-category-counts')
        ->expectsOutputToContain('on 2 site(s); corrected 1')
        ->expectsOutputToContain('Failed on 1 site(s)')
        ->assertFailed();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Category item count reconcile failed for a site'
        && $context['site_id'] === 1
        && $context['exception'] instanceof Throwable
        && $context['exception']->getMessage() === 'log store unavailable')->once();
    app(Tenancy::class)->enter(1, fn () => expect(ccCounts($one))->toBe([5]));   // its correction rolled back
    app(Tenancy::class)->enter(2, fn () => expect(ccCounts($two))->toBe([2]));
});

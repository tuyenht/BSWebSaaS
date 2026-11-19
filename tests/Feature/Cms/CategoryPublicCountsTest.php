<?php

use App\Modules\Admin\Livewire\Catalog\Index as ProductIndex;
use App\Modules\Admin\Livewire\Entries\Index as EntryIndex;
use App\Modules\Catalog\Actions\ChangeProductMainCategory;
use App\Modules\Catalog\Actions\ChangeProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\DeleteTaxonomy;
use App\Modules\Cms\Actions\Taxonomies\MoveTaxonomy;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Enums\CategoryChildrenOnDelete;
use App\Modules\Cms\Enums\CategoryItemsOnDelete;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryPublicCounts;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * A category's public totals per language (taxonomy_translations.public_own_count /
 * public_tree_count): how many items a visitor sees on its page in that language — its own items
 * (the default layout) or its whole branch, each item once (the card layout). Only public items
 * count: a published post with its translation published there (main category only), a published
 * product with a translation there (every category it is filed under; the catalog root counts every
 * one). Every write path moves them in the same transaction, through one service
 * (CategoryPublicCounts), and the nightly reconcile corrects and logs a drift.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $admin = DB::connection('pgsql_admin');
    foreach ([1, 2] as $site) {
        $admin->table('site_locales')->insert(['site_id' => $site, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'is_public' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A category named in Vietnamese and English. */
function pubCategory(string $name, string $vocabulary = 'post_cat', ?Taxonomy $parent = null): Taxonomy
{
    return app(CreateTaxonomy::class)->handle($vocabulary, $parent, new TaxonomyData(names: ['vi' => $name, 'en' => $name.' EN']));
}

/**
 * A post filed under $categories (index 0 = the main one), with a translation per language in $locales (language → status).
 *
 * @param  list<Taxonomy>  $categories
 * @param  array<string, string>  $locales
 */
function pubPost(array $categories, string $status = 'published', array $locales = ['vi' => 'published']): Entry
{
    $post = Entry::query()->create(['type' => 'post', 'status' => $status, 'published_at' => $status === 'published' ? now()->subMinute() : null]);
    foreach ($locales as $locale => $translationStatus) {
        $post->translations()->create(['locale' => $locale, 'title' => 'Bài '.Str::random(6), 'slug' => Str::lower(Str::random(10)), 'status' => $translationStatus, 'body' => ['blocks' => []]]);
    }
    $post->assignCategories(array_map(fn (Taxonomy $category): int => $category->id, $categories));

    return $post;
}

/**
 * A product filed under $categories, with a translation per language in $locales.
 *
 * @param  list<Taxonomy>  $categories
 * @param  list<string>  $locales
 */
function pubProduct(array $categories, string $status = 'published', array $locales = ['vi']): Product
{
    $product = Product::query()->create(['status' => $status]);
    foreach ($locales as $locale) {
        $product->translations()->create(['locale' => $locale, 'name' => 'Sản phẩm '.Str::random(6), 'slug' => Str::lower(Str::random(10))]);
    }
    $product->assignCategories(array_map(fn (Taxonomy $category): int => $category->id, $categories));

    return $product;
}

/**
 * The stored [own, tree] of these categories in one language, in order. Inside the site's context.
 *
 * @return list<array{0: int, 1: int}>
 */
function pubCounts(string $locale, Taxonomy ...$categories): array
{
    $rows = DB::table('taxonomy_translations')->where('locale', $locale)->whereIn('taxonomy_id', array_map(fn (Taxonomy $category): int => $category->id, $categories))
        ->get(['taxonomy_id', 'public_own_count', 'public_tree_count'])->keyBy('taxonomy_id');

    return array_map(fn (Taxonomy $category): array => [(int) $rows[$category->id]->public_own_count, (int) $rows[$category->id]->public_tree_count], $categories);
}

/**
 * Every stored row of a site that differs from a fresh count: "taxonomy|locale" → [stored, true].
 *
 * @return array<string, array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>
 */
function publicCountDrift(int $siteId): array
{
    return app(Tenancy::class)->enter($siteId, function () use ($siteId): array {
        $stored = [];
        foreach (DB::table('taxonomy_translations')->where('site_id', $siteId)->get(['taxonomy_id', 'locale', 'public_own_count', 'public_tree_count']) as $row) {
            $stored[$row->taxonomy_id.'|'.$row->locale] = [(int) $row->public_own_count, (int) $row->public_tree_count];
        }
        $ids = array_values(array_unique(array_map(fn (string $key): int => (int) explode('|', $key)[0], array_keys($stored))));
        $actual = app(CategoryPublicCounts::class)->actual($ids);

        $drift = [];
        foreach ($stored as $key => $count) {
            if (($actual[$key] ?? [0, 0]) !== $count) {
                $drift[$key] = [$count, $actual[$key] ?? [0, 0]];
            }
        }

        return $drift;
    });
}

it('counts a post in a language once it and its translation there are published, under its main category only', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $a = pubCategory('A');
        $b = pubCategory('B');
        $post = pubPost([$a, $b], 'draft', ['vi' => 'draft', 'en' => 'draft']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);   // a page never counts
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang', 'slug' => 'trang-'.$page->id, 'status' => 'published', 'body' => ['blocks' => []]]);
        $page->assignCategories([$a->id]);
        expect(pubCounts('vi', $a, $b))->toBe([[0, 0], [0, 0]]);

        // Published in Vietnamese only: English unchanged.
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $editor->fresh());
        expect(pubCounts('vi', $a, $b))->toBe([[1, 1], [0, 0]])
            ->and(pubCounts('en', $a))->toBe([[0, 0]]);

        // Its English translation published: English +1.
        $post->translationFor('en')->update(['status' => 'published']);
        expect(pubCounts('en', $a))->toBe([[1, 1]]);

        // Taken offline: out of both languages.
        app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Draft, $editor->fresh());
        expect(pubCounts('vi', $a))->toBe([[0, 0]])->and(pubCounts('en', $a))->toBe([[0, 0]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('collapses a publish of the entry and its translation into one update per row', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $a = pubCategory('A');
        $post = pubPost([$a], 'draft', ['vi' => 'draft']);
        $updates = [];
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            if (str_starts_with(strtolower($query->sql), 'update taxonomy_translations set public_own_count')) {
                $updates[] = $query->bindings;
            }
        });

        app(PublishEntry::class)->handle($post->fresh(), 'vi', $editor->fresh());

        expect($updates)->toHaveCount(1)->and(pubCounts('vi', $a))->toBe([[1, 1]]);
    });
});

it('counts a product filed under two categories of one branch once in their parent, once in each own total, per language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $top = pubCategory('Máy', 'product_cat');
        $left = pubCategory('Máy tiện', 'product_cat', $top);
        $right = pubCategory('Máy phay', 'product_cat', $top);
        $product = pubProduct([$left, $right], 'published', ['vi']);
        pubProduct([$left], 'draft');   // a draft never counts
        $product->assignCategories([$left->id, $right->id, $top->id]);

        expect(pubCounts('vi', $top, $left, $right))->toBe([[1, 1], [1, 1], [1, 1]])
            ->and(pubCounts('en', $top))->toBe([[0, 0]]);

        // An English translation: public in English too. Unpublished: out of both.
        $product->translations()->create(['locale' => 'en', 'name' => 'Lathe', 'slug' => 'lathe-'.$product->id]);
        expect(pubCounts('en', $top, $left))->toBe([[1, 1], [1, 1]]);
        $product->forceFill(['status' => 'draft'])->save();
        expect(pubCounts('vi', $top, $left, $right))->toBe([[0, 0], [0, 0], [0, 0]])
            ->and(pubCounts('en', $top))->toBe([[0, 0]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('counts every public product in the catalog root\'s branch total, filed there or not', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $root = pubCategory('Sản phẩm', 'product_cat');
        $child = pubCategory('Đồ gá', 'product_cat', $root);
        $elsewhere = pubCategory('Khác', 'product_cat');
        ContentSection::query()->create(['key' => 'products', 'label' => 'Sản phẩm', 'taxonomy_id' => $root->id]);

        pubProduct([]);
        pubProduct([$child]);
        pubProduct([$elsewhere, $child]);

        expect(pubCounts('vi', $root, $child, $elsewhere))->toBe([[0, 3], [2, 2], [1, 1]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('counts nothing at scheduling and one when the scheduled post goes live', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $a = pubCategory('A');
        $post = pubPost([$a], 'draft', ['vi' => 'draft']);

        app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Scheduled, $editor->fresh(), now()->addMinute());
        expect(pubCounts('vi', $a))->toBe([[0, 0]]);

        $this->travel(2)->minutes();
        expect(app(PublishScheduledEntry::class)->handle($post->fresh()))->toBeTrue()
            ->and(pubCounts('vi', $a))->toBe([[1, 1]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('follows a workflow transition onto the website and back', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($editor, 'editor', ['entries.approve', 'entries.review']);
        $category = pubCategory('Tin tức');
        ContentSection::query()->create(['key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id]);
        $post = pubPost([$category], 'draft', ['vi' => 'draft']);
        $post->forceFill(['author_id' => $author->id])->save();
        $workflow = Workflow::query()->create(['key' => 'editorial', 'name' => 'Biên tập', 'applies_to_type' => 'tin-tuc', 'template' => 'editorial', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Nháp', 'is_initial' => true], ['key' => 'pending', 'label' => 'Chờ'], ['key' => 'published', 'label' => 'Đăng', 'is_published' => true]] as $state) {
            $workflow->states()->create($state);
        }
        foreach ([
            ['key' => 'submit', 'label' => 'Gửi', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
            ['key' => 'approve', 'label' => 'Duyệt', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
            ['key' => 'retract', 'label' => 'Gỡ', 'from_state' => 'published', 'to_state' => 'draft', 'required_permission' => 'entries.review'],
        ] as $transition) {
            $workflow->transitions()->create($transition);
        }
        $action = app(ApplyEntryWorkflowTransition::class);

        $action->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
        expect(pubCounts('vi', $category))->toBe([[0, 0]]);
        $action->handle($post->fresh(), 'vi', $editor->fresh(), 'approve');
        expect(pubCounts('vi', $category))->toBe([[1, 1]]);
        $action->handle($post->fresh(), 'vi', $editor->fresh(), 'retract', 'Sai số liệu');
        expect(pubCounts('vi', $category))->toBe([[0, 0]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('takes a public post and product out in the trash, back on restore, and out for good on a permanent delete', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = pubCategory('A');
        $p = pubCategory('P', 'product_cat');
        $post = pubPost([$a]);
        $product = pubProduct([$p]);

        $post->delete();
        $product->delete();
        expect(pubCounts('vi', $a, $p))->toBe([[0, 0], [0, 0]]);

        $post->restore();
        $product->restore();
        expect(pubCounts('vi', $a, $p))->toBe([[1, 1], [1, 1]]);

        $post->forceDelete();
        $product->forceDelete();
        expect(pubCounts('vi', $a, $p))->toBe([[0, 0], [0, 0]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('moves a public post\'s count when its main category changes, and a bulk trash from the post list once per row', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = pubCategory('A');
        $b = pubCategory('B');
        $post = pubPost([$a]);
        $post->assignCategories([$b->id, $a->id]);
        expect(pubCounts('vi', $a, $b))->toBe([[0, 0], [1, 1]]);

        $ulids = [$post->ulid, pubPost([$b])->ulid, pubPost([$b])->ulid];
        $updates = 0;
        DB::listen(function (QueryExecuted $query) use (&$updates): void {
            $updates += (int) str_starts_with(strtolower($query->sql), 'update taxonomy_translations set public_own_count');
        });
        Livewire::actingAs($editor->fresh())->test(EntryIndex::class)->set('selected', $ulids)->call('bulkTrash');

        expect($updates)->toBe(1)->and(pubCounts('vi', $b))->toBe([[0, 0]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('moves the branch totals with a category moved under another parent, and follows the items a category delete re-files or lifts', function (): void {
    [$left, $right, $child] = app(Tenancy::class)->enter(1, function (): array {
        $left = pubCategory('Trái');
        $right = pubCategory('Phải');
        $child = pubCategory('Con', parent: $left);
        pubPost([$child]);
        pubPost([$child], 'published', ['vi' => 'published', 'en' => 'published']);
        expect(pubCounts('vi', $left, $right, $child))->toBe([[0, 2], [0, 0], [2, 2]]);

        return [$left, $right, $child];
    });

    // The ancestors are counted again once the move commits (a queued job; sync in tests).
    app(Tenancy::class)->enter(1, function () use ($child, $right, $left): void {
        app(MoveTaxonomy::class)->handle($child->fresh(), $right->fresh(), 0);
        expect(pubCounts('vi', $left))->toBe([[0, 2]]);   // not inside the move's transaction
    });
    app(Tenancy::class)->enter(1, function () use ($left, $right, $child): void {
        expect(pubCounts('vi', $left, $right, $child))->toBe([[0, 0], [0, 2], [2, 2]])
            ->and(pubCounts('en', $left, $right))->toBe([[0, 0], [0, 1]]);

        app(DeleteTaxonomy::class)->handle($child->fresh(), CategoryItemsOnDelete::Reassign, $left->fresh());
        expect(pubCounts('vi', $left, $right))->toBe([[2, 2], [0, 0]])
            ->and(pubCounts('en', $left))->toBe([[1, 1]]);

        // A category deleted with its items detached: its sub-category moves up, and its items
        // still count in the category above.
        $middle = pubCategory('Giữa', parent: $right);
        $leaf = pubCategory('Lá', parent: $middle);
        pubPost([$leaf]);
        app(DeleteTaxonomy::class)->handle($middle->fresh(), CategoryItemsOnDelete::Detach, children: CategoryChildrenOnDelete::Lift);
        expect(pubCounts('vi', $right, $leaf))->toBe([[0, 1], [1, 1]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('fills a category translation added later with the items already public in its language', function (): void {
    $category = app(Tenancy::class)->enter(1, function (): Taxonomy {
        $category = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'Tin']));
        pubPost([$category], 'published', ['vi' => 'published', 'en' => 'published']);

        $category->translations()->create(['locale' => 'en', 'name' => 'News', 'slug' => 'news-'.$category->id]);
        expect(pubCounts('en', $category))->toBe([[0, 0]]);   // counted once the save commits

        return $category;
    });
    app(Tenancy::class)->enter(1, fn () => expect(pubCounts('en', $category))->toBe([[1, 1]]));
    expect(publicCountDrift(1))->toBe([]);
});

it('follows a product published and unpublished through ChangeProductStatus, and a translation removed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = pubCategory('Máy', 'product_cat');
        $product = pubProduct([$category], 'draft', ['vi', 'en']);
        $post = pubPost([pubCategory('Tin')], 'published', ['vi' => 'published', 'en' => 'published']);

        app(ChangeProductStatus::class)->handle($product->fresh(), 'published');
        expect(pubCounts('vi', $category))->toBe([[1, 1]])->and(pubCounts('en', $category))->toBe([[1, 1]]);

        $product->translationFor('en')->delete();
        $post->translationFor('en')->delete();
        expect(pubCounts('en', $category))->toBe([[0, 0]])->and(pubCounts('vi', $category))->toBe([[1, 1]]);

        app(ChangeProductStatus::class)->handle($product->fresh(), 'draft');
        expect(pubCounts('vi', $category))->toBe([[0, 0]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('puts a bulk restore from the trash back with one update per row, whatever the number of posts', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.delete.any']);
        $a = pubCategory('A');
        $statements = function (int $n) use ($editor, $a): int {
            $posts = array_map(fn (): Entry => pubPost([$a]), range(1, $n));
            foreach ($posts as $post) {
                $post->delete();
            }
            $count = 0;
            DB::listen(function (QueryExecuted $query) use (&$count): void {
                $count += (int) (str_contains($query->sql, 'taxonomy_translations') || str_contains(strtolower($query->sql), 'with filed as'));
            });
            Livewire::actingAs($editor->fresh())->test(EntryIndex::class)
                ->set('tab', 'trash')->set('selected', array_map(fn (Entry $post): string => $post->ulid, $posts))->call('bulkRestore');

            return $count;
        };

        expect($statements(2))->toBe($statements(5))
            ->and(pubCounts('vi', $a))->toBe([[7, 7]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('leaves the totals as they were after a bulk restore of products back as drafts, and after its undo', function (): void {
    $manager = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($manager, 'cat-manager', ['catalog.view', 'catalog.manage']));

    [$category, $kept] = app(Tenancy::class)->enter(1, function (): array {
        $category = pubCategory('Máy', 'product_cat');
        $kept = pubProduct([$category], 'published', ['vi', 'en']);
        foreach ([pubProduct([$category], 'published', ['vi', 'en']), pubProduct([$category], 'published', ['vi'])] as $gone) {
            $gone->delete();
        }
        expect(pubCounts('vi', $category))->toBe([[1, 1]])->and(pubCounts('en', $category))->toBe([[1, 1]]);

        return [$category, $kept];
    });

    app(Tenancy::class)->enter(1, function () use ($manager, $category, $kept): void {
        $trashed = Product::onlyTrashed()->orderBy('id')->pluck('ulid')->all();
        // «Khôi phục (N)»: out of the trash as drafts, so still off the website; the undo puts them
        // back as they were (published) and into the trash again — off the website either way.
        $test = Livewire::actingAs($manager->fresh())->test(ProductIndex::class)
            ->set('tab', 'trash')->call('toggleSelect', $trashed)->call('bulkRestore');
        expect(pubCounts('vi', $category))->toBe([[1, 1]])->and(pubCounts('en', $category))->toBe([[1, 1]]);
        $test->call('undoLast');
        expect(pubCounts('vi', $category))->toBe([[1, 1]])->and(pubCounts('en', $category))->toBe([[1, 1]])
            ->and(Product::onlyTrashed()->count())->toBe(2)->and($kept->fresh()?->trashed())->toBeFalse();
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('moves the totals of live products given another main category in bulk, with one reading for them all', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $from = pubCategory('Cũ', 'product_cat');
        $to = pubCategory('Mới', 'product_cat');
        $ids = array_map(fn (): int => pubProduct([$from], 'published', ['vi', 'en'])->id, range(1, 3));
        pubProduct([$from], 'draft');

        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads): void {
            $reads += (int) str_contains(strtolower($query->sql), 'with filed as');
        });
        $products = Product::query()->with(['taxonomies', 'translations'])->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        expect(app(ChangeProductMainCategory::class)->handle($products, $to)['moved'])->toBe(3)
            ->and($reads)->toBe(2)
            ->and(pubCounts('vi', $from, $to))->toBe([[0, 0], [3, 3]])
            ->and(pubCounts('en', $from, $to))->toBe([[0, 0], [3, 3]]);
    });
    expect(publicCountDrift(1))->toBe([]);
});

it('counts two publishes at once in the same category, neither lost', function (): void {
    $editor = User::factory()->create();
    config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);

    [$a, $first, $second] = app(Tenancy::class)->enter(1, function () use ($editor): array {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);
        $a = pubCategory('A');

        return [$a, pubPost([$a], 'draft', ['vi' => 'draft']), pubPost([$a], 'draft', ['vi' => 'draft'])];
    });

    app(Tenancy::class)->enter(1, function () use ($editor, $first, $second): void {
        // Once the first publish has read the post's share, before it writes the totals, a second
        // publish on another connection runs to its commit.
        $raced = false;
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$raced, &$writes, $editor, $second): void {
            if (str_starts_with(strtolower($query->sql), 'update taxonomy_translations')) {
                $writes[] = $query->sql;
            }
            if ($raced || $query->connectionName !== 'pgsql' || ! str_starts_with(trim(strtolower($query->sql)), 'with filed as')) {
                return;
            }
            $raced = true;
            $default = DB::getDefaultConnection();
            DB::setDefaultConnection('pgsql_race');
            try {
                app(Tenancy::class)->enter(1, function () use ($editor, $second): void {
                    // A wait on the first publish fails here instead of hanging the run.
                    DB::statement("SET LOCAL lock_timeout = '5s'");
                    // Read on this connection: a model keeps the connection it was read on.
                    app(PublishEntry::class)->handle(Entry::on('pgsql_race')->findOrFail($second->id), 'vi', User::on('pgsql_race')->findOrFail($editor->id));
                });
            } finally {
                DB::setDefaultConnection($default);
                app(CurrentSite::class)->setId(1);
            }
        });

        app(PublishEntry::class)->handle($first->fresh(), 'vi', $editor->fresh());
        // Both writes add to the stored number in SQL, whatever it holds by then: never a number
        // read earlier and written back.
        expect($raced)->toBeTrue()
            ->and($writes)->toHaveCount(2)
            ->each->toContain('public_own_count = GREATEST(public_own_count + ?, 0), public_tree_count = GREATEST(public_tree_count + ?, 0)');
    });
    DB::purge('pgsql_race');

    app(Tenancy::class)->enter(1, fn () => expect(pubCounts('vi', $a))->toBe([[2, 2]]));
    expect(publicCountDrift(1))->toBe([]);
});

it('never touches another site\'s rows', function (): void {
    [$one, $two] = [
        app(Tenancy::class)->enter(1, fn (): Taxonomy => pubCategory('Tin')),
        app(Tenancy::class)->enter(2, fn (): Taxonomy => pubCategory('Tin')),
    ];
    app(Tenancy::class)->enter(2, fn () => pubPost([$two]));

    app(Tenancy::class)->enter(1, function () use ($two): void {
        // Site 2's rows are not there for site 1 to change, by id or otherwise.
        app(CategoryPublicCounts::class)->settle([], [$two->id.'|vi' => [5, 5]]);
        expect(app(CategoryPublicCounts::class)->recount([$two->id]))->toBe([]);
    });

    app(Tenancy::class)->enter(1, fn () => expect(pubCounts('vi', $one))->toBe([[0, 0]]));
    app(Tenancy::class)->enter(2, fn () => expect(pubCounts('vi', $two))->toBe([[1, 1]]));
    expect(publicCountDrift(1))->toBe([])->and(publicCountDrift(2))->toBe([]);
});

it('corrects a drifted total, logs it per row, and finds nothing on a second run', function (): void {
    $a = app(Tenancy::class)->enter(1, function (): Taxonomy {
        $top = pubCategory('A');
        pubPost([pubCategory('A1', parent: $top)]);

        return $top;
    });
    // After the new categories' own queued counts have run.
    app(Tenancy::class)->enter(1, fn () => DB::table('taxonomy_translations')->where('taxonomy_id', $a->id)->where('locale', 'vi')->update(['public_tree_count' => 9]));
    Log::spy();

    $this->artisan('cms:reconcile-category-public-counts', ['--chunk' => 1])->expectsOutputToContain('corrected 1 row(s)')->assertSuccessful();

    app(Tenancy::class)->enter(1, fn () => expect(pubCounts('vi', $a))->toBe([[0, 1]]));
    Log::shouldHaveReceived('warning')->with('Category public count drifted', ['site_id' => 1, 'taxonomy_id' => $a->id, 'locale' => 'vi', 'own' => [0, 0], 'tree' => [9, 1]])->once();

    $this->artisan('cms:reconcile-category-public-counts')->expectsOutputToContain('corrected 0 row(s)')->assertSuccessful();
});

it('runs the reconcile every night, never two at once', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'cms:reconcile-category-public-counts'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 20 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('never shows the admin total on the storefront', function (): void {
    $hits = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Modules/Storefront'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'items_count')) {
            $hits[] = $file->getPathname();
        }
    }

    expect($hits)->toBe([]);
});

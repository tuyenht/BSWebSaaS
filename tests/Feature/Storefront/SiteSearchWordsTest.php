<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductSiteWords;
use App\Modules\Cms\Actions\DuplicateEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntrySearchWords;
use App\Modules\Cms\Support\TaxonomySearchWords;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Storefront\Console\RebuildSiteSearchWords;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The word tables of the storefront search: which words a post, page, product or category has and
 * with which weight, that every save keeps them in the same transaction, that a website sees only its
 * own words (RLS), the cascade, and the command that checks and corrects them.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A post of the current site in Vietnamese. */
function siteWordsPost(string $title, ?string $excerpt = null, string $body = ''): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => now(), 'meta' => []]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => 'bai-'.$entry->id, 'status' => 'published', 'excerpt' => $excerpt,
        'body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => '', 'paragraphs' => [$body]]]]],
    ]);

    return $entry;
}

it('folds the words and weighs a word of the title 1 and one only in the short description 3', function (): void {
    expect(EntrySearchWords::of('Khuôn Mẫu', null))->toBe(['khuon' => 1, 'mau' => 1])
        ->and(EntrySearchWords::of('Khuôn mẫu ép', 'Khuôn ÉP nhựa — chính xác'))
        ->toBe(['chinh' => 3, 'ep' => 1, 'khuon' => 1, 'mau' => 1, 'nhua' => 3, 'xac' => 3])
        ->and(ProductSiteWords::of('AB-27', 'Vòng bi', '<p>Chịu <strong>nhiệt</strong>&nbsp;cao</p><p>bi</p>'))
        ->toBe(['27' => 1, 'ab' => 1, 'ab27' => 1, 'b27' => 1, 'bi' => 1, 'cao' => 3, 'chiu' => 3, 'nhiet' => 3, 'vong' => 1])
        ->and(TaxonomySearchWords::of('Máy CNC 5 trục'))->toBe(['5' => 1, 'cnc' => 1, 'may' => 1, 'truc' => 1]);
});

it('keeps the words of a post with every save of its title or excerpt, never the body, and per language', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $post = siteWordsPost('Khuôn Mẫu', 'Ép nhựa', 'thongsokythuat');
        expect(siteWordsOf('entry_search_words', 1, $post->id))->toBe(['vi' => ['ep' => 3, 'khuon' => 1, 'mau' => 1, 'nhua' => 3]]);

        $vi = $post->translations()->where('locale', 'vi')->firstOrFail();
        $vi->update(['title' => 'Khuôn ép', 'excerpt' => null]);
        $english = $post->translations()->create(['locale' => 'en', 'title' => 'Moulds', 'slug' => 'moulds', 'excerpt' => 'Injection']);
        expect(siteWordsOf('entry_search_words', 1, $post->id))->toBe([
            'en' => ['injection' => 3, 'moulds' => 1],
            'vi' => ['ep' => 1, 'khuon' => 1],
        ]);

        $english->delete();
        expect(siteWordsOf('entry_search_words', 1, $post->id))->toBe(['vi' => ['ep' => 1, 'khuon' => 1]]);
        expect(DB::table('entry_search_words')->where('word', 'thongsokythuat')->exists())->toBeFalse();

        // the Trash keeps the words (the search decides what is public); a delete for good drops them
        $post->delete();
        expect(siteWordsOf('entry_search_words', 1, $post->id))->not->toBe([]);
        $post->forceDelete();
        expect(DB::table('entry_search_words')->where('entry_id', $post->id)->count())->toBe(0);
    });
});

it('writes no words for a save that leaves the title and excerpt as they were', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $translation = siteWordsPost('Bản tin')->translations()->firstOrFail();

        $statements = countQueries(fn () => $translation->update(['seo_title' => 'Bản tin mới nhất']));
        $retitle = countQueries(fn () => $translation->update(['title' => 'Bản tin hay']));

        // the words of the values just saved: delete, insert
        expect($retitle - $statements)->toBe(2);
    });
});

it('gives a duplicated post the words of its copy', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Dịch vụ', 'slug' => 'dich-vu', 'status' => 'published', 'body' => ['blocks' => []]]);

        $copy = app(DuplicateEntry::class)->handle($page->fresh(), $editor->fresh());

        $words = siteWordsOf('entry_search_words', 1, $copy->id)['vi'] ?? [];
        expect($words)->toHaveKeys(['dich', 'vu'])
            ->and($copy->translationFor('vi')?->title)->not->toBe('Dịch vụ');
    });
});

it('keeps the words of a product with its name, SKU and summary, and drops them with the product', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $product = Product::query()->create(['status' => 'published', 'sku' => 'VB-1']);
        $vi = $product->translations()->create(['locale' => 'vi', 'name' => 'Vòng bi', 'slug' => 'vong-bi', 'summary' => '<p>Bi thép</p>', 'description' => '<p>chitietdai</p>']);
        expect(siteWordsOf('product_site_words', 1, $product->id))->toBe(['vi' => ['1' => 1, 'b1' => 1, 'bi' => 1, 'thep' => 3, 'vb' => 1, 'vb1' => 1, 'vong' => 1]]);

        $vi->update(['summary' => '<p>Chịu nhiệt</p>']);
        $product->update(['sku' => null]);
        $vi->update(['name' => 'Ổ bi']);
        expect(siteWordsOf('product_site_words', 1, $product->id))->toBe(['vi' => ['bi' => 1, 'chiu' => 3, 'nhiet' => 3, 'o' => 1]]);

        $vi->delete();
        expect(siteWordsOf('product_site_words', 1, $product->id))->toBe([]);

        $product->translations()->create(['locale' => 'vi', 'name' => 'Ổ bi', 'slug' => 'o-bi']);
        $product->forceDelete();
        expect(DB::table('product_site_words')->where('product_id', $product->id)->count())->toBe(0)
            ->and(DB::table('product_search_words')->where('product_id', $product->id)->count())->toBe(0);
    });
});

it('keeps the words of a category with its name, and a rename rewrites only the category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $vi = $category->translations()->create(['locale' => 'vi', 'name' => 'Khuôn Mẫu', 'slug' => 'khuon-mau']);
        $post = siteWordsPost('Bài khuôn');
        $post->assignCategories([$category->id]);
        $postWords = siteWordsOf('entry_search_words', 1, $post->id);
        expect(siteWordsOf('taxonomy_search_words', 1, $category->id))->toBe(['vi' => ['khuon' => 1, 'mau' => 1]]);

        $vi->update(['name' => 'Đồ gá']);
        expect(siteWordsOf('taxonomy_search_words', 1, $category->id))->toBe(['vi' => ['do' => 1, 'ga' => 1]])
            ->and(siteWordsOf('entry_search_words', 1, $post->id))->toBe($postWords);

        $vi->delete();
        expect(siteWordsOf('taxonomy_search_words', 1, $category->id))->toBe([]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);
        $category->delete();
        expect(DB::table('taxonomy_search_words')->where('taxonomy_id', $category->id)->count())->toBe(0);
    });
});

it('lets a website see and write only its own words, and nothing without a website', function (string $table): void {
    $theirs = app(Tenancy::class)->enter(2, function (): array {
        $post = siteWordsPost('Bên kia');
        $product = Product::query()->create(['status' => 'published']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Bên kia', 'slug' => 'ben-kia']);
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Bên kia', 'slug' => 'ben-kia']);

        return ['entry_search_words' => ['entry_id', $post->id], 'product_site_words' => ['product_id', $product->id], 'taxonomy_search_words' => ['taxonomy_id', $category->id]];
    });
    expect(DB::connection('pgsql_admin')->table($table)->where('site_id', 2)->count())->toBeGreaterThan(0);

    app(Tenancy::class)->enter(1, function () use ($table, $theirs): void {
        siteWordsPost('Bên này');
        expect(DB::table($table)->where('site_id', 2)->count())->toBe(0)
            ->and(DB::table($table)->where('word', 'kia')->count())->toBe(0);

        [$column, $id] = $theirs[$table];
        $row = ['site_id' => 2, 'locale' => 'vi', 'word' => 'lot', $column => $id] + ($table === 'taxonomy_search_words' ? [] : ['weight' => 1]);
        expect(fn () => DB::transaction(fn () => DB::table($table)->insert($row)))
            ->toThrow(QueryException::class, 'row-level security');
    });

    // the app's own connection outside any website: the policy fails closed
    DB::transaction(function () use ($table): void {
        expect(DB::table($table)->count())->toBe(0);
    });
})->with(['entry_search_words', 'product_site_words', 'taxonomy_search_words']);

it('refuses a weight other than 1 or 3 and an empty word', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $post = siteWordsPost('Bài');
        foreach ([['word' => 'x', 'weight' => 2], ['word' => '', 'weight' => 1]] as $bad) {
            expect(fn () => DB::transaction(fn () => DB::table('entry_search_words')->insert(['site_id' => 1, 'locale' => 'vi', 'entry_id' => $post->id] + $bad)))
                ->toThrow(QueryException::class, 'check constraint');
        }
    });
});

it('corrects words that drifted, site by site, and a second run corrects nothing', function (): void {
    [$post, $product, $category] = app(Tenancy::class)->enter(1, function (): array {
        $category = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá', 'slug' => 'do-ga']);
        $product = Product::query()->create(['status' => 'draft', 'sku' => 'DG-1']);
        $product->translations()->create(['locale' => 'vi', 'name' => 'Đồ gá hàn', 'slug' => 'do-ga-han', 'summary' => 'Thép']);

        return [siteWordsPost('Tin mới', 'Tóm tắt'), $product, $category];
    });
    app(Tenancy::class)->enter(2, fn (): Entry => siteWordsPost('Bên kia'));
    // read in the site's context: without one the word tables show nothing (RLS)
    $wordsNow = fn (): array => app(Tenancy::class)->enter(1, fn (): array => [
        'entry_search_words' => siteWordsOf('entry_search_words', 1, $post->id),
        'product_site_words' => siteWordsOf('product_site_words', 1, $product->id),
        'taxonomy_search_words' => siteWordsOf('taxonomy_search_words', 1, $category->id),
    ]);
    $right = $wordsNow();
    expect($right['entry_search_words'])->toBe(['vi' => ['moi' => 1, 'tat' => 3, 'tin' => 1, 'tom' => 3]])
        ->and($right['taxonomy_search_words'])->toBe(['vi' => ['do' => 1, 'ga' => 1]]);

    // a write that skipped the models: words lost, a stale word, a wrong weight
    $admin = DB::connection('pgsql_admin');
    $admin->table('entry_search_words')->where('entry_id', $post->id)->delete();
    $admin->table('product_site_words')->insert(['site_id' => 1, 'locale' => 'vi', 'word' => 'cu', 'product_id' => $product->id, 'weight' => 1]);
    $admin->table('product_site_words')->where('product_id', $product->id)->where('word', 'thep')->update(['weight' => 1]);
    $admin->table('taxonomy_search_words')->where('taxonomy_id', $category->id)->delete();
    Log::spy();

    $this->artisan('search:words', ['--chunk' => 1])->expectsOutputToContain('on 2 site(s); corrected 3.')->assertSuccessful();
    expect($wordsNow())->toBe($right);
    Log::shouldHaveReceived('warning')->times(3);

    $this->artisan('search:words')->expectsOutputToContain('corrected 0.')->assertSuccessful();
    $this->artisan('search:words', ['--site' => 2])->expectsOutputToContain('Checked 1 item(s) on 1 site(s); corrected 0.')->assertSuccessful();
});

it('checks a rolling window of each kind per site and starts again past the last item', function (): void {
    $posts = app(Tenancy::class)->enter(1, fn (): array => [siteWordsPost('Một'), siteWordsPost('Hai'), siteWordsPost('Ba')]);
    $key = RebuildSiteSearchWords::cursorKey(1, 'entry_search_words');
    Cache::forget($key);

    $this->artisan('search:words', ['--site' => 1, '--rolling' => 2])->expectsOutputToContain('Checked 2 item(s)')->assertSuccessful();
    expect(Cache::get($key))->toBe($posts[1]->id);
    $this->artisan('search:words', ['--site' => 1, '--rolling' => 2])->expectsOutputToContain('Checked 1 item(s)')->assertSuccessful();
    expect(Cache::get($key))->toBe(0);
});

it('writes each wrong item in a transaction of its own: one that fails is logged and skipped, the rest and the cursor go on', function (): void {
    $posts = app(Tenancy::class)->enter(1, fn (): array => [siteWordsPost('Một'), siteWordsPost('Hỏng'), siteWordsPost('Ba')]);
    $key = RebuildSiteSearchWords::cursorKey(1, 'entry_search_words');
    Cache::forget($key);
    $admin = DB::connection('pgsql_admin');
    $admin->table('entry_search_words')->whereIn('entry_id', array_map(fn (Entry $post): int => $post->id, $posts))->delete();
    // A write of the middle post's words fails, as a real error would (a lock timeout, a bad row).
    $admin->unprepared(<<<'SQL'
        create or replace function test_site_words_fail() returns trigger language plpgsql as $fn$
        begin
            raise exception 'the words of this item cannot be written';
        end
        $fn$;
        drop trigger if exists test_site_words_fail on entry_search_words;
        create trigger test_site_words_fail before insert on entry_search_words
            for each row when (new.word = 'hong') execute function test_site_words_fail();
        SQL);
    Log::spy();

    try {
        $this->artisan('search:words', ['--site' => 1, '--rolling' => 3])
            ->expectsOutputToContain('corrected 2.')
            ->expectsOutputToContain('Failed on 0 site check(s) and 1 item(s)')
            ->assertFailed();
    } finally {
        $admin->unprepared('drop trigger if exists test_site_words_fail on entry_search_words; drop function if exists test_site_words_fail();');
    }

    $words = fn (Entry $post): array => app(Tenancy::class)->enter(1, fn (): array => siteWordsOf('entry_search_words', 1, $post->id));
    expect($words($posts[0]))->toBe(['vi' => ['mot' => 1]])
        ->and($words($posts[1]))->toBe([])
        ->and($words($posts[2]))->toBe(['vi' => ['ba' => 1]])
        ->and(Cache::get($key))->toBe($posts[2]->id);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => ($context['item_id'] ?? null) === $posts[1]->id);

    // Once the fault is gone the next full run writes it.
    $this->artisan('search:words', ['--site' => 1])->expectsOutputToContain('corrected 1.')->assertSuccessful();
    expect($words($posts[1]))->toBe(['vi' => ['hong' => 1]]);
})->skip(getenv('CI') !== 'true', 'adds a trigger to fail one write: runs on CI\'s own database only');
